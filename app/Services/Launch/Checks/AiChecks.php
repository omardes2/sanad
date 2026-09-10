<?php

declare(strict_types=1);

namespace App\Services\Launch\Checks;

use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;
use App\Enums\AiOperation;
use App\Enums\HealthCheckStatus;
use App\Models\ProviderHealthCheck;
use App\Services\Ai\SanadAiRouter;
use App\Services\Credentials\CredentialResolver;
use App\Services\Settings\SettingsRepository;
use App\Support\Credentials\CredentialVault;
use App\Support\Tools\ToolCatalog;
use App\Support\Tools\ToolRegistry;
use Throwable;

/**
 * Readiness of the AI provider layer and of provider tool calling.
 *
 * PRIVACY: not one of these reads a secret value. Credential health is taken
 * from `ResolvedCredential::usable()` — a boolean — and the vault reports
 * `available()` and a key *id*. No API key, no fingerprint, no `last4` reaches
 * this page; the fingerprint and last4 exist on the DTO and are deliberately
 * NOT rendered, because both narrow a guess against a stolen backup.
 */
final class AiChecks
{
    public static function provider(): GateOutcome
    {
        $settings = app(SettingsRepository::class);
        $enabled = (bool) $settings->get('ai.enabled');

        $details = [
            GateDetail::boolean('الذكاء الاصطناعي مفعَّل', $enabled, 'مفعَّل', 'معطّل'),
        ];

        if (! $enabled) {
            $details[] = GateDetail::plain('الأثر', 'يعمل المساعد الحتمي البديل بلا أي اتصال خارجي، وبلا قياس حصص');

            return GateOutcome::notReady('الذكاء الاصطناعي معطّل (AI_ENABLED في البيئة يتقدّم على قيمة قاعدة البيانات).', $details);
        }

        $resolver = app(CredentialResolver::class);
        $vault = app(CredentialVault::class);

        $details[] = GateDetail::plain('مصدر المفاتيح', $resolver->mode());
        $details[] = GateDetail::boolean('الخزنة متاحة', $vault->available(), 'متاحة', 'غير متاحة');

        $keyId = $vault->available() ? $vault->keyId() : null;

        if ($keyId !== null) {
            // A key ID, never a key.
            $details[] = GateDetail::plain('معرّف مفتاح الخزنة', $keyId);
        }

        [$provider, $model, $routeError] = self::route();

        if ($provider === null) {
            $details[] = GateDetail::bad('التوجيه', $routeError ?? 'لا يوجد مزوّد مؤهَّل لعملية المحادثة');

            return GateOutcome::notReady('لا يوجد مسار مزوّد صالح لعملية المحادثة.', $details);
        }

        $details[] = GateDetail::ok('المزوّد المختار', $provider);
        $details[] = GateDetail::plain('النموذج', $model ?? '—');

        $credential = null;

        try {
            $credential = app(CredentialResolver::class)->resolve($provider);
        } catch (Throwable) {
            // Resolution itself failed; reported as "could not resolve" below.
        }

        if ($credential === null) {
            $details[] = GateDetail::unknown('المفتاح', 'تعذّر الحسم');

            return GateOutcome::notObserved('تعذّر التحقّق من مفتاح المزوّد المختار.', $details);
        }

        $details[] = GateDetail::boolean('مفتاح المزوّد المختار', $credential->usable(), 'صالح للاستخدام', 'غير صالح');

        if ($credential->failedClosed()) {
            $details[] = GateDetail::bad('المزوّد مغلَق', (string) $credential->failure);
        }

        if (! $credential->usable()) {
            return GateOutcome::notReady('المزوّد المختار بلا مفتاح صالح.', $details);
        }

        $details[] = self::latestHealth($provider);

        return GateOutcome::ready('مزوّد مفعَّل، مسار صالح، ومفتاح قابل للاستخدام.', $details);
    }

    /**
     * Provider tool calling: the model may PROPOSE, and the server decides. The
     * gate asks only whether the mechanism is wired and at least one tool is
     * exposable — never whether a particular tool "works".
     */
    public static function toolCalling(): GateOutcome
    {
        $catalog = app(ToolCatalog::class);
        $all = app(ToolRegistry::class)->all();
        $executable = $catalog->executable();
        $exposableKeys = array_map(static fn ($d): string => $d->key->value(), $executable);

        $details = [
            GateDetail::plain('الأدوات المعرَّفة', (string) count($all)),
            GateDetail::plain('المعروضة للمزوّد', (string) count($executable)),
            GateDetail::plain('ميزانية الدور', '٣ نداءات مزوّد · جولتا أدوات · ٣ استدعاءات لكل رسالة'),
        ];

        // Say WHY each hidden tool is hidden. "Hidden" is a server decision with
        // a reason, not an accident, and an operator should not have to guess.
        foreach ($all as $definition) {
            if (in_array($definition->key->value(), $exposableKeys, true)) {
                continue;
            }

            $details[] = GateDetail::plain(
                'غير معروضة: '.$definition->key->value(),
                $definition->needsApproval()
                    ? 'تحتاج موافقة ولا توجد آلية موافقة بعد'
                    : 'لا يوجد منفِّذ لهذا الإصدار، أو أثرها الجانبي لا يُعرَض إطلاقًا',
            );
        }

        if ($executable === []) {
            return GateOutcome::notReady('لا توجد أداة واحدة قابلة للعرض على المزوّد.', $details);
        }

        return GateOutcome::ready('آلية استدعاء الأدوات مضبوطة وهناك أدوات قابلة للعرض.', $details);
    }

    /**
     * Rate limiting / abuse protection.
     *
     * `ToolDefinition::rateLimitPerHour` is DECLARED METADATA AND NOTHING ELSE:
     * no code enforces it. Reporting it as a live protection would be exactly
     * the misrepresentation the launch scope forbids, so this gate reads
     * `not_implemented` until an enforcement path actually exists, and a test
     * fails the moment one appears while this still says otherwise.
     */
    public static function rateLimiting(): GateOutcome
    {
        return GateOutcome::notImplemented(
            'لا يوجد فرض لحدود المعدّل: `rateLimitPerHour` بيانات وصفية معلَنة وغير مفروضة.',
            [
                GateDetail::bad('الفرض', 'غير موجود في app/'),
                GateDetail::plain('الموجود', 'قيمة معلَنة لكل أداة في ToolDefinition، تُقرأ ولا تُطبَّق'),
                GateDetail::plain('المطلوب', 'عدّاد لكل مشترك/أداة + رفض محدود عند التجاوز + قياس'),
            ],
        );
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} provider, model, error
     */
    private static function route(): array
    {
        try {
            $evaluation = app(SanadAiRouter::class)->evaluate(AiOperation::Chat);
            $selected = $evaluation->selected;

            if ($selected === null) {
                return [null, null, 'لا يوجد مرشّح مؤهَّل في الكتالوج'];
            }

            return [$selected->provider, $selected->model, null];
        } catch (Throwable $e) {
            return [null, null, 'تعذّر تقييم التوجيه: '.class_basename($e)];
        }
    }

    private static function latestHealth(string $provider): GateDetail
    {
        try {
            $latest = ProviderHealthCheck::query()
                ->whereHas('provider', static fn ($q) => $q->where('key', $provider))
                ->latest('checked_at')
                ->first();
        } catch (Throwable) {
            return GateDetail::unknown('آخر فحص صحة', 'تعذّرت القراءة');
        }

        if ($latest === null) {
            return GateDetail::unknown('آخر فحص صحة', 'لا يوجد فحص مسجَّل');
        }

        $status = $latest->status;
        $when = $latest->checked_at?->format('Y-m-d H:i') ?? '—';

        return $status === HealthCheckStatus::Ok
            ? GateDetail::ok('آخر فحص صحة', 'سليم — '.$when)
            : GateDetail::unknown('آخر فحص صحة', ($status?->value ?? 'غير معروف').' — '.$when);
    }
}

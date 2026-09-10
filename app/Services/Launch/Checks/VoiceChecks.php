<?php

declare(strict_types=1);

namespace App\Services\Launch\Checks;

use App\Contracts\Ai\SupportsTranscription;
use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;
use App\Enums\AiOperation;
use App\Services\Ai\AiManager;
use App\Services\Ai\SanadAiRouter;
use App\Services\Credentials\CredentialResolver;
use App\Support\WhatsApp\WhatsAppConfig;
use Throwable;

/**
 * Readiness of the voice-note path: a subscriber speaks, Sanad understands.
 *
 * WHAT THIS GATE ASKS. Not "is Groq configured" — that would make one vendor
 * the definition of a product capability, and the next deployment that routes
 * transcription to OpenAI would read as unready while working perfectly. It
 * asks the vendor-neutral question instead:
 *
 *   is the feature on, can WhatsApp media be fetched, and does a ROUTABLE
 *   `SupportsTranscription` provider/model with a USABLE CREDENTIAL exist?
 *
 * The row names whichever provider and model the catalog actually resolved, and
 * counts the other eligible ones, so an operator can see both what is serving
 * voice today and that it is not the only thing that could.
 *
 * WHAT IT DOES NOT ASK is whether any voice note has succeeded. That is
 * OBSERVATION, and this codebase has one rule about observation on this screen:
 * absence of evidence is not evidence of failure (ADR-0045). A deployment that
 * has simply had no voice notes yet is not unready, and a gate that said
 * otherwise would block a launch on traffic that has not happened.
 *
 * PRIVACY: the provider key and the routed model id are operator configuration
 * and safe to render. Credential health is taken from `usable()` — a boolean —
 * exactly as AiChecks takes it. No key, no fingerprint, no last4, no key id, no
 * subscriber fact and no signed media URL is read here. And no provider name is
 * ever GUESSED: what is printed is what the catalog resolved, or nothing.
 */
final class VoiceChecks
{
    public static function transcription(): GateOutcome
    {
        $enabled = (bool) config('voice.enabled', true);

        $details = [
            GateDetail::boolean('التفريغ مفعَّل', $enabled, 'مفعَّل', 'معطّل'),
        ];

        if (! $enabled) {
            $details[] = GateDetail::plain(
                'الأثر',
                'الرسالة الصوتية تُستقبَل وتُخزَّن ويُردّ عليها بسبب محدَّد — ولا تُهمَل بصمت',
            );

            return GateOutcome::notReady('تفريغ الرسائل الصوتية معطّل في هذه البيئة.', $details);
        }

        // The media fetch uses the SAME access token as sending, so a WhatsApp
        // integration that cannot send cannot download a voice note either.
        $whatsapp = app(WhatsAppConfig::class);
        $details[] = GateDetail::boolean('تنزيل وسائط واتساب', $whatsapp->canSend(), 'ممكن', 'غير مضبوط');

        if (! $whatsapp->canSend()) {
            return GateOutcome::notReady('تعذّر تنزيل الوسائط: تكامل واتساب غير مكتمل.', $details);
        }

        [$selected, $eligible, $error] = self::route();

        // How many DIFFERENT providers could serve transcription right now. The
        // count is the point: it says this capability is not one vendor's.
        $providers = array_values(array_unique(array_map(
            static fn (ModelSpec $spec): string => $spec->provider,
            $eligible,
        )));

        $details[] = GateDetail::plain(
            'مزوّدو تفريغ مؤهَّلون',
            $providers === [] ? 'لا يوجد' : count($providers).' ('.implode(' · ', $providers).')',
        );

        if ($selected === null) {
            $details[] = GateDetail::bad('التوجيه', $error ?? 'لا يوجد نموذج تفريغ مؤهَّل في الفهرس');
            $details[] = GateDetail::plain(
                'المطلوب',
                'نموذج بقدرة transcription في فهرس النماذج (قاعدة البيانات أو config) لمزوّد ينفّذ SupportsTranscription ومفتاحه صالح',
            );

            return GateOutcome::notReady('لا يوجد مسار تفريغ صالح.', $details);
        }

        $details[] = GateDetail::ok('المزوّد المختار', $selected->provider);
        $details[] = GateDetail::plain('النموذج', $selected->model);

        // The credential, as a boolean — never a key, a fingerprint or a last4.
        $credential = null;

        try {
            $credential = app(CredentialResolver::class)->resolve($selected->provider);
        } catch (Throwable) {
            // Reported as "could not be established" below, never as ready.
        }

        if ($credential === null) {
            $details[] = GateDetail::unknown('المفتاح', 'تعذّر الحسم');

            return GateOutcome::notObserved('تعذّر التحقّق من مفتاح مزوّد التفريغ المختار.', $details);
        }

        $details[] = GateDetail::boolean('مفتاح المزوّد المختار', $credential->usable(), 'صالح للاستخدام', 'غير صالح');

        if ($credential->failedClosed()) {
            $details[] = GateDetail::bad('المزوّد مغلَق', (string) $credential->failure);
        }

        if (! $credential->usable()) {
            return GateOutcome::notReady('مزوّد التفريغ المختار بلا مفتاح صالح.', $details);
        }

        $details[] = GateDetail::plain(
            'الحدود',
            sprintf(
                'حجم أقصى %d ميغابايت · مدّة قصوى %d ثانية · محاولتان فعليتان كحدّ أقصى',
                (int) round(((int) config('voice.max_bytes', 0)) / 1048576),
                (int) config('voice.max_duration_seconds', 0),
            ),
        );
        $details[] = GateDetail::plain(
            'الضمان',
            'مرّة واحدة على الأقل بحدّ أعلى — ليس exactly-once: نتيجة غير معروفة لا تُعدّ فشلًا ولا تكلفة صفرية',
        );
        $details[] = GateDetail::plain(
            'التكلفة',
            'صفوف الاستخدام تُسجَّل بلا سعر: التفريغ يُسعَّر بالدقيقة ولا يوجد تسعير بالدقيقة بعد',
        );

        return GateOutcome::ready('التفريغ مفعَّل، الوسائط قابلة للتنزيل، ومزوّد تفريغ مؤهَّل بمفتاح صالح.', $details);
    }

    /**
     * The routed transcription provider/model and every eligible candidate.
     *
     * A candidate counts only when its ADAPTER implements SupportsTranscription,
     * not merely when the catalog says the model transcribes: the catalog is
     * operator data, and operator data must not be able to crash a worker. The
     * pipeline applies exactly this rule (VoiceNoteTranscriber::route), so the
     * gate and the runtime cannot disagree — including on the awkward case where
     * the selected row is catalogued for transcription by a provider that cannot
     * serve it, which is treated as no route by both.
     *
     * @return array{0: ?ModelSpec, 1: list<ModelSpec>, 2: ?string}
     */
    private static function route(): array
    {
        try {
            $evaluation = app(SanadAiRouter::class)->evaluate(AiOperation::Transcription);
            $manager = app(AiManager::class);

            $transcribes = static function (ModelSpec $spec) use ($manager): bool {
                try {
                    return $manager->provider($spec->provider) instanceof SupportsTranscription;
                } catch (Throwable) {
                    return false;
                }
            };

            $eligible = array_values(array_filter($evaluation->eligible(), $transcribes));
            $selected = $evaluation->selected;

            return [
                $selected !== null && $transcribes($selected) ? $selected : null,
                $eligible,
                $selected === null
                    ? null
                    : ($transcribes($selected) ? null : 'المزوّد المختار لا ينفّذ SupportsTranscription — يُعامَل كأنه بلا مسار'),
            ];
        } catch (Throwable $e) {
            // The message comes from the typed AI hierarchy, which is safe by
            // construction (status codes and short reasons only).
            return [null, [], $e->getMessage()];
        }
    }
}

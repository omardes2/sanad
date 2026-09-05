<?php

declare(strict_types=1);

namespace App\Services\Ai\Routing;

use App\Contracts\Ai\SupportsHealthChecks;
use App\Data\Ai\Catalog\RouteEvaluation;
use App\Data\Ai\Routing\CutoverPreview;
use App\Data\Ai\Routing\ReadinessCheck;
use App\Data\Ai\Routing\ReadinessReport;
use App\Data\Credentials\ResolvedCredential;
use App\Enums\CredentialSource;
use App\Enums\HealthCheckKind;
use App\Enums\HealthCheckStatus;
use App\Exceptions\Routing\CutoverBlockedException;
use App\Exceptions\Routing\StaleCutoverException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\ProviderHealthCheck;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Pricing\PriceBook;
use App\Services\Credentials\CredentialManager;
use App\Services\Credentials\CredentialResolver;
use App\Services\Settings\SettingsRepository;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of the two managed settings (`ai.catalog_source`,
 * `ai.routing.mode`) and of `ai_providers.is_primary` (Phase C4). Three
 * independent, one-thing-at-a-time cutovers, each: preview (readiness +
 * simulation with the real router) → typed confirmation of the resulting
 * `provider:model` → apply inside one transaction that re-reads the state
 * the admin saw (stale conflict otherwise; never last-writer-wins) → audit in
 * the same transaction.
 *
 *  Stage B  whatIfDatabaseCatalog(): the route the DATABASE catalog would
 *           produce while the runtime still uses config — read-only.
 *  Stage C  switchCatalogSource('config'|'database'): changes the catalog
 *           source ONLY. Going to `database` requires the same provider:model
 *           before and after (the step must not change the route).
 *  Mode     switchRoutingMode('env'|'db'): `env → db` requires a usable
 *           primary, the database catalog active, and the same route before
 *           and after; `db → env` is the rollback.
 *  Primary  setPrimary(provider, expectedCurrentPrimaryId): the only way the
 *           real provider changes, later, one provider at a time.
 *
 * An environment override on the setting (AI_CATALOG_SOURCE / AI_ROUTING_MODE)
 * blocks the corresponding cutover: the environment is the emergency layer.
 * Permission: ai.routing.cutover (super_admin).
 */
class RoutingCutover
{
    public const CATALOG_SOURCE = 'ai.catalog_source';

    public const ROUTING_MODE = 'ai.routing.mode';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly RoutingSimulator $simulator,
        private readonly RoutingPreference $preference,
        private readonly CatalogSourceResolver $resolver,
        private readonly CredentialResolver $credentials,
        private readonly AiManager $manager,
        private readonly PriceBook $prices,
        private readonly AuditLogger $audit,
    ) {}

    // ---- Stage B ------------------------------------------------------------------

    public function whatIfDatabaseCatalog(): CutoverPreview
    {
        $before = $this->simulator->current();
        $after = $this->simulator->proposed(catalogSource: 'database');
        $readiness = $after->selected === null ? null : $this->readiness($after->selected->provider, $after->selected->model, 'database');

        return new CutoverPreview('what_if_database', $this->resolver->mode(), 'database', $before, $after, $readiness, [], $readiness?->warnings() ?? [], false);
    }

    // ---- Stage C: catalog source -----------------------------------------------------

    public function previewCatalogSource(string $target): CutoverPreview
    {
        $this->authorize();
        $target = strtolower(trim($target));
        $current = $this->resolver->mode();
        $before = $this->simulator->current();
        $blockers = [];

        if (! in_array($target, ['config', 'database'], true)) {
            $blockers[] = 'الهدف يجب أن يكون config أو database.';
        }

        if ($this->settings->effective(self::CATALOG_SOURCE)->envForced()) {
            $blockers[] = 'AI_CATALOG_SOURCE مضبوط في البيئة؛ البيئة هي طبقة الطوارئ ولا يمكن تغيير المصدر من اللوحة.';
        }

        if ($target === $current) {
            $blockers[] = "مصدر الكتالوج هو [{$current}] بالفعل.";
        }

        $after = $this->simulator->proposed(catalogSource: $target === 'database' ? 'database' : 'config');
        $readiness = $after->selected === null ? null : $this->readiness($after->selected->provider, $after->selected->model, $target === 'database' ? 'database' : 'config');
        $blockers = [...$blockers, ...$this->commonBlockers($before, $after, $readiness, sameRouteRequired: $target === 'database')];

        return new CutoverPreview('catalog_source', $current, $target, $before, $after, $readiness, $blockers, $readiness?->warnings() ?? [], $target === 'database');
    }

    /**
     * @throws AuthorizationException|CutoverBlockedException|StaleCutoverException
     */
    public function switchCatalogSource(string $target, string $expectedCurrent, ?string $confirmation): CutoverPreview
    {
        $this->authorize();

        return $this->applySetting(self::CATALOG_SOURCE, $target, $expectedCurrent, $confirmation, fn (): CutoverPreview => $this->previewCatalogSource($target), AuditActions::AiCatalogSourceChanged);
    }

    // ---- Routing mode ----------------------------------------------------------------

    public function previewRoutingMode(string $target): CutoverPreview
    {
        $this->authorize();
        $target = strtolower(trim($target));
        $current = $this->preference->mode();
        $before = $this->simulator->current();
        $blockers = [];

        if (! in_array($target, [RoutingPreference::MODE_ENV, RoutingPreference::MODE_DB], true)) {
            $blockers[] = 'الهدف يجب أن يكون env أو db.';
        }

        if ($this->settings->effective(self::ROUTING_MODE)->envForced()) {
            $blockers[] = 'AI_ROUTING_MODE مضبوط في البيئة؛ البيئة هي طبقة الطوارئ ولا يمكن تغيير الوضع من اللوحة.';
        }

        if ($target === $current) {
            $blockers[] = "وضع التوجيه هو [{$current}] بالفعل.";
        }

        if ($target === RoutingPreference::MODE_DB) {
            $primary = $this->preference->primary();

            if ($primary === null || ! $primary->is_enabled) {
                $blockers[] = 'لا يوجد مزوّد أساسي (is_primary) مفعّل في قاعدة البيانات؛ عيّنه أولًا.';
            }

            if ($this->resolver->activeName() !== 'database') {
                $blockers[] = 'كتالوج قاعدة البيانات ليس المصدر الفعّال؛ نفّذ cutover مصدر الكتالوج أولًا.';
            }

            $preferred = $primary?->key ?? $this->preference->envProvider();
        } else {
            $preferred = $this->preference->envProvider();
        }

        $after = $this->simulator->proposed(preferredProvider: $preferred);
        $readiness = $after->selected === null ? null : $this->readiness($after->selected->provider, $after->selected->model, $this->resolver->activeName());
        $blockers = [...$blockers, ...$this->commonBlockers($before, $after, $readiness, sameRouteRequired: $target === RoutingPreference::MODE_DB)];

        return new CutoverPreview('routing_mode', $current, $target, $before, $after, $readiness, $blockers, $readiness?->warnings() ?? [], $target === RoutingPreference::MODE_DB);
    }

    /**
     * @throws AuthorizationException|CutoverBlockedException|StaleCutoverException
     */
    public function switchRoutingMode(string $target, string $expectedCurrent, ?string $confirmation): CutoverPreview
    {
        $this->authorize();

        return $this->applySetting(self::ROUTING_MODE, $target, $expectedCurrent, $confirmation, fn (): CutoverPreview => $this->previewRoutingMode($target), AuditActions::AiRoutingModeChanged);
    }

    // ---- Primary ---------------------------------------------------------------------

    public function previewPrimary(AiProvider $target): CutoverPreview
    {
        $this->authorize();
        $current = $this->preference->primary();
        $before = $this->simulator->current();
        $blockers = [];

        if (! $target->is_enabled) {
            $blockers[] = "المزوّد [{$target->key}] معطّل.";
        }

        if (! $this->manager->has($target->key)) {
            $blockers[] = "لا يوجد adapter للمزوّد [{$target->key}].";
        }

        if ($current !== null && $current->id === $target->id) {
            $blockers[] = "المزوّد [{$target->key}] هو الأساسي بالفعل.";
        }

        // In db mode this changes the route now; in env mode it has no runtime
        // effect until the mode is switched — the preview says which.
        $after = $this->preference->mode() === RoutingPreference::MODE_DB
            ? $this->simulator->proposed(preferredProvider: $target->key)
            : $this->simulator->current();
        $readiness = $this->readiness($target->key, $after->selected?->provider === $target->key ? $after->selected->model : $this->firstEnabledModel($target), $this->resolver->activeName());
        $blockers = [...$blockers, ...$this->commonBlockers($before, $after, $readiness, sameRouteRequired: false)];

        return new CutoverPreview('primary', (string) ($current?->id ?? ''), (string) $target->id, $before, $after, $readiness, $blockers, $readiness->warnings(), false);
    }

    /**
     * @throws AuthorizationException|CutoverBlockedException|StaleCutoverException
     */
    public function setPrimary(AiProvider $target, ?int $expectedCurrentPrimaryId, ?string $confirmation): AiProvider
    {
        $this->authorize();

        $result = DB::transaction(function () use ($target, $expectedCurrentPrimaryId, $confirmation): AiProvider {
            // Lock the target and the current primary row(s) so concurrent
            // setPrimary calls serialise on the same state.
            $locked = AiProvider::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $current = AiProvider::query()->where('is_primary', true)->lockForUpdate()->first();

            if (($current?->id) !== $expectedCurrentPrimaryId) {
                throw new StaleCutoverException(sprintf(
                    'تعارض: المزوّد الأساسي تغيّر منذ المعاينة (المتوقع %s، الحالي %s). أعد المعاينة.',
                    $expectedCurrentPrimaryId === null ? 'لا شيء' : '#'.$expectedCurrentPrimaryId,
                    $current === null ? 'لا شيء' : '#'.$current->id,
                ));
            }

            CatalogCache::flush(); // preview must see the locked, current rows
            $preview = $this->previewPrimary($locked);
            $this->assertApplicable($preview, $confirmation);

            $now = CarbonImmutable::now();

            if ($current !== null) {
                $current->forceFill(['is_primary' => false])->save();
            }

            $locked->forceFill(['is_primary' => true])->save();

            $this->audit->record(AuditActions::AiRoutingPrimaryChanged, $locked, [
                'primary' => ['from' => $current?->key, 'to' => $locked->key],
            ], $this->auditContext($preview, $expectedCurrentPrimaryId === null ? null : (string) $expectedCurrentPrimaryId) + ['routing_mode' => $this->preference->mode(), 'at' => $now->toIso8601String()]);

            return $locked;
        });

        CatalogCache::flushAfterCommit();

        return $result;
    }

    // ---- Readiness -------------------------------------------------------------------

    /**
     * Readiness of a provider (and the model that would serve chat) for
     * becoming the governing route. Health must prove the EXACT effective
     * credential: the vault row id, or the env key's fingerprint snapshot.
     */
    public function readiness(string $providerKey, ?string $model, string $catalogSource): ReadinessReport
    {
        $checks = [];
        $known = $this->manager->has($providerKey);
        $row = AiProvider::query()->where('key', $providerKey)->first();

        if ($catalogSource === 'database') {
            $enabledModels = $row === null ? 0 : AiModel::query()->where('provider_id', $row->id)->where('is_enabled', true)->count();
            $checks[] = new ReadinessCheck('provider', $row !== null && $row->is_enabled && $known ? ReadinessCheck::OK : ReadinessCheck::FAIL, 'المزوّد',
                $row === null ? 'لا صف في الكتالوج' : ($row->is_enabled ? ($known ? 'مفعّل وله adapter' : 'لا adapter') : 'معطّل'));
            $checks[] = new ReadinessCheck('models', $enabledModels > 0 ? ReadinessCheck::OK : ReadinessCheck::FAIL, 'النماذج', $enabledModels.' نموذج مفعّل');
        } else {
            $checks[] = new ReadinessCheck('provider', $known ? ReadinessCheck::OK : ReadinessCheck::FAIL, 'المزوّد', $known ? 'adapter موجود (كتالوج config)' : 'لا adapter');
        }

        $resolved = $known ? $this->credentials->resolve($providerKey) : null;

        if ($resolved === null || ! $resolved->usable()) {
            $checks[] = new ReadinessCheck('credential', ReadinessCheck::FAIL, 'المفتاح', $resolved?->failedClosed() ? 'المزوّد مغلق ('.$resolved->failure.')' : 'لا مفتاح صالح');
        } else {
            $checks[] = new ReadinessCheck('credential', ReadinessCheck::OK, 'المفتاح', $resolved->source->label().' '.$resolved->fingerprint);
        }

        $checks[] = $this->healthCheck($providerKey, $row, $resolved?->usable() ? $resolved : null, $known);

        if ($model !== null && $row !== null) {
            $modelRow = AiModel::query()->where('provider_id', $row->id)->where('external_id', $model)->first();
            $price = $modelRow === null ? null : $this->prices->priceFor($modelRow->id, CarbonImmutable::now());
            $checks[] = new ReadinessCheck('pricing', $price !== null ? ReadinessCheck::OK : ReadinessCheck::WARN, 'التسعير',
                $price !== null ? "سعر ساري #{$price->id} ({$price->currency})" : 'COST UNKNOWN — لا سعر ساري لهذا النموذج؛ الاستخدام سيُسجَّل بتكلفة غير معروفة (ليس $0)');
        } else {
            $checks[] = new ReadinessCheck('pricing', ReadinessCheck::WARN, 'التسعير', 'COST UNKNOWN — النموذج ليس في كتالوج قاعدة البيانات');
        }

        return new ReadinessReport($providerKey, $model, $checks);
    }

    private function healthCheck(string $providerKey, ?AiProvider $row, ?ResolvedCredential $resolved, bool $known): ReadinessCheck
    {
        if ($row === null || $resolved === null) {
            return new ReadinessCheck('health', ReadinessCheck::FAIL, 'الصحة', 'لا يمكن التحقق بلا صف مزوّد ومفتاح صالح');
        }

        $adapter = $known ? $this->manager->providerWith($providerKey, ['base_url' => 'https://unused.invalid', 'api_key' => null]) : null;
        $hasProbe = $adapter instanceof SupportsHealthChecks && $adapter->healthCapabilities()->nonBillableAuthProbe;

        if (! $hasProbe) {
            return new ReadinessCheck('health', ReadinessCheck::WARN, 'الصحة', 'لا فحص مصادقة غير مفوتر لهذا الـadapter؛ لا يمكن إثبات المفتاح مجانًا');
        }

        $window = CredentialManager::verificationWindowMinutes();
        $query = ProviderHealthCheck::query()
            ->where('provider_id', $row->id)
            ->where('kind', HealthCheckKind::Auth->value)
            ->where('status', HealthCheckStatus::Ok->value)
            ->where('checked_at', '>=', CarbonImmutable::now()->subMinutes($window));

        // EXACT credential: the vault row id, or the env key's fingerprint
        // snapshot stored in the probe details. A key that changed since the
        // probe is not covered by it.
        if ($resolved->source === CredentialSource::Vault) {
            $query->where('credential_id', $resolved->credentialId);
        } else {
            $query->where('credential_source', CredentialSource::Env->value)->where('details->credential_fingerprint', $resolved->fingerprint);
        }

        $latest = $query->orderByDesc('checked_at')->first();

        return $latest === null
            ? new ReadinessCheck('health', ReadinessCheck::FAIL, 'الصحة', "لا فحص مصادقة ناجح لنفس المفتاح ({$resolved->fingerprint}) خلال آخر {$window} دقيقة؛ شغّل اختبار الاتصال الآن")
            : new ReadinessCheck('health', ReadinessCheck::OK, 'الصحة', 'مصادقة ناجحة لنفس المفتاح '.$latest->checked_at?->format('H:i').' (#'.$latest->id.')');
    }

    // ---- Internals -------------------------------------------------------------------

    /**
     * @param  callable(): CutoverPreview  $preview
     *
     * @throws CutoverBlockedException|StaleCutoverException
     */
    private function applySetting(string $key, string $target, string $expectedCurrent, ?string $confirmation, callable $preview, string $action): CutoverPreview
    {
        $target = strtolower(trim($target));
        $expectedCurrent = strtolower(trim($expectedCurrent));

        $result = DB::transaction(function () use ($key, $target, $expectedCurrent, $confirmation, $preview, $action): CutoverPreview {
            $effectiveNow = (string) $this->settings->effective($key)->value;

            // Serialise concurrent cutovers on the setting row: create it when
            // absent (a concurrent creator loses on the unique key), then lock.
            try {
                AppSetting::query()->firstOrCreate(['key' => $key], ['value' => $effectiveNow]);
            } catch (QueryException) {
                throw new StaleCutoverException('تعارض: إعداد آخر كُتب في اللحظة نفسها. أعد المعاينة.');
            }

            AppSetting::query()->where('key', $key)->lockForUpdate()->first();
            $this->settings->cacheFlush();
            $current = (string) $this->settings->effective($key)->value;

            if ($current !== $expectedCurrent) {
                throw new StaleCutoverException("تعارض: القيمة الحالية [{$current}] لا تطابق ما رأيته عند المعاينة [{$expectedCurrent}]. أعد المعاينة.");
            }

            CatalogCache::flush();
            $p = $preview();
            $this->assertApplicable($p, $confirmation);

            $this->settings->setManaged($key, $target, 'cutover');

            $this->audit->record($action, null, [
                $key => ['from' => $current, 'to' => $target],
            ], $this->auditContext($p, $expectedCurrent));

            return $p;
        });

        CatalogCache::flushAfterCommit();

        return $result;
    }

    /**
     * @throws CutoverBlockedException
     */
    private function assertApplicable(CutoverPreview $preview, ?string $confirmation): void
    {
        $blockers = $preview->blockers;

        if (! $preview->after->hasRoute()) {
            $blockers[] = 'لا مسار صالح لعملية chat بعد التغيير (last viable route).';
        }

        $expected = $preview->expectedConfirmation();

        if ($blockers === [] && ($confirmation === null || trim($confirmation) !== $expected || $expected === '')) {
            $blockers[] = "اكتب المسار الناتج «{$expected}» (provider:model) للتأكيد.";
        }

        if ($blockers !== []) {
            throw new CutoverBlockedException(array_values(array_unique($blockers)));
        }
    }

    /**
     * @return list<string>
     */
    private function commonBlockers(RouteEvaluation $before, RouteEvaluation $after, ?ReadinessReport $readiness, bool $sameRouteRequired): array
    {
        $blockers = [];

        if (! $after->hasRoute()) {
            $blockers[] = 'لا مسار صالح لعملية chat بعد التغيير (last viable route).';
        }

        if ($sameRouteRequired && $after->hasRoute() && $before->selectedHandle() !== $after->selectedHandle()) {
            $blockers[] = sprintf('هذه الخطوة يجب ألا تغيّر المسار: قبل [%s] بعد [%s]. غيّر المسار لاحقًا بعملية مستقلة.', $before->selectedHandle() ?? 'لا شيء', $after->selectedHandle() ?? 'لا شيء');
        }

        if ($readiness !== null) {
            $blockers = [...$blockers, ...$readiness->failures()];
        }

        return $blockers;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditContext(CutoverPreview $preview, ?string $expectedCurrent): array
    {
        return [
            'kind' => $preview->kind,
            'expected_current' => $expectedCurrent,
            'route_before' => $preview->before->selectedHandle(),
            'route_after' => $preview->after->selectedHandle(),
            'same_route' => $preview->sameRoute(),
            'readiness' => $preview->readiness === null ? null : array_map(static fn (array $c): array => ['key' => $c['key'], 'status' => $c['status']], $preview->readiness->toArray()['checks']),
            'warnings' => $preview->warnings,
            'confirmed' => true,
            'confirmation' => $preview->expectedConfirmation(),
        ];
    }

    private function firstEnabledModel(AiProvider $provider): ?string
    {
        return AiModel::query()->where('provider_id', $provider->id)->where('is_enabled', true)->orderByDesc('priority')->orderBy('id')->value('external_id');
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(): void
    {
        $user = Auth::user();

        if ($user !== null) {
            if (! $user->can(Permission::AiRoutingCutover->value) || ! $user->hasRole(Role::SuperAdmin->value)) {
                throw new AuthorizationException('Cutover is reserved to super_admin with ai.routing.cutover.');
            }

            return;
        }

        if (! app()->runningInConsole()) {
            throw new AuthorizationException('Unauthenticated cutover.');
        }
    }
}

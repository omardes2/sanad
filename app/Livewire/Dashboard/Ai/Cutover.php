<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Ai;

use App\Data\Ai\Catalog\RouteEvaluation;
use App\Data\Ai\Routing\CutoverPreview;
use App\Exceptions\Routing\CutoverBlockedException;
use App\Exceptions\Routing\StaleCutoverException;
use App\Models\AiProvider;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Ai\Routing\RoutingCutover;
use App\Services\Ai\Routing\RoutingPreference;
use App\Services\Billing\Pricing\CostEstimator;
use App\Services\Settings\SettingsRepository;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cutover console (Phase C4), super_admin + ai.routing.cutover only. Three
 * independent one-thing-at-a-time actions, each preview → typed
 * `provider:model` confirmation → apply with the state the admin saw:
 *   1. catalog source (config ↔ database)   — Stage C
 *   2. routing mode (env ↔ db)
 *   3. primary provider (is_primary)
 * plus the read-only Stage B what-if (database catalog while the runtime
 * still uses config). Nothing here runs automatically.
 */
#[Title('Cutover | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Cutover extends Component
{
    public string $catalogTarget = '';

    public string $modeTarget = '';

    public string $primaryTarget = '';

    /** @var array<string, array<string, mixed>|null> kind => preview (safe arrays only) */
    public array $previews = [];

    /** @var array<string, string> kind => typed confirmation */
    public array $confirmations = [];

    /** @var array<string, list<string>> */
    public array $problems = [];

    public ?string $notice = null;

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless(($user?->can(Permission::AiRoutingCutover->value) ?? false) && $user->hasRole(Role::SuperAdmin->value), 403);
    }

    public function whatIf(RoutingCutover $cutover): void
    {
        $this->previews['what_if'] = $this->toArray($cutover->whatIfDatabaseCatalog());
    }

    public function previewCatalog(RoutingCutover $cutover): void
    {
        $this->run('catalog_source', fn () => $this->previews['catalog_source'] = $this->toArray($cutover->previewCatalogSource($this->catalogTarget)));
    }

    public function applyCatalog(RoutingCutover $cutover): void
    {
        $preview = $this->previews['catalog_source'] ?? null;

        if ($preview === null) {
            $this->problems['catalog_source'] = ['اعرض المعاينة أولًا.'];

            return;
        }

        $this->run('catalog_source', function () use ($cutover, $preview): void {
            $cutover->switchCatalogSource($preview['target'], $preview['current'], $this->confirmations['catalog_source'] ?? null);
            $this->notice = "تم تغيير مصدر الكتالوج إلى [{$preview['target']}] والمسار [{$preview['expected']}].";
            $this->previews['catalog_source'] = null;
            $this->confirmations['catalog_source'] = '';
        });
    }

    public function previewMode(RoutingCutover $cutover): void
    {
        $this->run('routing_mode', fn () => $this->previews['routing_mode'] = $this->toArray($cutover->previewRoutingMode($this->modeTarget)));
    }

    public function applyMode(RoutingCutover $cutover): void
    {
        $preview = $this->previews['routing_mode'] ?? null;

        if ($preview === null) {
            $this->problems['routing_mode'] = ['اعرض المعاينة أولًا.'];

            return;
        }

        $this->run('routing_mode', function () use ($cutover, $preview): void {
            $cutover->switchRoutingMode($preview['target'], $preview['current'], $this->confirmations['routing_mode'] ?? null);
            $this->notice = "تم تغيير وضع التوجيه إلى [{$preview['target']}] والمسار [{$preview['expected']}].";
            $this->previews['routing_mode'] = null;
            $this->confirmations['routing_mode'] = '';
        });
    }

    public function previewPrimary(RoutingCutover $cutover): void
    {
        $this->run('primary', function () use ($cutover): void {
            $provider = AiProvider::query()->findOrFail((int) $this->primaryTarget);
            $this->previews['primary'] = $this->toArray($cutover->previewPrimary($provider));
        });
    }

    public function applyPrimary(RoutingCutover $cutover): void
    {
        $preview = $this->previews['primary'] ?? null;

        if ($preview === null) {
            $this->problems['primary'] = ['اعرض المعاينة أولًا.'];

            return;
        }

        $this->run('primary', function () use ($cutover, $preview): void {
            $provider = AiProvider::query()->findOrFail((int) $preview['target']);
            $cutover->setPrimary($provider, $preview['current'] === '' ? null : (int) $preview['current'], $this->confirmations['primary'] ?? null);
            $this->notice = "أصبح [{$provider->key}] المزوّد الأساسي؛ المسار [{$preview['expected']}].";
            $this->previews['primary'] = null;
            $this->confirmations['primary'] = '';
        });
    }

    private function run(string $kind, callable $action): void
    {
        $this->problems[$kind] = [];
        $this->notice = null;

        try {
            $action();
        } catch (CutoverBlockedException $e) {
            $this->problems[$kind] = $e->blockers;
        } catch (StaleCutoverException $e) {
            $this->problems[$kind] = [$e->getMessage()];
            $this->previews[$kind] = null;
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    public function render(RoutingPreference $preference, CatalogSourceResolver $resolver, SettingsRepository $settings)
    {
        $resolution = $preference->resolve();

        return view('livewire.dashboard.ai.cutover', [
            'providers' => AiProvider::query()->orderByDesc('priority')->orderBy('id')->get(),
            'resolution' => $resolution,
            'primary' => $preference->primary(),
            'catalogMode' => $resolver->mode(),
            'catalogActive' => $resolver->activeName(),
            'catalogEnvForced' => $settings->effective(RoutingCutover::CATALOG_SOURCE)->envForced(),
            'modeEnvForced' => $settings->effective(RoutingCutover::ROUTING_MODE)->envForced(),
            'envProvider' => $preference->envProvider(),
            'reasonLabels' => Routing::reasonLabels(),
            'currency' => (string) config('billing.cost_currency', 'USD'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(CutoverPreview $preview): array
    {
        $estimator = app(CostEstimator::class);
        $rows = static function (RouteEvaluation $evaluation) use ($estimator): array {
            $out = [];

            foreach ($evaluation->candidates as $row) {
                $estimate = $estimator->estimate($row['spec']);
                $out[] = [
                    'handle' => $row['spec']->provider.':'.$row['spec']->model,
                    'status' => $row['status'],
                    'reason' => $row['reason'],
                    'estimate' => $estimate === null ? null : number_format($estimate, 6),
                ];
            }

            return $out;
        };

        return [
            'kind' => $preview->kind,
            'current' => $preview->currentValue,
            'target' => $preview->targetValue,
            'before' => $preview->before->selectedHandle(),
            'after' => $preview->after->selectedHandle(),
            'before_rows' => $rows($preview->before),
            'after_rows' => $rows($preview->after),
            'same_route' => $preview->sameRoute(),
            'same_route_required' => $preview->sameRouteRequired,
            'readiness' => $preview->readiness?->toArray()['checks'] ?? [],
            'blockers' => $preview->blockers,
            'warnings' => $preview->warnings,
            'expected' => $preview->expectedConfirmation(),
            'applicable' => $preview->applicable(),
        ];
    }
}

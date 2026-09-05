<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Ai;

use App\Enums\AiOperation;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Billing\Pricing\CostEstimator;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Routing visibility + what-if simulation (Phase C2). READ-ONLY: it shows
 * exactly what SanadAiRouter::evaluate() decides for an operation — every
 * candidate with the reason it was skipped — and lets the admin try a
 * different preferred provider or cost guardrail WITHOUT writing anything.
 * There is no cutover here: AI_PROVIDER stays the operational preference and
 * is_primary is not read (Phase C4).
 *
 * Cost estimates appear only for accounts that may see costs.
 */
#[Title('التوجيه | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Routing extends Component
{
    public string $operation = 'chat';

    public string $preferred = '';

    public string $maxUnitCost = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::AiRoutingManage->value) ?? false, 403);
    }

    public function render(RoutingSimulator $simulator, CatalogSourceResolver $resolver, AiManager $manager, CostEstimator $estimator)
    {
        $user = auth()->user();
        $showCosts = ($user?->can(Permission::UsageViewCosts->value) ?? false) || ($user?->can(Permission::AiPricingView->value) ?? false);
        $operation = AiOperation::tryFrom($this->operation) ?? AiOperation::Chat;
        $preferred = trim($this->preferred) !== '' && $manager->has(trim($this->preferred)) ? trim($this->preferred) : null;
        $max = is_numeric(trim($this->maxUnitCost)) && (float) $this->maxUnitCost >= 0 ? (float) $this->maxUnitCost : null;

        $live = $simulator->current($operation);
        $whatIf = ($preferred !== null || $max !== null) ? $simulator->current($operation, $preferred, $max) : null;

        $rows = static function ($evaluation) use ($showCosts, $estimator): array {
            $out = [];

            foreach ($evaluation->candidates as $row) {
                $out[] = [
                    'handle' => $row['spec']->provider.':'.$row['spec']->model,
                    'priority' => $row['spec']->priority,
                    'status' => $row['status'],
                    'reason' => $row['reason'],
                    'fallback' => $row['spec']->fallbackModel === null ? null : ($row['spec']->fallbackProvider ?? $row['spec']->provider).':'.$row['spec']->fallbackModel,
                    'estimate' => $showCosts ? ($row['estimate'] ?? $estimator->estimate($row['spec'])) : null,
                ];
            }

            return $out;
        };

        return view('livewire.dashboard.ai.routing', [
            'operations' => AiOperation::cases(),
            'providersKnown' => $manager->names(),
            'sourceMode' => $resolver->mode(),
            'sourceActive' => $resolver->activeName(),
            'envPreferred' => (string) config('ai.provider', 'groq'),
            'live' => $live,
            'liveRows' => $rows($live),
            'whatIf' => $whatIf,
            'whatIfRows' => $whatIf === null ? [] : $rows($whatIf),
            'showCosts' => $showCosts,
            'currency' => (string) config('billing.cost_currency', 'USD'),
            'reasonLabels' => self::reasonLabels(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function reasonLabels(): array
    {
        return [
            'disabled' => 'معطّل في الكتالوج',
            'unsupported_operation' => 'النموذج لا يدعم العملية',
            'unknown_provider' => 'لا يوجد adapter لهذا المزوّد',
            'provider_unsupported_operation' => 'الـadapter لا يدعم العملية',
            'unconfigured' => 'المزوّد بلا مفتاح/إعداد في البيئة',
            'cost_guardrail' => 'التكلفة المقدّرة تتجاوز الحد',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Ai;

use App\Data\Billing\PricePublication;
use App\Enums\ModelPriceSource;
use App\Exceptions\Billing\PriceOverlapException;
use App\Models\AiModel;
use App\Models\ModelPrice;
use App\Services\Billing\Pricing\CostCalculator;
use App\Services\Billing\Pricing\PriceBook;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Historical price book (Phase C2). `ai.pricing.view` shows every period of
 * every model; `ai.pricing.manage` may PUBLISH a new period — through
 * PriceBook only (parent-row lock, overlap rejection, audit in the same
 * transaction). Existing periods are never edited or deleted here: a mistake
 * is corrected by publishing a new period, exactly like sanad:ai:price.
 *
 * Publication is two-step: preview (with a worked example so a per-1K vs
 * per-1M mistake is visible, and the open period that would be closed), then
 * an explicit confirm.
 */
#[Title('الأسعار | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Pricing extends Component
{
    public ?int $publishing = null;

    /** @var array<string, string> */
    public array $form = [];

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    /** @var list<string> */
    public array $problems = [];

    public ?string $notice = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::AiPricingView->value) ?? false, 403);
    }

    public function start(int $modelId): void
    {
        abort_unless(auth()->user()?->can(Permission::AiPricingManage->value) ?? false, 403);

        $model = AiModel::query()->findOrFail($modelId);

        $this->publishing = $model->id;
        $this->preview = null;
        $this->problems = [];
        $this->notice = null;
        $this->form = [
            'currency' => (string) config('billing.cost_currency', 'USD'),
            'input' => '', 'output' => '', 'cached' => '', 'per_request' => '0',
            'effective_from' => CarbonImmutable::now()->format('Y-m-d\TH:i'),
            'effective_until' => '',
            'note' => '',
        ];
    }

    public function cancel(): void
    {
        $this->publishing = null;
        $this->preview = null;
        $this->form = [];
        $this->problems = [];
    }

    public function previewPublication(PriceBook $book, CostCalculator $calculator): void
    {
        abort_unless(auth()->user()?->can(Permission::AiPricingManage->value) ?? false, 403);

        $this->problems = [];
        $this->preview = null;

        try {
            $publication = $this->publication();
        } catch (InvalidArgumentException|Throwable $e) {
            $this->problems = [$e->getMessage()];

            return;
        }

        $model = AiModel::query()->with('provider')->findOrFail((int) $this->publishing);
        $open = $book->openPriceFor($model->id);

        $sample = new ModelPrice([
            'currency' => $publication->currency, 'unit' => $publication->unit,
            'input_per_million' => $publication->inputPerMillion, 'output_per_million' => $publication->outputPerMillion,
            'cached_input_per_million' => $publication->cachedInputPerMillion, 'per_request' => $publication->perRequest,
        ]);

        $this->preview = [
            'model' => $model->handle(),
            'currency' => strtoupper($publication->currency),
            'input' => $publication->inputPerMillion,
            'output' => $publication->outputPerMillion,
            'cached' => $publication->cachedInputPerMillion ?? '(= input)',
            'per_request' => $publication->perRequest,
            'from' => $publication->effectiveFrom->toIso8601String(),
            'until' => $publication->effectiveUntil?->toIso8601String() ?? 'مفتوح',
            'example' => $calculator->estimateTokens($sample, 1000, 300, 0).' '.strtoupper($publication->currency),
            'closes' => $open === null ? null : ['id' => $open->id, 'from' => $open->effective_from?->toIso8601String()],
            'backdated' => $publication->effectiveFrom < CarbonImmutable::now()->subMinute(),
        ];
    }

    public function publish(PriceBook $book): void
    {
        abort_unless(auth()->user()?->can(Permission::AiPricingManage->value) ?? false, 403);

        if ($this->preview === null) {
            $this->problems = ['اعرض المعاينة أولًا ثم أكّد النشر.'];

            return;
        }

        $model = AiModel::query()->with('provider')->findOrFail((int) $this->publishing);

        try {
            $price = $book->publish($model, $this->publication());
        } catch (PriceOverlapException|InvalidArgumentException $e) {
            $this->problems = [$e->getMessage()];
            $this->preview = null;

            return;
        }

        $this->notice = "نُشر السعر #{$price->id} للنموذج «{$model->handle()}».";
        $this->cancel();
    }

    public function render()
    {
        $user = auth()->user();
        $models = AiModel::query()->with(['provider', 'prices' => static fn ($q) => $q->orderByDesc('effective_from')->orderByDesc('id')])->orderBy('provider_id')->orderBy('id')->get();

        return view('livewire.dashboard.ai.pricing', [
            'models' => $models,
            'canManage' => $user?->can(Permission::AiPricingManage->value) ?? false,
            'now' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function publication(): PricePublication
    {
        $from = trim($this->form['effective_from'] ?? '');
        $until = trim($this->form['effective_until'] ?? '');
        $cached = trim($this->form['cached'] ?? '');
        $note = trim($this->form['note'] ?? '');

        if ($from === '') {
            throw new InvalidArgumentException('تاريخ بداية السريان مطلوب.');
        }

        return new PricePublication(
            currency: trim($this->form['currency'] ?? ''),
            inputPerMillion: trim($this->form['input'] ?? ''),
            outputPerMillion: trim($this->form['output'] ?? ''),
            cachedInputPerMillion: $cached === '' ? null : $cached,
            perRequest: trim($this->form['per_request'] ?? '0') === '' ? '0' : trim($this->form['per_request']),
            effectiveFrom: CarbonImmutable::parse($from, config('app.timezone')),
            effectiveUntil: $until === '' ? null : CarbonImmutable::parse($until, config('app.timezone')),
            source: ModelPriceSource::Manual,
            note: $note === '' ? null : mb_substr($note, 0, 500),
            createdBy: auth()->id(),
        );
    }
}

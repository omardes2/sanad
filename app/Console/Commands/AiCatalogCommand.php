<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Models\AiModel;
use App\Models\ModelPrice;
use App\Models\UsageEvent;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Billing\Pricing\PriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only diagnostics: the catalog exactly as the router sees it now (which
 * source is active, candidate order, which providers are configured), the
 * current open price per model, and how many ledger rows are UNPRICED
 * (unknown cost). Safe to run anywhere, any time; it writes nothing.
 */
class AiCatalogCommand extends Command
{
    protected $signature = 'sanad:ai:catalog';

    protected $description = 'Show the AI catalog as the router sees it, current prices, and unpriced usage events';

    public function handle(CatalogSourceResolver $catalog, AiManager $manager, PriceBook $book): int
    {
        $preferred = (string) config('ai.provider', 'groq');

        $this->line('Catalog source mode: <info>'.$catalog->mode().'</info> → active: <info>'.$catalog->activeName().'</info>');
        $this->line("Preferred provider (AI_PROVIDER): <info>{$preferred}</info>");

        $candidates = $catalog->candidates(AiOperation::Chat, new RoutingContext);

        usort(
            $candidates,
            static fn (ModelSpec $a, ModelSpec $b): int => [$b->provider === $preferred, $b->priority]
                <=> [$a->provider === $preferred, $a->priority],
        );

        $rows = [];

        foreach ($candidates as $index => $spec) {
            $known = $manager->has($spec->provider);
            $configured = $known && $manager->provider($spec->provider)->isConfigured();
            $price = $spec->modelId === null ? null : $book->priceFor($spec->modelId, CarbonImmutable::now());

            $rows[] = [
                $index + 1,
                $spec->provider.':'.$spec->model,
                $spec->priority,
                $spec->enabled ? 'yes' : 'no',
                $known ? ($configured ? 'yes' : 'no key') : 'unknown',
                $spec->fallbackModel !== null ? ($spec->fallbackProvider ?? $spec->provider).':'.$spec->fallbackModel : '-',
                $price === null ? 'NONE' : "#{$price->id} {$price->currency} in {$price->input_per_million} / out {$price->output_per_million}",
            ];
        }

        $this->table(['#', 'Model', 'Priority', 'Enabled', 'Configured', 'Fallback', 'Current price (per 1M)'], $rows);

        if (Schema::hasTable('ai_models')) {
            $registered = AiModel::query()->count();
            $openPrices = ModelPrice::query()->whereNull('effective_until')->count();
            $this->line("Registered models: {$registered}; models with an open price: {$openPrices}.");
        }

        if (Schema::hasColumn('usage_events', 'cost_source')) {
            $unpriced = UsageEvent::query()->unpriced()->count();
            $total = UsageEvent::query()->count();
            $this->line("Usage events: {$total} total, <comment>{$unpriced} UNPRICED</comment> (unknown cost — never read as free).");

            $byModel = UsageEvent::query()
                ->unpriced()
                ->selectRaw('provider, model, count(*) as n')
                ->groupBy('provider', 'model')
                ->orderByDesc('n')
                ->limit(20)
                ->get();

            if ($byModel->isNotEmpty()) {
                $this->table(
                    ['Provider', 'Model (as reported)', 'Unpriced events'],
                    $byModel->map(static fn ($row): array => [$row->provider, $row->model ?? '(null)', $row->n])->all(),
                );
            }
        }

        return self::SUCCESS;
    }
}

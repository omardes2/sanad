<?php

declare(strict_types=1);

use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E5.1 — forbidden vocabulary: no numeric metric on the overview, the
 * close history or the close detail is named Revenue, Gross Profit, Gross
 * Margin, Margin or Accounting Profit. The only remaining occurrences are
 * explicit status statements ("NOT AVAILABLE", "not revenue", the
 * Revenue Recognition policy name) — enumerated here, so a new occurrence
 * fails the test. Source level: no card label / table header in the finance
 * views carries those terms.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

const FORBIDDEN_TERMS = ['revenue', 'gross profit', 'gross margin', 'margin', 'accounting profit'];

/** Status statements that are allowed to mention a forbidden term because they say it is NOT a figure. */
const ALLOWED_STATUS_PHRASES = [
    'Historical revenue: <strong>NOT AVAILABLE</strong>',
    'no Revenue Recognition policy',
    'Revenue Recognition بعد',
    'Gross Profit / Margin / Revenue Recognition: <strong>NOT AVAILABLE</strong>',
    'not a currency, never revenue',
    'Not revenue.',
    'revenue_history_unavailable',
];

function forbiddenOccurrences(string $html): array
{
    $body = substr($html, (int) strpos($html, '<main'));
    $body = str_replace(ALLOWED_STATUS_PHRASES, '', $body);
    $found = [];

    foreach (FORBIDDEN_TERMS as $term) {
        $offset = 0;
        while (($pos = stripos($body, $term, $offset)) !== false) {
            $found[] = $term.' → '.trim(preg_replace('/\s+/', ' ', strip_tags(substr($body, max(0, $pos - 60), 140))));
            $offset = $pos + strlen($term);
        }
    }

    return $found;
}

/** Every metric label (card label / table header) and the value that follows it. */
function metricLabels(string $html): array
{
    preg_match_all('/<p class="text-\[11px\] text-slate-500">([^<]+)<\/p>\s*<p[^>]*>([^<]*)<\/p>/u', $html, $cards, PREG_SET_ORDER);
    preg_match_all('/<th[^>]*>([^<]+)<\/th>/u', $html, $headers);

    return [...array_map(fn ($m) => trim($m[1]), $cards), ...array_map('trim', $headers[1])];
}

it('overview, close history and close detail carry no forbidden metric name outside the enumerated status statements, and no metric label uses one', function () {
    $fx = closableMonth();
    e1Payment($fx['subscriber'], ['amount' => '50.00', 'currency' => 'EUR', 'receivedAt' => CarbonImmutable::parse('2026-08-21', 'UTC')]); // blockers on the live month too
    foreach (['communication' => 'meta-whatsapp', 'external' => 'none-declared'] as $component => $cp) { // an empty July: CONFIRMED ZERO ⇒ closable
        e2Reconcile([], ['component' => $component, 'counterpartyKey' => $cp, 'month' => '2026-07', 'source' => 'confirmed_zero', 'reasonCode' => 'none', 'evidenceRef' => 'att', 'typedConfirmation' => 'ZERO']);
    }
    $close = closeMonth('2026-07', null, 'k-july'); // a frozen month as well
    $user = userWithRole(Role::Finance);

    $pages = [
        'overview' => $this->actingAs($user)->get(route('dashboard.finance', ['from' => '2026-07-01', 'to' => '2026-09-06']))->assertOk()->getContent(),
        'close history' => $this->actingAs($user)->get(route('dashboard.finance.close', ['month' => '2026-08']))->assertOk()->getContent(),
        'close detail' => $this->actingAs($user)->get(route('dashboard.finance.close.show', $close->id))->assertOk()->getContent(),
    ];

    foreach ($pages as $name => $html) {
        expect(forbiddenOccurrences($html))->toBe([], $name);
        foreach (metricLabels($html) as $label) {
            foreach (FORBIDDEN_TERMS as $term) {
                expect(stripos($label, $term))->toBeFalse($name.' label: '.$label);
            }
        }
        expect($html)->toContain('Reconciled Cash Contribution'); // the one cash-basis name, and it is never called profit
    }
});

it('source level: the finance views name no card or column after a forbidden term', function () {
    foreach (['livewire/dashboard/finance.blade.php', 'livewire/dashboard/finance/period-close.blade.php', 'livewire/dashboard/finance/close-detail.blade.php', 'components/finance/banners.blade.php'] as $view) {
        $src = file_get_contents(resource_path('views/'.$view));
        preg_match_all('/<p class="text-\[11px\] text-slate-500">([^<{]+)<\/p>|<th[^>]*>([^<{]+)<\/th>/u', $src, $m);
        foreach ([...$m[1], ...$m[2]] as $label) {
            foreach (FORBIDDEN_TERMS as $term) {
                expect(stripos($label, $term))->toBeFalse($view.' label: '.$label);
            }
        }
        expect(preg_match('/data-testid="gross-margin"/', $src))->toBe(0);
    }
});

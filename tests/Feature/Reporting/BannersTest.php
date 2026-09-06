<?php

declare(strict_types=1);

use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Services\Payments\CustomerPaymentService;
use App\Services\Reconciliation\CostInvoiceService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E5.1 — the shared <x-finance.banners> component surfaces the
 * existing service states on the surfaces where they apply, with the
 * services' own wording and never as a 0:
 *  - CASH band: FEES UNKNOWN (CashSummary::feesUnknownCount), NOT CONVERTED
 *    (ReportingTotal::notConverted);
 *  - RECONCILED band, live month: LEDGER MOVED SINCE RECONCILIATION and
 *    EVIDENCE VOIDED / SUPERSEDED (ScopeSummary::flags) as warnings, and the
 *    preflight blockers (FEES_INCOMPLETE, FX_INCOMPLETE_CASH, LEDGER_MOVED,
 *    EVIDENCE_STALE, UNRESOLVED_DISPUTES …) as BLOCKING;
 *  - close history page: the same preflight blockers; a frozen month / close
 *    detail: the frozen conditions marked FROZEN.
 * No banner logic of its own: it renders what the services returned.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function bannersOf(string $html, string $testid): array
{
    $start = strpos($html, 'data-testid="'.$testid.'"');
    if ($start === false) {
        return [];
    }
    $block = substr($html, $start, (int) strpos($html, '</div>'."\n".'    </div>', $start) - $start + 1);
    preg_match_all('/data-banner="(blocking|warning|info)">([^<]+)</u', substr($html, $start), $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $match) {
        $out[] = $match[1].': '.html_entity_decode($match[2]);
    }

    return $out;
}

it('shows FEES UNKNOWN, NOT CONVERTED, LEDGER MOVED, EVIDENCE VOIDED, UNRESOLVED_DISPUTES and the close blockers on the overview and the close page, with the service wording and never a 0', function () {
    $fx = closableMonth();
    $eur = e1Payment($fx['subscriber'], ['amount' => '50.00', 'currency' => 'EUR', 'receivedAt' => CarbonImmutable::parse('2026-08-21', 'UTC')]); // fee NULL + NOT CONVERTED
    app(CustomerPaymentService::class)->transition($fx['usd'], CustomerPaymentEventType::Disputed, $fx['usd']->stateToken(), PaymentSource::Gateway, 'chargeback');
    financeRow(['provider' => 'groq', 'provider_cost' => '1.000000', 'total_cost' => '1.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-20', 'UTC')]); // ledger moved
    app(CostInvoiceService::class)->void($fx['invoice']->id, $fx['invoice']->fresh()->stateToken(), 'duplicate'); // evidence voided
    $user = userWithRole(Role::Finance);

    $html = $this->actingAs($user)->get(route('dashboard.finance', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk()->getContent();
    $cash = bannersOf($html, 'cash-banners');
    $month = bannersOf($html, 'month-banners-2026-08');

    expect($cash)->toContain('warning: WARNING · FEES UNKNOWN · EUR: 1 of 1 payments — Net Cash After Gateway Fees NOT AVAILABLE (never 0)')
        ->and(implode("\n", $cash))->toContain('NOT CONVERTED · Gross Cash Collected: 1 of 3 lines have no current frozen conversion to USD — total INCOMPLETE / NOT AVAILABLE')
        ->and(implode("\n", $month))->toContain('blocking: BLOCKING · FEES_INCOMPLETE (payments:'.$eur->id.')')
        ->toContain('BLOCKING · FX_INCOMPLETE_CASH (payment:'.$eur->id.')')
        ->toContain('BLOCKING · UNRESOLVED_DISPUTES (payments:'.$fx['usd']->id.')')
        ->toContain('BLOCKING · LEDGER_MOVED (reconciliation:'.$fx['reconciliation']->id.')')
        ->toContain('BLOCKING · EVIDENCE_STALE (reconciliation:'.$fx['reconciliation']->id.' EVIDENCE VOIDED (#'.$fx['invoice']->id.'))')
        ->toContain('warning: WARNING · LEDGER MOVED SINCE RECONCILIATION · provider:groq (reconciliation:'.$fx['reconciliation']->id.')')
        ->toContain('warning: WARNING · EVIDENCE VOIDED (#'.$fx['invoice']->id.') · provider:groq (reconciliation:'.$fx['reconciliation']->id.')')
        ->toContain('info: INFO · CONFIRMED_ZERO (communication:meta-whatsapp)');

    // The blocked figures are NOT AVAILABLE, never 0.
    $row = substr($html, strpos($html, 'data-testid="month-2026-08"'), (int) strpos($html, '</tr>', strpos($html, 'data-testid="month-2026-08"')) - strpos($html, 'data-testid="month-2026-08"'));
    expect(substr_count($row, 'NOT AVAILABLE'))->toBeGreaterThanOrEqual(3)->and($row)->not->toContain('>0.000000<')->not->toContain('>0.00<');

    // The close page shows the same blockers through the same component.
    $close = $this->actingAs($user)->get(route('dashboard.finance.close', ['month' => '2026-08']))->assertOk()->getContent();
    $preflight = bannersOf($close, 'preflight-banners');
    expect(implode("\n", $preflight))->toContain('BLOCKING · FEES_INCOMPLETE')->toContain('BLOCKING · UNRESOLVED_DISPUTES')->toContain('BLOCKING · LEDGER_MOVED')->toContain('BLOCKING · EVIDENCE_STALE')->toContain('BLOCKING · FX_INCOMPLETE_CASH');
});

it('shows nothing artificial: a clean month has no blocking or warning banner, only the informational conditions; a frozen month shows its frozen conditions marked FROZEN', function () {
    closableMonth();
    $user = userWithRole(Role::Finance);

    $html = $this->actingAs($user)->get(route('dashboard.finance', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk()->getContent();
    expect(bannersOf($html, 'cash-banners'))->toBe([])
        ->and(array_filter(bannersOf($html, 'month-banners-2026-08'), fn ($b) => ! str_starts_with($b, 'info:')))->toBe([])
        ->and(bannersOf($html, 'month-banners-2026-08'))->toContain('info: INFO · CONFIRMED_ZERO (communication:meta-whatsapp)');

    $this->actingAs(userWithRole(Role::SuperAdmin));
    $close = closeMonth('2026-08', null, 'k1');
    $html = $this->actingAs($user)->get(route('dashboard.finance', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk()->getContent();
    expect(bannersOf($html, 'month-banners-2026-08'))->toContain('info: FROZEN · CONFIRMED_ZERO (communication:meta-whatsapp)')
        ->and(array_filter(bannersOf($html, 'month-banners-2026-08'), fn ($b) => ! str_starts_with($b, 'info: FROZEN')))->toBe([]);

    $detail = $this->actingAs($user)->get(route('dashboard.finance.close.show', $close->id))->assertOk()->getContent();
    expect(bannersOf($detail, 'frozen-banners'))->toContain('info: FROZEN · CONFIRMED_ZERO (communication:meta-whatsapp)')->toContain('info: FROZEN · CALCULATED_COVERAGE_PARTIAL (external:none-declared no_producer)');
});

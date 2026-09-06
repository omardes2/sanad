<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\PaymentDetail;
use App\Livewire\Dashboard\Finance\Payments;
use App\Livewire\Dashboard\Finance\RefundDetail;
use App\Livewire\Dashboard\Finance\Refunds;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Phase E5.2a — render = read-only, at the wire: listing, changing filters,
 * paginating, opening a payment detail, opening a refund detail and opening
 * a confirmation panel issue NO INSERT / UPDATE / DELETE / DDL on any table.
 * Writes happen only after an explicit action submit.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('issues no write statement while rendering, filtering, paginating, opening details and opening confirmation panels', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $this->actingAs($finance);
    $writes = [];
    DB::listen(function (QueryExecuted $q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $q->sql) === 1) {
            $writes[] = $q->sql;
        }
    });

    $this->get(route('dashboard.finance.payments', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk();
    Livewire::actingAs($finance)->test(Payments::class, ['from' => '2026-08-01', 'to' => '2026-08-31'])->set('currency', 'ILS')->set('fee', 'known')->set('status', 'succeeded')->call('gotoPage', 2)->call('gotoPage', 1)->assertOk();
    $this->get(route('dashboard.finance.payments.show', $fx['usd']->id))->assertOk();
    Livewire::actingAs($finance)->test(PaymentDetail::class, ['payment' => $fx['usd']])->call('openConfirm', 'dispute')->call('openConfirm', 'refund')->call('openConfirm', 'allocate')->call('closeConfirm')->assertOk();
    $this->get(route('dashboard.finance.refunds', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk();
    Livewire::actingAs($finance)->test(Refunds::class, ['from' => '2026-08-01', 'to' => '2026-08-31'])->set('currency', 'USD')->call('gotoPage', 1)->assertOk();
    $this->get(route('dashboard.finance.refunds.show', $fx['refund']->id))->assertOk();
    Livewire::actingAs($finance)->test(RefundDetail::class, ['refund' => $fx['refund']])->call('openConfirm', 'allocate')->call('closeConfirm')->assertOk();

    expect($writes)->toBe([]);
});

it('source level: the payment pages call no model write and no query-builder write; every write goes through the E1 services', function () {
    foreach (['Livewire/Dashboard/Finance/Payments.php', 'Livewire/Dashboard/Finance/PaymentDetail.php', 'Livewire/Dashboard/Finance/Refunds.php', 'Livewire/Dashboard/Finance/RefundDetail.php', 'Livewire/Dashboard/Finance/Concerns/HandlesPaymentActions.php', 'Services/Payments/PaymentLedgerView.php'] as $file) {
        $src = php_strip_whitespace(app_path($file));
        expect(preg_match('/->(create|update|delete|save|insert|forceFill|upsert|increment|decrement|touch|truncate)\(/', $src))->toBe(0, $file)
            ->and(preg_match('/DB::(statement|unprepared|insert|update|delete|table)/', $src))->toBe(0, $file);
    }
    $detail = php_strip_whitespace(app_path('Livewire/Dashboard/Finance/PaymentDetail.php'));
    expect(preg_match_all('/\$service->(transition|record|allocatePayment)\(/', $detail))->toBe(3); // the only write paths: the E1 services
});

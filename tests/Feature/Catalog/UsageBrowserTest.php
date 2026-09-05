<?php

declare(strict_types=1);

use App\Enums\CostSource;
use App\Livewire\Dashboard\Usage as UsagePage;
use App\Models\UsageEvent;
use App\Services\Usage\UsageQuery;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function usageLedger(): void
{
    $base = ['occurred_at' => now()->subDay(), 'provider' => 'groq', 'model' => 'llama', 'operation' => 'chat', 'channel' => 'whatsapp', 'outcome' => 'succeeded', 'currency' => 'USD', 'subscriber_id' => 7];

    UsageEvent::factory()->create(array_merge($base, ['cost_source' => CostSource::ModelPrice, 'total_cost' => '0.100000', 'provider_cost' => '0.100000', 'cost' => '0.100000']));
    UsageEvent::factory()->create(array_merge($base, ['cost_source' => CostSource::ConfigRate, 'total_cost' => '0.250000', 'provider_cost' => '0.250000', 'cost' => '0.250000']));
    UsageEvent::factory()->create(array_merge($base, ['cost_source' => CostSource::None, 'total_cost' => '0', 'provider_cost' => '0', 'cost' => '0']));
    UsageEvent::factory()->create(array_merge($base, ['cost_source' => CostSource::CurrencyMismatch, 'total_cost' => '0', 'provider_cost' => '0', 'cost' => '0']));
    UsageEvent::factory()->create(array_merge($base, ['cost_source' => null, 'total_cost' => '9.990000', 'provider_cost' => '9.99', 'cost' => '9.99'])); // pre-ledger row: cost columns are NOT trusted
    UsageEvent::factory()->create(array_merge($base, ['cost_source' => CostSource::ModelPrice, 'total_cost' => '0.500000', 'provider_cost' => '0.5', 'cost' => '0.5', 'occurred_at' => now()->subDays(60)])); // outside the default window
}

it('sums only priced rows and counts unpriced rows by reason inside the window', function () {
    usageLedger();
    [$from, $to] = UsageQuery::window(now()->subDays(29)->format('Y-m-d'), now()->format('Y-m-d'));

    $totals = UsageQuery::totals(UsageQuery::build($from, $to));

    expect($totals['rows'])->toBe(5)
        ->and($totals['priced_rows'])->toBe(2)
        ->and($totals['priced_total'])->toBe('0.350000')
        ->and($totals['unpriced_rows'])->toBe(3)
        ->and($totals['unpriced_by_reason'])->toBe(['currency_mismatch' => 1, 'legacy' => 1, 'none' => 1]);
});

it('requires an explicit, bounded window', function () {
    expect(fn () => UsageQuery::window('', '2026-01-31'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => UsageQuery::window('2026-02-01', '2026-01-31'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => UsageQuery::window('2026-01-01', '2026-06-01'))->toThrow(InvalidArgumentException::class, (string) UsageQuery::MAX_DAYS)
        ->and(UsageQuery::window('2026-01-01', '2026-01-01')[1]->toIso8601String())->toBe(CarbonImmutable::parse('2026-01-02T00:00:00', config('app.timezone'))->toIso8601String());
});

it('shows money and the export link only to accounts that may see costs / export', function () {
    usageLedger();

    Livewire::actingAs(userWithRole(Role::Support))
        ->test(UsagePage::class)
        ->assertOk()
        ->assertSee('غير مسعّرة')
        ->assertDontSee('0.350000')
        ->assertDontSee('تصدير CSV')
        ->assertDontSee('>التكلفة</th>', false);

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(UsagePage::class)
        ->assertDontSee('0.350000')
        ->assertDontSee('تصدير CSV');

    Livewire::actingAs(userWithRole(Role::Finance))
        ->test(UsagePage::class)
        ->assertSee('0.350000 USD')
        ->assertSee('تصدير CSV')
        ->assertSee(route('dashboard.usage.export', [], false))
        ->assertSee('صفوف سابقة لدفتر الأسعار')
        ->assertDontSee('9.990000');
});

it('filters by cost status, provider and subscriber and prefills a 30-day window', function () {
    usageLedger();

    Livewire::actingAs(userWithRole(Role::Finance))
        ->test(UsagePage::class)
        ->assertSet('from', now()->subDays(29)->format('Y-m-d'))
        ->assertSet('to', now()->format('Y-m-d'))
        ->set('cost', 'unpriced')
        ->assertSee('3')
        ->assertDontSee('0.350000')
        ->set('cost', 'priced')
        ->set('provider', 'openai')
        ->assertSee('لا توجد أحداث')
        ->set('provider', 'groq')
        ->set('subscriber_id', '7')
        ->assertSee('0.350000')
        ->set('from', '2020-01-01')
        ->assertSee((string) UsageQuery::MAX_DAYS);
});

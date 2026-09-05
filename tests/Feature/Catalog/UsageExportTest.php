<?php

declare(strict_types=1);

use App\Enums\CostSource;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Usage\UsageExporter;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function exportUrl(array $params = []): string
{
    return route('dashboard.usage.export', $params + ['from' => now()->subDays(29)->format('Y-m-d'), 'to' => now()->format('Y-m-d')]);
}

function exportBody($response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('is reachable only by super_admin and finance (fail closed for everyone else)', function () {
    $this->get(exportUrl())->assertRedirect(route('login'));

    rbacSync();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(exportUrl())->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get(exportUrl())->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(exportUrl())->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(exportUrl())->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get(exportUrl())->assertOk();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(exportUrl())->assertOk();
});

it('requires an explicit bounded window', function () {
    $finance = userWithRole(Role::Finance);

    $this->actingAs($finance)->get(route('dashboard.usage.export'))->assertSessionHasErrors(['from', 'to']);
    $this->actingAs($finance)->get(route('dashboard.usage.export', ['from' => '2026-01-01', 'to' => '2026-06-30']))->assertStatus(422);
    $this->actingAs($finance)->get(route('dashboard.usage.export', ['from' => '2026-02-01', 'to' => '2026-01-01']))->assertStatus(422);
});

it('streams a CSV with the ledger columns and no personal data, cost columns only with usage.view_costs', function () {
    $subscriber = User::factory()->create(['is_admin' => false, 'name' => 'Ahmad Secret', 'email' => 'ahmad@example.com', 'phone' => '+970599123456']);
    UsageEvent::factory()->create([
        'user_id' => $subscriber->id, 'subscriber_id' => $subscriber->id, 'occurred_at' => now()->subHour(),
        'provider' => 'groq', 'model' => 'llama', 'operation' => 'chat', 'channel' => 'whatsapp', 'outcome' => 'succeeded',
        'cost_source' => CostSource::ModelPrice, 'total_cost' => '0.123456', 'currency' => 'USD', 'input_units' => 10, 'output_units' => 5,
        'metadata' => ['text' => 'MESSAGE BODY MUST NOT LEAK', 'phone' => '+970599123456'],
    ]);
    UsageEvent::factory()->create(['subscriber_id' => $subscriber->id, 'occurred_at' => now()->subHour(), 'cost_source' => null, 'total_cost' => '5', 'metadata' => null]);

    $response = $this->actingAs(userWithRole(Role::Finance))->get(exportUrl());
    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('content-disposition'))->toContain('sanad-usage-');

    $body = exportBody($response->baseResponse);
    $lines = array_values(array_filter(explode("\n", trim($body))));

    expect($lines)->toHaveCount(3)
        ->and(str_replace("\xEF\xBB\xBF", '', $lines[0]))->toBe(implode(',', UsageExporter::columns(true)))
        ->and($lines[1])->toContain(','.$subscriber->id.',')
        ->and($lines[1])->toContain('model_price,yes,')
        ->and($lines[1])->toContain('0.123456')
        ->and($lines[2])->toContain(',no,')
        ->and($body)->not->toContain('Ahmad')
        ->and($body)->not->toContain('ahmad@example.com')
        ->and($body)->not->toContain('970599')
        ->and($body)->not->toContain('MESSAGE BODY');

    // Without usage.view_costs (super_admin bypasses everything, so revoke on finance): no cost columns at all.
    $finance = userWithRole(Role::Finance);
    Spatie\Permission\Models\Role::findByName(Role::Finance->value)->revokePermissionTo('usage.view_costs');
    $body = exportBody($this->actingAs($finance->fresh())->get(exportUrl())->baseResponse);

    expect(str_replace("\xEF\xBB\xBF", '', strtok($body, "\n")))->toBe(implode(',', UsageExporter::columns(false)))
        ->and($body)->not->toContain('0.123456')
        ->and($body)->not->toContain('total_cost');
});

it('honours the same filters as the page', function () {
    UsageEvent::factory()->create(['occurred_at' => now()->subHour(), 'provider' => 'groq', 'cost_source' => CostSource::None]);
    UsageEvent::factory()->create(['occurred_at' => now()->subHour(), 'provider' => 'openai', 'cost_source' => CostSource::ModelPrice]);

    $body = exportBody($this->actingAs(userWithRole(Role::Finance))->get(exportUrl(['cost' => 'unpriced']))->baseResponse);
    $lines = array_values(array_filter(explode("\n", trim($body))));

    expect($lines)->toHaveCount(2)->and($lines[1])->toContain('groq');
});

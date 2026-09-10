<?php

declare(strict_types=1);

use App\Enums\MemoryCategory;
use App\Enums\ToolCapability;
use App\Enums\ToolInvocationStatus;
use App\Models\Memory;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Memory\MemoryOperationsQuery;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Memory content and fingerprints never reach a rendered page
|--------------------------------------------------------------------------
*/

it('never renders memory content or a fingerprint on any memory page', function () {
    $subscriber = User::factory()->create();
    $secret = 'سرٌّ لا يجوز أن يظهر في أي صفحة إدارية';

    $memory = Memory::factory()->for($subscriber, 'user')->create([
        'category' => MemoryCategory::Preference,
        'content' => $secret,
    ]);

    // The row really does hold a sealed envelope and a fingerprint.
    $raw = DB::table('memories')->where('id', $memory->id)->first();
    expect($raw->fingerprint)->not->toBeNull()
        ->and($raw->content)->not->toContain($secret);

    $admin = userWithRole(Role::SuperAdmin);

    foreach ([route('dashboard.memory'), route('dashboard.memory.subscriber', $subscriber)] as $url) {
        $response = $this->actingAs($admin)->get($url);

        $response->assertOk()
            ->assertDontSee($secret)
            // Neither the plaintext nor the ciphertext nor the MAC.
            ->assertDontSee($raw->fingerprint)
            ->assertDontSee($raw->content);
    }
});

it('cannot even select memory content or fingerprint through the operations query', function () {
    expect(MemoryOperationsQuery::SELECTS)->not->toContain('content')
        ->and(MemoryOperationsQuery::SELECTS)->not->toContain('fingerprint');

    $subscriber = User::factory()->create();
    Memory::factory()->for($subscriber, 'user')->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    MemoryOperationsQuery::rowsFor($subscriber);
    MemoryOperationsQuery::summaryFor($subscriber);
    MemoryOperationsQuery::overview();
    MemoryOperationsQuery::subscribers();

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $sql) {
        expect($sql)->not->toContain('content')
            ->and($sql)->not->toContain('fingerprint');
    }
});

it('says plainly that no memory content is shown', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->get(route('dashboard.memory'))
        ->assertOk()
        ->assertSee('لا تعرض هذه الصفحة محتوى أي ذاكرة');
});

/*
|--------------------------------------------------------------------------
| Secrets never reach the readiness board
|--------------------------------------------------------------------------
*/

it('never prints a key, a token or a secret on the readiness board', function () {
    config()->set('services.whatsapp.access_token', 'whatsapp-access-token-value');
    config()->set('services.whatsapp.app_secret', 'whatsapp-app-secret-value');
    config()->set('services.whatsapp.verify_token', 'whatsapp-verify-token-value');

    $response = $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.launch'));

    $response->assertOk()
        ->assertDontSee('whatsapp-access-token-value')
        ->assertDontSee('whatsapp-app-secret-value')
        ->assertDontSee('whatsapp-verify-token-value')
        ->assertDontSee((string) config('memory.key'))
        ->assertDontSee((string) config('memory.fingerprint_key'))
        ->assertDontSee((string) config('app.key'))
        ->assertDontSee('base64:');
});

/*
|--------------------------------------------------------------------------
| Claim tokens are fencing, not diagnostics
|--------------------------------------------------------------------------
*/

it('never renders a reminder claim token, even with the delivery permission', function () {
    $subscriber = User::factory()->create();
    $token = '11111111-2222-3333-4444-555555555555';

    $reminder = Reminder::factory()->for($subscriber)->create([
        'claim_token' => $token,
        'claimed_at' => now()->subMinute(),
    ]);

    // Operations holds reminders.delivery.view — the most privileged view there is.
    $admin = userWithRole(Role::Operations);

    $this->actingAs($admin)->get(route('dashboard.reminders'))->assertOk()->assertDontSee($token);
    $this->actingAs($admin)->get(route('dashboard.reminders.show', $reminder))->assertOk()->assertDontSee($token);

    // super_admin passes every gate and still must not see it.
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->get(route('dashboard.reminders.show', $reminder))
        ->assertOk()
        ->assertDontSee($token);
});

/*
|--------------------------------------------------------------------------
| A redacted tool output is labelled as metadata, never as the result
|--------------------------------------------------------------------------
*/

it('labels a redacted invocation output as storage metadata and not as the tool result', function () {
    $subscriber = User::factory()->create();

    $invocation = admInvocation($subscriber, [
        'status' => ToolInvocationStatus::Succeeded->value,
        'output' => ['memories_count' => 3, 'truncated' => false],
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
        'duration_ms' => 12,
        'version' => 4,
    ]);

    $this->actingAs(userWithRole(Role::Operations))
        ->get(route('dashboard.tools.invocations.show', $invocation))
        ->assertOk()
        ->assertSee('بيانات وصفية عن المخرَج (ليست نتيجة الأداة)')
        ->assertSee('هذا ليس ما أعادته الأداة للنموذج')
        ->assertDontSee('مخرَج الأداة');
});

it('labels a non-redacted tool output as the tool output', function () {
    $subscriber = User::factory()->create();

    $invocation = admInvocation($subscriber, [
        'tool_key' => 'task.list',
        'tool_version' => 1,
        'capability' => ToolCapability::TasksWrite->value,
        'status' => ToolInvocationStatus::Succeeded->value,
        'output' => ['tasks' => [], 'truncated' => false],
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
        'duration_ms' => 5,
        'version' => 4,
    ]);

    $this->actingAs(userWithRole(Role::Operations))
        ->get(route('dashboard.tools.invocations.show', $invocation))
        ->assertOk()
        ->assertSee('مخرَج الأداة')
        ->assertDontSee('بيانات وصفية عن المخرَج');
});

it('states that raw tool arguments are never stored', function () {
    $invocation = admInvocation(User::factory()->create());

    $this->actingAs(userWithRole(Role::Operations))
        ->get(route('dashboard.tools.invocations.show', $invocation))
        ->assertOk()
        ->assertSee('وسائط الأدوات الخام لا تُحفظ أبدًا');
});

/*
|--------------------------------------------------------------------------
| Costs stay behind usage.view_costs
|--------------------------------------------------------------------------
*/

it('hides the cost card from a role without usage.view_costs', function () {
    // Support holds usage.view but not usage.view_costs.
    $this->actingAs(userWithRole(Role::Support))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('تكلفة المزوّد');

    $this->actingAs(userWithRole(Role::Finance))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('تكلفة المزوّد');
});

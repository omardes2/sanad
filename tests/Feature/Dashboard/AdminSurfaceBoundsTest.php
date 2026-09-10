<?php

declare(strict_types=1);

use App\Enums\ReminderStatus;
use App\Enums\ToolCapability;
use App\Models\Memory;
use App\Models\Reminder;
use App\Models\User;
use App\Support\Rbac\Role;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Capture every statement one request runs, split into reads and writes.
 *
 * @return array{reads: list<string>, writes: list<string>}
 */
function admQueries(callable $request): array
{
    $reads = [];
    $writes = [];

    DB::listen(function (QueryExecuted $q) use (&$reads, &$writes): void {
        $sql = ltrim($q->sql);

        preg_match('/^(insert|update|delete|truncate|alter|drop|create)\b/i', $sql) === 1
            ? $writes[] = $sql
            : $reads[] = $sql;
    });

    $request();

    return ['reads' => $reads, 'writes' => $writes];
}

/*
|--------------------------------------------------------------------------
| Every admin page is READ-ONLY
|--------------------------------------------------------------------------
*/

it('writes nothing when rendering any admin page', function (string $route) {
    $subscriber = User::factory()->create();
    admInvocation($subscriber);
    admConsent($subscriber, ToolCapability::MemoryRead);
    Memory::factory()->for($subscriber, 'user')->create();
    Reminder::factory()->for($subscriber)->create(['status' => ReminderStatus::Sent]);

    $admin = userWithRole(Role::SuperAdmin);

    $captured = admQueries(function () use ($admin, $route, $subscriber) {
        $url = in_array($route, ['dashboard.memory.subscriber'], true)
            ? route($route, $subscriber)
            : route($route);

        $this->actingAs($admin)->get($url)->assertOk();
    });

    // Session writes are the framework's, not the page's.
    $pageWrites = array_values(array_filter(
        $captured['writes'],
        static fn (string $sql): bool => ! str_contains($sql, 'sessions'),
    ));

    expect($pageWrites)->toBe([]);
})->with([
    'dashboard',
    'dashboard.launch',
    'dashboard.tools.invocations',
    'dashboard.tools.consents',
    'dashboard.memory',
    'dashboard.memory.subscriber',
    'dashboard.reminders',
]);

/*
|--------------------------------------------------------------------------
| Bounded query counts
|--------------------------------------------------------------------------
*/

it('keeps the overview query count flat as the data grows', function () {
    $admin = userWithRole(Role::SuperAdmin);

    $count = function () use ($admin): int {
        return count(admQueries(function () use ($admin) {
            $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        })['reads']);
    };

    // The FIRST request of a test warms the permission cache and creates the
    // session, so it runs strictly more queries than every request after it.
    // Measuring against it would compare warm-up to steady state and always
    // "pass" for the wrong reason — so the baseline is taken warm.
    $count();
    $small = $count();

    // Twenty subscribers with rows in every table the board reads.
    User::factory()->count(20)->create()->each(function (User $subscriber): void {
        admInvocation($subscriber);
        Reminder::factory()->for($subscriber)->create(['status' => ReminderStatus::Sent, 'sent_at' => now()]);
        Memory::factory()->for($subscriber, 'user')->create();
    });

    expect($count())->toBe($small);
});

it('keeps the readiness board query count flat as the data grows', function () {
    $admin = userWithRole(Role::SuperAdmin);

    $count = function () use ($admin): int {
        return count(admQueries(function () use ($admin) {
            $this->actingAs($admin)->get(route('dashboard.launch'))->assertOk();
        })['reads']);
    };

    $count();
    $small = $count();

    User::factory()->count(15)->create()->each(function (User $subscriber): void {
        admInvocation($subscriber);
        Reminder::factory()->for($subscriber)->create(['status' => ReminderStatus::Failed, 'last_error' => 'too_late']);
    });

    expect($count())->toBe($small);
});

it('keeps the invocation list query count flat as rows grow', function () {
    $admin = userWithRole(Role::Operations);
    $subscriber = User::factory()->create();

    $count = function () use ($admin): int {
        return count(admQueries(function () use ($admin) {
            $this->actingAs($admin)->get(route('dashboard.tools.invocations'))->assertOk();
        })['reads']);
    };

    admInvocation($subscriber);
    $count();
    $small = $count();

    // Many rows, and several subscribers, to rule out an N+1 on the relation.
    for ($i = 0; $i < 25; $i++) {
        admInvocation(User::factory()->create());
    }

    expect($count())->toBe($small);
});

it('keeps the memory pages query count flat as subscribers grow', function () {
    $admin = userWithRole(Role::Operations);

    $count = function () use ($admin): int {
        return count(admQueries(function () use ($admin) {
            $this->actingAs($admin)->get(route('dashboard.memory'))->assertOk();
        })['reads']);
    };

    Memory::factory()->for(User::factory(), 'user')->create();
    $count();
    $small = $count();

    for ($i = 0; $i < 20; $i++) {
        Memory::factory()->for(User::factory(), 'user')->count(2)->create();
    }

    expect($count())->toBe($small);
});

it('keeps the reminder list query count flat as rows grow', function () {
    $admin = userWithRole(Role::Operations);

    $count = function () use ($admin): int {
        return count(admQueries(function () use ($admin) {
            $this->actingAs($admin)->get(route('dashboard.reminders'))->assertOk();
        })['reads']);
    };

    Reminder::factory()->for(User::factory())->create(['status' => ReminderStatus::Sent]);
    $count();
    $small = $count();

    for ($i = 0; $i < 20; $i++) {
        Reminder::factory()->for(User::factory())->create(['status' => ReminderStatus::Sent]);
    }

    expect($count())->toBe($small);
});

/*
|--------------------------------------------------------------------------
| Windows are mandatory, not optional
|--------------------------------------------------------------------------
*/

it('never issues an unbounded scan of tool_invocations from a page', function () {
    $admin = userWithRole(Role::Operations);
    admInvocation(User::factory()->create());

    $captured = admQueries(function () use ($admin) {
        $this->actingAs($admin)->get(route('dashboard.tools.invocations'))->assertOk();
    });

    $touching = array_values(array_filter(
        $captured['reads'],
        static fn (string $sql): bool => str_contains($sql, 'tool_invocations'),
    ));

    expect($touching)->not->toBeEmpty();

    foreach ($touching as $sql) {
        // Every read of this table carries the created_at window.
        expect($sql)->toContain('created_at');
    }
});

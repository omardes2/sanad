<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Services\Tools\StaleInvocationSweeper;
use App\Services\Tools\ToolConsentService;
use App\Services\Tools\ToolInvocationStore;
use App\Support\Audit\AuditActions;
use App\Support\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase F2 — crash recovery for synchronous reads.
 *
 * F2 guarantees exactly-once CLAIM and exactly-once TERMINAL RECORD, and
 * at-most-once EXECUTION under normal process execution. It does not pretend to
 * distributed exactly-once: if a process dies after the read ran but before the
 * terminal transaction committed, Sanad cannot know whether the read completed.
 * The invocation stays `running`, this sweeper later settles it as `timed_out`,
 * and nothing ever re-executes it under the same identity.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'UTC'));
    $this->subscriber = User::factory()->create();

    auth()->setUser($this->subscriber);
    app(ToolConsentService::class)->grant($this->subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();
});

/** An invocation abandoned in `running`, exactly as a killed process would leave it. */
function f2Abandoned(User $subscriber): ToolInvocation
{
    $request = f2Plan()->one(f2Message($subscriber), 'memory.read@1', ['query' => 'coffee']);

    return f2Store()->begin(f2Store()->authorize(f2Store()->claim($request)->invocation), fn (): bool => true);
}

function f2Sweeper(int $grace = StaleInvocationSweeper::GRACE_SECONDS): StaleInvocationSweeper
{
    return new StaleInvocationSweeper(app(ToolInvocationStore::class), app(ToolRegistry::class), $grace);
}

it('leaves a running invocation alone until its own tool version says it cannot still be running', function () {
    $row = f2Abandoned($this->subscriber);
    $timeout = app(ToolRegistry::class)->require('memory.read', 1)->timeoutMs;

    // Inside the declared budget: nothing is touched.
    expect(f2Sweeper()->sweep())->toBe(0);

    $this->travelTo(CarbonImmutable::now('UTC')->addMilliseconds($timeout)->addSeconds(5));
    expect(f2Sweeper()->sweep())->toBe(0); // the grace has not passed either

    $this->travelTo(CarbonImmutable::now('UTC')->addSeconds(StaleInvocationSweeper::GRACE_SECONDS + 1));
    expect(f2Sweeper()->sweep())->toBe(1)
        ->and($row->fresh()->status)->toBe(ToolInvocationStatus::TimedOut);
});

it('settles a stale read as timed_out exactly once: one event, one audit, one usage row, and never a success', function () {
    $row = f2Abandoned($this->subscriber);
    $this->travelTo(CarbonImmutable::now('UTC')->addHour());

    expect(f2Sweeper()->sweep())->toBe(1);

    $settled = $row->fresh();

    expect($settled->status)->toBe(ToolInvocationStatus::TimedOut)
        ->and($settled->failure_kind)->toBe(ToolInvocationFailureKind::Timeout)
        // It never retries the tool and never manufactures a result.
        ->and($settled->output)->toBeNull()
        ->and($settled->finished_at)->not->toBeNull()
        ->and($settled->events()->pluck('to_status')->map->value->all())->toBe(['planned', 'authorized', 'running', 'timed_out'])
        ->and($settled->events()->where('to_status', ToolInvocationStatus::TimedOut)->count())->toBe(1)
        ->and(f2Audits($settled))->toHaveCount(1)
        ->and(f2Audits($settled)->first()->action)->toBe(AuditActions::ToolInvocationTimedOut)
        // It HAD entered running, so it is metered once.
        ->and(f2Usage($settled))->toHaveCount(1);

    // Sweeping again changes nothing: a terminal state accepts no transition.
    expect(f2Sweeper()->sweep())->toBe(0)
        ->and($settled->fresh()->version)->toBe(4)
        ->and(f2Audits($settled))->toHaveCount(1)
        ->and(f2Usage($settled))->toHaveCount(1);
});

it('is not a retry mechanism: it never touches a non-read invocation, nor a version it cannot price a timeout from', function () {
    $row = f2Abandoned($this->subscriber);
    $this->travelTo(CarbonImmutable::now('UTC')->addHour());

    // A side-effect class F2 does not execute is never swept, whatever its age.
    $row->forceFill(['side_effect' => ToolSideEffect::ExternalWrite->value])->save();
    expect(f2Sweeper()->sweep())->toBe(0)
        ->and($row->fresh()->status)->toBe(ToolInvocationStatus::Running);

    // An unknown tool version gives no expiry to recompute, so it is left alone
    // rather than guessed at.
    $row->forceFill(['side_effect' => ToolSideEffect::Read->value, 'tool_version' => 99])->save();
    expect(f2Sweeper()->sweep())->toBe(0)
        ->and($row->fresh()->status)->toBe(ToolInvocationStatus::Running);

    $row->forceFill(['tool_version' => 1])->save();
    expect(f2Sweeper()->sweep())->toBe(1);
});

it('loses to the worker that settled first, and writes nothing when it does', function () {
    $row = f2Abandoned($this->subscriber);
    $this->travelTo(CarbonImmutable::now('UTC')->addHour());

    // The original worker commits its success before the sweeper gets there.
    f2Store()->succeed($row, ['matches' => 2, 'truncated' => false], 12);

    expect(f2Sweeper()->sweep())->toBe(0);

    $settled = $row->fresh();

    expect($settled->status)->toBe(ToolInvocationStatus::Succeeded)
        ->and($settled->output)->toBe(['matches' => 2, 'truncated' => false])
        ->and($settled->version)->toBe(4)
        ->and($settled->events()->count())->toBe(4)
        // Exactly one terminal state, one terminal event, one audit, one usage row.
        ->and($settled->events()->whereIn('to_status', [ToolInvocationStatus::Succeeded, ToolInvocationStatus::TimedOut])->count())->toBe(1)
        ->and(f2Audits($settled))->toHaveCount(1)
        ->and(f2Usage($settled))->toHaveCount(1);
});

it('sweeps a bounded batch and never the whole table', function () {
    for ($i = 0; $i < 4; $i++) {
        f2Abandoned($this->subscriber);
    }

    $this->travelTo(CarbonImmutable::now('UTC')->addHour());

    expect(f2Sweeper()->sweep(limit: 2))->toBe(2)
        ->and(ToolInvocation::query()->where('status', ToolInvocationStatus::Running->value)->count())->toBe(2)
        ->and(f2Sweeper()->sweep(limit: 999))->toBe(2)  // clamped to MAX_BATCH, still finishes the rest
        ->and(ToolInvocation::query()->where('status', ToolInvocationStatus::Running->value)->count())->toBe(0);
});

it('is console-only and refuses to reach back in time from the command line', function () {
    f2Abandoned($this->subscriber);

    // The command clamps a negative grace to zero: no operator can make the
    // sweeper settle an invocation that is still inside its budget.
    $this->artisan('sanad:tool-invocations-sweep', ['--grace' => -100000])
        ->expectsOutput('swept:0')
        ->assertSuccessful();

    $this->travelTo(CarbonImmutable::now('UTC')->addHour());

    $this->artisan('sanad:tool-invocations-sweep')
        ->expectsOutput('swept:1')
        ->assertSuccessful();
});

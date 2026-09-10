<?php

declare(strict_types=1);

use App\Enums\FollowUpAnswer;
use App\Enums\FollowUpStatus;
use App\Enums\ToolCapability;
use App\Enums\ToolSideEffect;
use App\Services\FollowUps\FollowUpService;
use App\Services\Tools\ReadToolExecutor;
use App\Services\Tools\WriteToolExecutor;
use App\Support\Tools\ToolIntentRequirements;
use App\Support\Tools\ToolKey;
use App\Support\Tools\ToolOutputPersistence;
use App\Support\Tools\ToolRegistry;
use App\Support\Tools\ToolWriteTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The four contracts, and the boundaries that stop a model from stepping outside
 * them.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    fuConfigure();
});

it('ships four follow-up tools with the right class, capability and approval shape', function () {
    $registry = app(ToolRegistry::class);

    $expected = [
        'follow_up.create@1' => [ToolSideEffect::Write, ToolCapability::FollowUpsWrite],
        'follow_up.list@1' => [ToolSideEffect::Read, ToolCapability::FollowUpsRead],
        'follow_up.resolve@1' => [ToolSideEffect::Write, ToolCapability::FollowUpsWrite],
        'follow_up.cancel@1' => [ToolSideEffect::Write, ToolCapability::FollowUpsWrite],
    ];

    foreach ($expected as $key => [$sideEffect, $capability]) {
        $definition = $registry->requireKey($key);

        expect($definition->sideEffect)->toBe($sideEffect, $key)
            ->and($definition->capability)->toBe($capability, $key)
            // Opening a loop writes one local row and sends nothing, so it is a
            // `write` rather than an `external_write` — the same reasoning that
            // made `reminder.create@2` replace the frozen `@1`.
            ->and($definition->requiresApproval)->toBeFalse($key);
    }

    // Reading is a SEPARATE capability from writing, so a deployment can offer
    // discovery without offering the licence to ask.
    expect(ToolCapability::FollowUpsRead)->not->toBe(ToolCapability::FollowUpsWrite);
});

it('lets no schema carry ownership, a timezone or a channel', function () {
    $registry = app(ToolRegistry::class);

    foreach (['follow_up.create@1', 'follow_up.list@1', 'follow_up.resolve@1', 'follow_up.cancel@1'] as $key) {
        $fields = array_map(
            static fn ($field): string => $field->name,
            $registry->requireKey($key)->input->fields,
        );

        foreach (['user_id', 'subscriber_id', 'owner_id', 'timezone', 'channel', 'phone', 'to'] as $forbidden) {
            expect(in_array($forbidden, $fields, true))->toBeFalse("{$key} must not accept {$forbidden}");
        }
    }
});

it('requires an absolute first ask time in the schema itself', function () {
    $create = app(ToolRegistry::class)->requireKey('follow_up.create@1');

    $time = collect($create->input->fields)->firstWhere('name', 'first_ask_at');

    // The schema cannot be satisfied without a time, so there is no path by which
    // a default becomes a proactive message at an hour nobody chose.
    expect($time)->not->toBeNull()
        ->and($time->required)->toBeTrue()
        ->and($time->type->value)->toBe('datetime');
});

it('declares an explicit-intent requirement on creation only', function () {
    expect(ToolIntentRequirements::required(ToolKey::of('follow_up.create', 1)))->toBeTrue()
        // Ending a loop is deliberately NOT gated: stopping unsolicited messages
        // is the safe direction, and a subscriber who phrases it oddly must still
        // be able to stop them.
        ->and(ToolIntentRequirements::required(ToolKey::of('follow_up.cancel', 1)))->toBeFalse()
        ->and(ToolIntentRequirements::required(ToolKey::of('follow_up.resolve', 1)))->toBeFalse()
        ->and(ToolIntentRequirements::required(ToolKey::of('follow_up.list', 1)))->toBeFalse();
});

it('routes each tool to its own executor, and declares its blast radius', function () {
    expect(in_array('follow_up.list@1', ReadToolExecutor::executableKeys(), true))->toBeTrue()
        ->and(in_array('follow_up.list@1', WriteToolExecutor::executableKeys(), true))->toBeFalse();

    foreach (['follow_up.create@1', 'follow_up.resolve@1', 'follow_up.cancel@1'] as $key) {
        expect(in_array($key, WriteToolExecutor::executableKeys(), true))->toBeTrue($key)
            ->and(in_array($key, ReadToolExecutor::executableKeys(), true))->toBeFalse($key);
    }

    // Creating touches the DEFINITION only: the ask rows are written by the
    // materialiser, which is not a tool and is not reachable from a model turn.
    expect(ToolWriteTargets::for(ToolKey::of('follow_up.create', 1)))->toBe(['follow_ups'])
        ->and(ToolWriteTargets::for(ToolKey::of('follow_up.resolve', 1)))->toBe(['follow_ups', 'reminders'])
        ->and(ToolWriteTargets::for(ToolKey::of('follow_up.cancel', 1)))->toBe(['follow_ups', 'reminders']);
});

it('stores only the SHAPE of a listing, never the subscriber\'s questions', function () {
    $key = ToolKey::of('follow_up.list', 1);

    // The questions reach the model for the turn and are re-derived on replay —
    // the same treatment `memory.read@2` gets, for the same reason.
    expect(ToolOutputPersistence::rehydratableOnReplay($key))->toBeTrue();

    $kept = ToolOutputPersistence::filter($key, [
        'follow_ups' => [['follow_up_id' => 1, 'question' => 'دفعت فاتورة الكهربا؟', 'status' => 'open']],
        'truncated' => false,
    ]);

    // SHAPE SURVIVES, CONTENT DOES NOT: the list becomes its own length, so the
    // audit trail still says how much was returned without saying what.
    expect(array_keys($kept))->toBe(['follow_ups_count', 'truncated'])
        ->and($kept['follow_ups_count'])->toBe(1)
        ->and(json_encode($kept, JSON_UNESCAPED_UNICODE))->not->toContain('الكهربا');
});

it('bounds the listing at the declared ceiling, however much is asked for', function () {
    [$user, , $conversation] = fuSubscriber();
    config(['follow_ups.max_open_per_subscriber' => 20, 'follow_ups.list_limit' => 3]);

    foreach (range(1, 5) as $i) {
        fuCreate($user, $conversation, ['question' => "سؤال {$i}"]);
    }

    $service = app(FollowUpService::class);

    $bounded = $service->list($user, ['limit' => 999]);

    expect($bounded['follow_ups'])->toHaveCount(3)
        // `truncated` is a FACT, measured by reading one row more than the bound.
        ->and($bounded['truncated'])->toBeTrue();

    $smaller = $service->list($user, ['limit' => 2]);

    expect($smaller['follow_ups'])->toHaveCount(2)
        ->and($smaller['truncated'])->toBeTrue();

    // The schema's ceiling is a code constant; configuration may only narrow it.
    $schema = app(ToolRegistry::class)->requireKey('follow_up.list@1')->output;
    $rows = collect($schema->fields)->firstWhere('name', 'follow_ups');

    expect($rows->max)->toBe(FollowUpService::LIST_MAX)
        ->and(FollowUpService::LIST_MAX)->toBeGreaterThanOrEqual(3);
});

it('lists only the caller\'s own loops, and live ones by default', function () {
    [$user, , $conversation] = fuSubscriber();
    [$other, , $otherConversation] = fuSubscriber();

    $mine = fuCreate($user, $conversation, ['question' => 'دفعت الفاتورة؟']);
    fuCreate($other, $otherConversation, ['question' => 'حكيت مع المدير؟']);

    $cancelled = fuCreate($user, $conversation, ['question' => 'راجعت الطبيب؟']);
    app(FollowUpService::class)->cancel($user, (int) $cancelled->getKey());

    $live = app(FollowUpService::class)->list($user);

    expect(array_column($live['follow_ups'], 'follow_up_id'))->toBe([(int) $mine->getKey()])
        ->and(array_column($live['follow_ups'], 'question'))->toBe(['دفعت الفاتورة؟']);

    // A closed loop is not something the subscriber can act on, so it is offered
    // only when asked for explicitly.
    $all = app(FollowUpService::class)->list($user, ['include_closed' => true]);

    expect($all['follow_ups'])->toHaveCount(2)
        ->and(array_column($all['follow_ups'], 'status'))
        ->toContain(FollowUpStatus::Cancelled->value);
});

it('reports the budget and the next ask in the subscriber\'s own wall clock', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuAwaiting($user, $conversation);

    $row = app(FollowUpService::class)->list($user)['follow_ups'][0];

    expect($row['asks_used'])->toBe(1)
        ->and($row['max_asks'])->toBe(3)
        ->and($row['status'])->toBe(FollowUpStatus::AwaitingAnswer->value)
        // Awaiting an answer means nothing else is scheduled, and the listing says
        // exactly that rather than inventing a time.
        ->and($row['next_ask_local'])->toBeNull();

    $reply = fuInbound($user, $conversation, 'لسا');
    app(FollowUpService::class)->resolve($user, (int) $followUp->getKey(), FollowUpAnswer::NotYet, $reply);

    $after = app(FollowUpService::class)->list($user)['follow_ups'][0];

    // Asia/Hebron is +02:00 or +03:00, never UTC — so a local rendering cannot
    // accidentally equal the stored instant.
    expect($after['next_ask_local'])->not->toBeNull()
        ->and($after['next_ask_local'])->not->toBe(
            $followUp->fresh()->next_ask_at->format('Y-m-d H:i')
        );
});

it('only lets the model propose the two outcomes a reply can support', function () {
    $outcome = collect(app(ToolRegistry::class)->requireKey('follow_up.resolve@1')->input->fields)
        ->firstWhere('name', 'outcome');

    expect($outcome->options)->toBe(['confirmed', 'not_yet'])
        // `cancel` is NOT proposable here: stopping a loop is its own tool, so one
        // contract does not quietly serve two different intentions.
        ->and(in_array('cancel', $outcome->options, true))->toBeFalse()
        ->and(FollowUpAnswer::proposable())->toBe(['confirmed', 'not_yet']);
});

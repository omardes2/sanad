<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\FollowUpAnswer;
use App\Enums\FollowUpBlockReason;
use App\Enums\FollowUpStatus;
use App\Enums\ReminderFailureReason;
use App\Enums\ReminderStatus;
use App\Enums\TaskStatus;
use App\Exceptions\Tools\ToolDomainException;
use App\Models\FollowUp;
use App\Models\Reminder;
use App\Models\Task;
use App\Services\FollowUps\FollowUpAskMaterialiser;
use App\Services\FollowUps\FollowUpService;
use App\Services\Reminders\ReminderDispatcher;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The loop: opening it, asking, reading the answer, and every way it ends.
 *
 * The two invariants under everything here:
 *   - ONE OUTSTANDING ASK AT A TIME, and the ladder is never pre-created;
 *   - the ask budget is REMINDER TRUTH (`attempts > 0`), so an ask refused before
 *     dispatch costs nothing and an ask with an unknown outcome costs one.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    fuConfigure();
});

/*
|--------------------------------------------------------------------------
| Opening a loop
|--------------------------------------------------------------------------
*/

it('opens a loop with the time the subscriber gave, and schedules nothing else', function () {
    [$user, , $conversation] = fuSubscriber();
    $at = CarbonImmutable::now('UTC')->addHours(20);

    $followUp = fuCreate($user, $conversation, ['first_ask_at' => $at->format('Y-m-d\TH:i')]);

    expect($followUp->status)->toBe(FollowUpStatus::Open)
        ->and($followUp->next_ask_at->format('Y-m-d H:i'))->toBe($at->format('Y-m-d H:i'))
        ->and($followUp->max_asks)->toBe(3)
        ->and($followUp->asksSent())->toBe(0)
        // Creating a loop SENDS NOTHING and creates no ask: materialisation does
        // that, separately, when the time comes.
        ->and(fuAsks($followUp))->toHaveCount(0)
        // Ownership, zone and channel all come from trusted context.
        ->and($followUp->user_id)->toBe($user->id)
        ->and($followUp->timezone)->toBe('Asia/Hebron')
        ->and($followUp->source_message_id)->not->toBeNull();
});

it('refuses to invent a time: with no definite moment in the subscriber\'s words it asks instead', function () {
    [$user, , $conversation] = fuSubscriber();

    // Explicit intent, but no time anywhere in the conversation.
    $message = fuInbound($user, $conversation, 'تابع معي بهاد الموضوع');

    expect(fn () => app(FollowUpService::class)->create(
        $user,
        ['question' => 'دفعت؟', 'first_ask_at' => CarbonImmutable::now('UTC')->addDay()->format('Y-m-d\TH:i')],
        ChannelType::WhatsApp,
        $message,
    ))->toThrow(ToolDomainException::class);

    // Nothing was created — which is the point: Sanad asks WHEN, and a default
    // («بكرا», +24h, "after the expected outcome") never stands in for an answer.
    expect(FollowUp::query()->count())->toBe(0);
});

it('refuses a first ask in the past, and a loop for a plan without the feature', function () {
    [$user, , $conversation] = fuSubscriber();

    expect(fn () => fuCreate($user, $conversation, [
        'first_ask_at' => CarbonImmutable::now('UTC')->subHour()->format('Y-m-d\TH:i'),
    ]))->toThrow(ToolDomainException::class);

    [$unentitled, , $otherConversation] = fuSubscriber(entitled: false);

    // PlanFeature::FollowUp is its OWN entitlement: this plan has reminders.
    expect(fn () => fuCreate($unentitled, $otherConversation))->toThrow(ToolDomainException::class)
        ->and(FollowUp::query()->count())->toBe(0);
});

it('caps live loops per subscriber and refuses rather than dropping one', function () {
    [$user, , $conversation] = fuSubscriber();
    config(['follow_ups.max_open_per_subscriber' => 2]);

    $first = fuCreate($user, $conversation);
    $second = fuCreate($user, $conversation);

    expect(fn () => fuCreate($user, $conversation))->toThrow(ToolDomainException::class);

    // Nothing was terminated to make space: a loop the subscriber asked Sanad to
    // watch is not the platform's to drop.
    expect(FollowUp::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and($first->fresh()->status)->toBe(FollowUpStatus::Open)
        ->and($second->fresh()->status)->toBe(FollowUpStatus::Open);

    // Cancelling one frees exactly one slot.
    app(FollowUpService::class)->cancel($user, (int) $first->getKey());

    expect(fuCreate($user, $conversation))->toBeInstanceOf(FollowUp::class)
        ->and(FollowUp::query()->where('user_id', $user->id)->live()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Asking — one at a time, on reminder truth
|--------------------------------------------------------------------------
*/

it('creates the ask as an ordinary reminder when it is due, and only then', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);

    // Not due yet.
    expect(fuAdvance($followUp))->toBe('waiting')
        ->and(fuAsks($followUp))->toHaveCount(0);

    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();

    expect(fuAdvance($followUp->fresh()))->toBe('created');

    $asks = fuAsks($followUp->fresh());

    expect($asks)->toHaveCount(1)
        ->and($asks[0]->ask_index)->toBe(1)
        ->and($asks[0]->status)->toBe(ReminderStatus::Pending)
        // It IS a reminder: its own claim, its own attempt budget, its own title.
        ->and($asks[0]->attempts)->toBe(0)
        ->and($asks[0]->claim_token)->toBeNull()
        ->and($asks[0]->title)->toBe($followUp->question)
        ->and($asks[0]->isFollowUpAsk())->toBeTrue()
        // And the loop no longer has anything scheduled: the ask is the
        // outstanding thing now.
        ->and($followUp->fresh()->next_ask_at)->toBeNull();
});

it('never creates a second ask while one is still outstanding', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();

    fuAdvance($followUp->fresh());

    // Ten more runs, with the first ask still pending.
    foreach (range(1, 10) as $i) {
        expect(fuAdvance($followUp->fresh()))->toBe('in_flight');
    }

    expect(fuAsks($followUp->fresh()))->toHaveCount(1);
});

it('moves to awaiting_answer only once an ask genuinely left the platform', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    $ask = fuAsks($followUp)[0];

    // A pending ask is not an asked question, and no budget is spent.
    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Open)
        ->and($followUp->fresh()->asksSent())->toBe(0);

    // Reminder truth: `attempts > 0` means a request left the platform.
    $ask->forceFill(['attempts' => 1, 'dispatched_at' => CarbonImmutable::now(), 'status' => ReminderStatus::Sent->value, 'sent_at' => CarbonImmutable::now()])->save();

    expect(fuAdvance($followUp->fresh()))->toBe('asked');

    $followUp->refresh();

    expect($followUp->status)->toBe(FollowUpStatus::AwaitingAnswer)
        ->and($followUp->asksSent())->toBe(1)
        ->and($followUp->budgetRemaining())->toBe(2)
        ->and($followUp->lastSentAt())->not->toBeNull();
});

it('waits out the interval, then asks again — and stops at the budget, silently', function () {
    [$user, , $conversation] = fuSubscriber();
    config(['follow_ups.max_asks_per_follow_up' => 2, 'follow_ups.min_ask_interval_hours' => 24]);
    $followUp = fuCreate($user, $conversation);

    $send = function (FollowUp $followUp, CarbonImmutable $at): void {
        $ask = fuAsks($followUp);
        $ask = end($ask);
        $ask->forceFill([
            'attempts' => 1,
            'dispatched_at' => $at,
            'sent_at' => $at,
            'status' => ReminderStatus::Sent->value,
        ])->save();
    };

    // Ask 1.
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    expect(fuAdvance($followUp->fresh()))->toBe('created');
    $send($followUp, CarbonImmutable::now('UTC')->subHours(30));
    expect(fuAdvance($followUp->fresh()))->toBe('asked');

    // The interval has passed, so ask 2 becomes eligible — one step at a time, and
    // the loop stays `awaiting_answer` throughout, because the question is still
    // outstanding while it is being asked again.
    expect(fuAdvance($followUp->fresh()))->toBe('created');
    expect(fuAsks($followUp->fresh()))->toHaveCount(2)
        ->and($followUp->fresh()->status)->toBe(FollowUpStatus::AwaitingAnswer);
    $send($followUp, CarbonImmutable::now('UTC')->subHours(25));

    // The budget is spent: the loop retires itself. TERMINAL AND SILENT — no
    // escalation, no third ask, ever.
    expect(fuAdvance($followUp->fresh()))->toBe('abandoned');

    $followUp->refresh();

    expect($followUp->status)->toBe(FollowUpStatus::Abandoned)
        ->and($followUp->asksSent())->toBe(2)
        ->and($followUp->terminated_at)->not->toBeNull()
        ->and($followUp->next_ask_at)->toBeNull();

    // And it stays retired, however many runs arrive.
    foreach (range(1, 5) as $i) {
        expect(fuAdvance($followUp->fresh()))->toBe('inert');
    }

    expect(fuAsks($followUp->fresh()))->toHaveCount(2);
});

it('does not ask again before the interval has elapsed', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    $ask = fuAsks($followUp)[0];
    // Sent one hour ago, with a 24-hour floor.
    $ask->forceFill([
        'attempts' => 1,
        'dispatched_at' => CarbonImmutable::now()->subHour(),
        'sent_at' => CarbonImmutable::now()->subHour(),
        'status' => ReminderStatus::Sent->value,
    ])->save();

    fuAdvance($followUp->fresh());

    foreach (range(1, 3) as $i) {
        expect(fuAdvance($followUp->fresh()))->toBe('waiting');
    }

    expect(fuAsks($followUp->fresh()))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| The template dependency: blocked, and never a retry loop
|--------------------------------------------------------------------------
*/

it('holds the loop instead of asking when no approved follow-up template exists outside the window', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);

    // No template, and the service window is closed (the subscriber's last
    // inbound message is old).
    config(['follow_ups.whatsapp.template.ready' => false, 'follow_ups.whatsapp.template.name' => '']);
    DB::table('messages')->where('user_id', $user->id)->update(['created_at' => CarbonImmutable::now()->subDays(5)]);

    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();

    expect(fuAdvance($followUp->fresh()))->toBe('blocked');

    $followUp->refresh();

    expect($followUp->status)->toBe(FollowUpStatus::Blocked)
        ->and($followUp->blocked_reason)->toBe(FollowUpBlockReason::TemplateUnavailable)
        ->and($followUp->blocked_at)->not->toBeNull()
        ->and($followUp->next_ask_at)->toBeNull()
        // NO ASK WAS CREATED AT ALL: no budget spent, and no failed delivery.
        ->and(fuAsks($followUp))->toHaveCount(0)
        ->and($followUp->asksSent())->toBe(0)
        ->and($followUp->budgetRemaining())->toBe(3);

    // And it stays held: a run-by-run retry loop is exactly what this prevents.
    foreach (range(1, 10) as $i) {
        expect(fuAdvance($followUp->fresh()))->toBe('inert');
    }

    expect(fuAsks($followUp->fresh()))->toHaveCount(0)
        ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Blocked);
});

it('asks inside the service window even with no template, because none is needed there', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);

    config(['follow_ups.whatsapp.template.ready' => false, 'follow_ups.whatsapp.template.name' => '']);
    // The subscriber spoke ten minutes ago: a free-form ask is permitted.
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();

    expect(fuAdvance($followUp->fresh()))->toBe('created')
        ->and($followUp->fresh()->status)->toBe(FollowUpStatus::Open);
});

it('treats an ask refused before dispatch as never asked, and holds the loop', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    // The delivery policy refused it BEFORE burning an attempt — the shape a
    // template gap takes when it appears between materialisation and delivery.
    $ask = fuAsks($followUp)[0];
    $ask->forceFill([
        'status' => ReminderStatus::Failed->value,
        'last_error' => ReminderFailureReason::TemplateRequired->value,
        'attempts' => 0,
    ])->save();

    expect(fuAdvance($followUp->fresh()))->toBe('blocked');

    $followUp->refresh();

    expect($followUp->status)->toBe(FollowUpStatus::Blocked)
        ->and($followUp->blocked_reason)->toBe(FollowUpBlockReason::TemplateUnavailable)
        // THE BUDGET IS UNTOUCHED: nothing reached a provider, so nothing was
        // asked. A configuration gap cannot drain a subscriber's ladder.
        ->and($followUp->asksSent())->toBe(0)
        ->and($followUp->budgetRemaining())->toBe(3)
        // And no replacement ask is generated to fail the same way.
        ->and(fuAsks($followUp))->toHaveCount(1);
});

it('releases a held loop only once the dependency is satisfied, idempotently', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);

    config(['follow_ups.whatsapp.template.ready' => false, 'follow_ups.whatsapp.template.name' => '']);
    DB::table('messages')->where('user_id', $user->id)->update(['created_at' => CarbonImmutable::now()->subDays(5)]);
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Blocked);

    // Still missing: the command refuses to release what is still broken.
    $this->artisan('sanad:follow-ups:unblock')->assertSuccessful();
    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Blocked);

    // Now approved and configured.
    config([
        'follow_ups.whatsapp.template.ready' => true,
        'follow_ups.whatsapp.template.name' => 'sanad_follow_up_v1',
        'follow_ups.whatsapp.template.language' => 'ar',
    ]);

    $this->artisan('sanad:follow-ups:unblock')->assertSuccessful();

    $followUp->refresh();

    expect($followUp->status)->toBe(FollowUpStatus::Open)
        ->and($followUp->blocked_reason)->toBeNull()
        ->and($followUp->next_ask_at)->not->toBeNull();

    // Idempotent: running it again changes nothing.
    $before = $followUp->fresh()->updated_at;
    $this->artisan('sanad:follow-ups:unblock')->assertSuccessful();
    expect($followUp->fresh()->status)->toBe(FollowUpStatus::Open)
        ->and($followUp->fresh()->updated_at->eq($before))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Closing the loop
|--------------------------------------------------------------------------
*/

it('closes the loop on a correlated confirmation, exactly once', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuAwaiting($user, $conversation);

    $reply = fuInbound($user, $conversation, 'اه دفعتها');

    $result = app(FollowUpService::class)->resolve($user, (int) $followUp->getKey(), FollowUpAnswer::Confirmed, $reply);

    $followUp->refresh();

    expect($result['resolved'])->toBeTrue()
        ->and($followUp->status)->toBe(FollowUpStatus::ResolvedConfirmed)
        ->and($followUp->resolved_at)->not->toBeNull()
        ->and($followUp->terminated_at)->not->toBeNull()
        ->and($followUp->next_ask_at)->toBeNull()
        // The evidence is kept as an IDENTIFIER, never as a copy of the words.
        ->and($followUp->resolved_by_message_id)->toBe($reply->id);

    // Idempotent and honest: the loop is closed, and a repeat is not the call
    // that closed it.
    $again = app(FollowUpService::class)->resolve($user, (int) $followUp->getKey(), FollowUpAnswer::Confirmed, $reply);

    expect($again['resolved'])->toBeFalse()
        ->and($followUp->fresh()->status)->toBe(FollowUpStatus::ResolvedConfirmed);

    // And no further ask is ever created.
    expect(fuAdvance($followUp->fresh()))->toBe('inert');
});

it('keeps the loop open on "not yet" and schedules at most one more ask, never immediately', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuAwaiting($user, $conversation);
    $askedAt = $followUp->lastSentAt();

    $reply = fuInbound($user, $conversation, 'لسا ما دفعت');

    $result = app(FollowUpService::class)->resolve($user, (int) $followUp->getKey(), FollowUpAnswer::NotYet, $reply);

    $followUp->refresh();

    expect($result['resolved'])->toBeFalse()
        ->and($followUp->status)->toBe(FollowUpStatus::Open)
        ->and($followUp->resolved_at)->toBeNull()
        // The next ask is the LAST ASK plus the interval — so answering "not yet"
        // can never be the thing that triggers the next message.
        ->and($followUp->next_ask_at->format('Y-m-d H:i'))
        ->toBe($askedAt->addHours(24)->format('Y-m-d H:i'));

    // Nothing is created right now.
    expect(fuAdvance($followUp->fresh()))->toBe('waiting')
        ->and(fuAsks($followUp->fresh()))->toHaveCount(1);
});

it('refuses to resolve on an unreadable reply, and never reads it as "no"', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuAwaiting($user, $conversation);

    $vague = fuInbound($user, $conversation, 'اهلا كيفك، بدي أسألك عن شي تاني');

    expect(fn () => app(FollowUpService::class)->resolve($user, (int) $followUp->getKey(), FollowUpAnswer::Confirmed, $vague))
        ->toThrow(ToolDomainException::class)
        ->and(fn () => app(FollowUpService::class)->resolve($user, (int) $followUp->getKey(), FollowUpAnswer::NotYet, $vague))
        ->toThrow(ToolDomainException::class);

    $followUp->refresh();

    // The loop did not move, and — crucially — no new ask was triggered.
    expect($followUp->status)->toBe(FollowUpStatus::AwaitingAnswer)
        ->and($followUp->next_ask_at)->toBeNull()
        ->and(fuAsks($followUp))->toHaveCount(1);
});

it('refuses to resolve when two loops could be the subject of one «آه»', function () {
    [$user, , $conversation] = fuSubscriber();
    $first = fuAwaiting($user, $conversation);
    $second = fuAwaiting($user, $conversation, 'راجعت الطبيب؟');

    $reply = fuInbound($user, $conversation, 'اه');

    // Two open questions, one bare affirmative. The domain must not pick.
    expect(fn () => app(FollowUpService::class)->resolve($user, (int) $first->getKey(), FollowUpAnswer::Confirmed, $reply))
        ->toThrow(ToolDomainException::class);

    expect($first->fresh()->status)->toBe(FollowUpStatus::AwaitingAnswer)
        ->and($second->fresh()->status)->toBe(FollowUpStatus::AwaitingAnswer);
});

it('refuses a reply that predates the ask, or arrives after the answer window', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuAwaiting($user, $conversation);

    $before = fuInbound($user, $conversation, 'اه', CarbonImmutable::now()->subDays(3));
    expect(fn () => app(FollowUpService::class)->resolve($user, (int) $followUp->getKey(), FollowUpAnswer::Confirmed, $before))
        ->toThrow(ToolDomainException::class);

    $late = fuInbound($user, $conversation, 'اه', CarbonImmutable::now()->addDays(10));
    expect(fn () => app(FollowUpService::class)->resolve($user, (int) $followUp->getKey(), FollowUpAnswer::Confirmed, $late))
        ->toThrow(ToolDomainException::class);

    expect($followUp->fresh()->status)->toBe(FollowUpStatus::AwaitingAnswer);
});

it('refuses a reply from another subscriber entirely', function () {
    [$user, , $conversation] = fuSubscriber();
    [$other, , $otherConversation] = fuSubscriber();
    $followUp = fuAwaiting($user, $conversation);

    $theirs = fuInbound($other, $otherConversation, 'اه دفعت');

    expect(fn () => app(FollowUpService::class)->resolve($user, (int) $followUp->getKey(), FollowUpAnswer::Confirmed, $theirs))
        ->toThrow(ToolDomainException::class)
        ->and($followUp->fresh()->status)->toBe(FollowUpStatus::AwaitingAnswer);
});

it('cancels a loop and its pending ask in one go, idempotently', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    $result = app(FollowUpService::class)->cancel($user, (int) $followUp->getKey());

    $followUp->refresh();

    expect($result['terminated'])->toBeTrue()
        ->and($result['cancelled_asks'])->toBe(1)
        ->and($followUp->status)->toBe(FollowUpStatus::Cancelled)
        ->and($followUp->terminated_at)->not->toBeNull()
        ->and($followUp->next_ask_at)->toBeNull()
        ->and(fuAsks($followUp)[0]->status)->toBe(ReminderStatus::Cancelled);

    $again = app(FollowUpService::class)->cancel($user, (int) $followUp->getKey());

    expect($again['terminated'])->toBeFalse()
        ->and($again['cancelled_asks'])->toBe(0);

    // And a materialiser that was mid-flight cannot revive it.
    expect(fuAdvance($followUp->fresh()))->toBe('inert')
        ->and(fuAsks($followUp->fresh()))->toHaveCount(1);
});

it('closes the loop when the linked task is completed, exactly once and only when truly linked', function () {
    [$user, , $conversation] = fuSubscriber();

    $task = Task::query()->create([
        'user_id' => $user->id,
        'title' => 'ادفع فاتورة الكهربا',
        'status' => TaskStatus::Pending->value,
    ]);
    $unrelated = Task::query()->create([
        'user_id' => $user->id,
        'title' => 'اتصل بالبنك',
        'status' => TaskStatus::Pending->value,
    ]);

    $linked = fuCreate($user, $conversation, ['task_id' => $task->id]);
    $other = fuCreate($user, $conversation);

    // Completing an UNRELATED task closes nothing.
    app(TaskService::class)->complete($user, (int) $unrelated->getKey());

    expect($linked->fresh()->status)->toBe(FollowUpStatus::Open)
        ->and($other->fresh()->status)->toBe(FollowUpStatus::Open);

    app(TaskService::class)->complete($user, (int) $task->getKey());

    expect($linked->fresh()->status)->toBe(FollowUpStatus::ResolvedByTask)
        ->and($linked->fresh()->resolved_at)->not->toBeNull()
        // The loop with no task is untouched.
        ->and($other->fresh()->status)->toBe(FollowUpStatus::Open);

    // Saying "done" twice closes nothing a second time.
    $resolvedAt = $linked->fresh()->resolved_at;
    app(TaskService::class)->complete($user, (int) $task->getKey());

    expect($linked->fresh()->resolved_at->eq($resolvedAt))->toBeTrue();
});

it('refuses to attach a loop to a task that is not the subscriber\'s', function () {
    [$user, , $conversation] = fuSubscriber();
    [$other] = fuSubscriber();

    $theirTask = Task::query()->create([
        'user_id' => $other->id,
        'title' => 'شي تاني',
        'status' => TaskStatus::Pending->value,
    ]);

    expect(fn () => fuCreate($user, $conversation, ['task_id' => $theirTask->id]))
        ->toThrow(ToolDomainException::class)
        ->and(FollowUp::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The ask really is an ordinary reminder
|--------------------------------------------------------------------------
*/

it('delivers an ask through the untouched reminder path, with the FOLLOW-UP template outside the window', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    // Outside the service window, so an approved template is the only mechanism.
    DB::table('messages')->where('user_id', $user->id)->update(['created_at' => CarbonImmutable::now()->subDays(5)]);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.FU1']]], 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $claim = $dispatcher->claimDue(10)[0];
    $dispatcher->deliver($claim);

    $ask = fuAsks($followUp)[0]->fresh();

    expect($ask->status)->toBe(ReminderStatus::Sent)
        ->and($ask->attempts)->toBe(1)
        // One outbound message per ask, which `messages.reminder_id` enforces.
        ->and(DB::table('messages')->where('reminder_id', $ask->id)->count())->toBe(1);

    // And the request carried the FOLLOW-UP template, not the reminder one.
    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->first();

    expect($sent['type'] ?? null)->toBe('template')
        ->and($sent['template']['name'] ?? null)->toBe('sanad_follow_up_v1');
});

it('holds the loop when an ask exhausts its physical attempts without ever reaching sent', function () {
    [$user, , $conversation] = fuSubscriber();
    config(['follow_ups.min_ask_interval_hours' => 1]);
    $followUp = fuCreate($user, $conversation);

    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    /*
     * Ask 1 spends BOTH physical attempts on unproven outcomes and is retired by
     * the sweeper as `attempts_exhausted`. It never reached `sent`, so Sanad cannot
     * say the subscriber was asked — and the ladder must not act as though it was.
     */
    $first = fuAsks($followUp)[0];
    $first->forceFill([
        'attempts' => ReminderDispatcher::MAX_ATTEMPTS,
        'dispatched_at' => CarbonImmutable::now()->subHours(3),
        'status' => ReminderStatus::Failed->value,
        'last_error' => ReminderFailureReason::AttemptsExhausted->value,
    ])->save();

    expect(fuAdvance($followUp->fresh()))->toBe('blocked');

    $followUp->refresh();

    expect($followUp->status)->toBe(FollowUpStatus::Blocked)
        ->and($followUp->blocked_reason)->toBe(FollowUpBlockReason::DeliveryUnavailable)
        // NO logical ask was spent: two physical attempts are not one question.
        ->and($followUp->asksSent())->toBe(0)
        ->and($followUp->budgetRemaining())->toBe(3)
        // And no replacement ask is produced, now or ever, while the hold stands.
        ->and(fuAsks($followUp))->toHaveCount(1);

    foreach (range(1, 5) as $i) {
        expect(fuAdvance($followUp->fresh()))->toBe('inert');
    }

    expect(fuAsks($followUp->fresh()))->toHaveCount(1);
});

it('gives the next ask a fresh physical ceiling once the previous one is proven sent', function () {
    [$user, , $conversation] = fuSubscriber();
    config(['follow_ups.min_ask_interval_hours' => 1]);
    $followUp = fuCreate($user, $conversation);

    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    // Ask 1 needed BOTH physical attempts, and the second one was accepted.
    $first = fuAsks($followUp)[0];
    $first->forceFill([
        'attempts' => ReminderDispatcher::MAX_ATTEMPTS,
        'dispatched_at' => CarbonImmutable::now()->subHours(3),
        'sent_at' => CarbonImmutable::now()->subHours(3),
        'status' => ReminderStatus::Sent->value,
    ])->save();

    expect(fuAdvance($followUp->fresh()))->toBe('asked')
        // ONE logical ask, not two: the budget counts questions, not requests.
        ->and($followUp->fresh()->asksSent())->toBe(1);

    expect(fuAdvance($followUp->fresh()))->toBe('created');

    $second = fuAsks($followUp->fresh())[1];

    expect($second->attempts)->toBe(0)
        ->and($second->claim_token)->toBeNull()
        ->and($second->ask_index)->toBe(2)
        ->and($first->fresh()->attempts)->toBe(ReminderDispatcher::MAX_ATTEMPTS);
});

/*
|--------------------------------------------------------------------------
| The crash boundary: `attempts` is not delivery truth
|--------------------------------------------------------------------------
*/

it('does not advance the ladder when a worker died after authorising an attempt and before the network', function () {
    [$user, , $conversation] = fuSubscriber();
    config(['follow_ups.min_ask_interval_hours' => 1]);
    $followUp = fuCreate($user, $conversation);

    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    /*
     * EXACTLY THE STATE `ReminderDispatcher::authoriseDispatch()` COMMITS: the
     * claim is held, `attempts` is 1 and `dispatched_at` is stamped — and then the
     * worker dies before the request leaves. `sent_at` is null and the status is
     * still `processing`, which is indistinguishable from a request in flight. That
     * is the whole reason `attempts` cannot mean "asked".
     */
    $ask = fuAsks($followUp)[0];
    $ask->forceFill([
        'status' => ReminderStatus::Processing->value,
        'claim_token' => str_repeat('a', 32),
        'claimed_at' => CarbonImmutable::now()->subMinutes(2),
        'attempts' => 1,
        'dispatched_at' => CarbonImmutable::now()->subMinutes(2),
        'sent_at' => null,
    ])->save();

    // Many materialiser runs, with the ladder given every chance to advance.
    foreach (range(1, 8) as $i) {
        expect(fuAdvance($followUp->fresh()))->toBe('in_flight');
    }

    $followUp->refresh();

    expect($followUp->asksSent())->toBe(0, 'a crash before the network asked nothing')
        ->and($followUp->budgetRemaining())->toBe(3)
        // No ask 2: a question that may never have been asked cannot license a second.
        ->and(fuAsks($followUp))->toHaveCount(1)
        // And the loop is neither awaiting an answer nor answered.
        ->and($followUp->status)->toBe(FollowUpStatus::Open)
        ->and($followUp->resolved_at)->toBeNull()
        ->and($followUp->lastSentAt())->toBeNull();
});

it('counts the logical ask exactly once when the reminder reaches sent, however many runs observe it', function () {
    [$user, , $conversation] = fuSubscriber();
    $followUp = fuCreate($user, $conversation);

    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    $ask = fuAsks($followUp)[0];
    $ask->forceFill([
        'attempts' => 1,
        'dispatched_at' => CarbonImmutable::now(),
        'sent_at' => CarbonImmutable::now(),
        'status' => ReminderStatus::Sent->value,
    ])->save();

    // Ten observers of the same sent reminder.
    $outcomes = [];

    foreach (range(1, 10) as $i) {
        $outcomes[] = fuAdvance($followUp->fresh());
    }

    $followUp->refresh();

    // There is no counter to increment, so there is nothing to increment twice:
    // the number is a count over the ask rows.
    expect($followUp->asksSent())->toBe(1, implode(' | ', $outcomes))
        ->and($followUp->budgetRemaining())->toBe(2)
        ->and($followUp->status)->toBe(FollowUpStatus::AwaitingAnswer)
        ->and(fuAsks($followUp))->toHaveCount(1);
});

it('does not produce the next ask from an unknown outcome, and never calls it sent or unsent', function () {
    [$user, , $conversation] = fuSubscriber();
    config(['follow_ups.min_ask_interval_hours' => 1]);
    $followUp = fuCreate($user, $conversation);

    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
    fuAdvance($followUp->fresh());

    // A request left and its answer never came back: the dispatcher records
    // `unknown`, which is neither a delivery nor a confirmed failure.
    $ask = fuAsks($followUp)[0];
    $ask->forceFill([
        'attempts' => 1,
        'dispatched_at' => CarbonImmutable::now()->subHours(2),
        'status' => ReminderStatus::Processing->value,
        'last_error' => ReminderFailureReason::Unknown->value,
    ])->save();

    // While it is unresolved, nothing advances.
    expect(fuAdvance($followUp->fresh()))->toBe('in_flight')
        ->and($followUp->fresh()->asksSent())->toBe(0)
        ->and(fuAsks($followUp->fresh()))->toHaveCount(1);

    // The reminder subsystem exhausts its own bounded retry and retires the row
    // without ever establishing `sent`.
    $ask->forceFill([
        'attempts' => ReminderDispatcher::MAX_ATTEMPTS,
        'status' => ReminderStatus::Failed->value,
        'last_error' => ReminderFailureReason::Unknown->value,
    ])->save();

    expect(fuAdvance($followUp->fresh()))->toBe('blocked');

    $followUp->refresh();

    // Held: not asked, not unasked, no budget spent, no replacement ask.
    expect($followUp->status)->toBe(FollowUpStatus::Blocked)
        ->and($followUp->blocked_reason)->toBe(FollowUpBlockReason::DeliveryUnavailable)
        ->and($followUp->asksSent())->toBe(0)
        ->and($followUp->budgetRemaining())->toBe(3)
        ->and(fuAsks($followUp))->toHaveCount(1)
        ->and($followUp->resolved_at)->toBeNull();
});

it('leaves one-time reminders and recurring occurrences completely untouched', function () {
    [$user, , $conversation] = fuSubscriber();
    $message = fuInbound($user, $conversation, 'ذكرني بكرا الساعة ٩');

    $reminder = Reminder::query()->create([
        'user_id' => $user->id,
        'source_message_id' => $message->id,
        'title' => 'اتصال',
        'remind_at' => CarbonImmutable::now()->addHour(),
        'timezone' => 'Asia/Hebron',
        'channel' => ChannelType::WhatsApp->value,
        'status' => ReminderStatus::Pending->value,
    ]);

    expect($reminder->follow_up_id)->toBeNull()
        ->and($reminder->ask_index)->toBeNull()
        ->and($reminder->isFollowUpAsk())->toBeFalse()
        ->and($reminder->isOccurrence())->toBeFalse();

    // A follow-up materialisation run does not touch it.
    app(FollowUpAskMaterialiser::class)->run();

    expect($reminder->fresh()->status)->toBe(ReminderStatus::Pending)
        ->and(Reminder::query()->whereNotNull('follow_up_id')->count())->toBe(0);
});

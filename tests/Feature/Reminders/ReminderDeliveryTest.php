<?php

declare(strict_types=1);

use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Enums\ReminderFailureReason;
use App\Enums\ReminderStatus;
use App\Exceptions\Tools\ToolDomainException;
use App\Jobs\DeliverReminder;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\ReminderDispatcher;
use App\Services\Reminders\ReminderService;
use App\Support\Billing\UsageKeys;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Reminder delivery — an EXTERNAL write with a bounded at-least-once guarantee.
 *
 * The two facts every test here defends:
 *   1. `attempts` counts PHYSICAL dispatches, never claims;
 *   2. at most 2 physical sends per reminder occurrence, ever.
 */
beforeEach(function () {
    whatsappConfigure();
    config([
        'reminders.enabled' => true,
        'reminders.max_lateness_minutes' => 60,
        'reminders.lease_seconds' => 300,
        'reminders.batch' => 100,
        'reminders.whatsapp.free_form_window_hours' => 24,
        'reminders.whatsapp.template.ready' => false,
        'reminders.whatsapp.template.name' => null,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'UTC'));
});

/**
 * A subscriber on WhatsApp with a conversation, a recent inbound message
 * (so the free-form window is open) and one due reminder.
 *
 * @return array{0: User, 1: ChannelAccount, 2: Conversation, 3: Reminder}
 */
function reminderSubject(array $reminder = [], ?CarbonImmutable $lastInboundAt = null): array
{
    $user = User::factory()->create(['is_admin' => false, 'timezone' => 'Asia/Hebron']);
    $account = ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => '+97059900'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
        'status' => ChannelAccountStatus::Active,
    ]);
    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);

    $inbound = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => 'ذكرني بكرا',
        'created_at' => $lastInboundAt ?? CarbonImmutable::now()->subHour(),
    ]);

    $row = Reminder::query()->create(array_merge([
        'user_id' => $user->id,
        'source_message_id' => $inbound->id,
        'title' => 'أتصل على البنك',
        'remind_at' => CarbonImmutable::now()->subMinute(),
        'timezone' => 'Asia/Hebron',
        'channel' => ChannelType::WhatsApp->value,
        'status' => ReminderStatus::Pending->value,
    ], $reminder));

    return [$user, $account, $conversation, $row];
}

/** A Graph API success response carrying a wamid. */
function reminderAccepted(string $wamid = 'wamid.ACCEPTED1'): array
{
    return ['messages' => [['id' => $wamid]]];
}

// ---------------------------------------------------------------------------
// Physical attempt counting — a claim is not a send
// ---------------------------------------------------------------------------

it('claims a due reminder without counting a physical attempt', function () {
    [, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $claimed = app(ReminderDispatcher::class)->claimDue(10);
    $reminder->refresh();

    expect($claimed)->toBe([$reminder->id])
        ->and($reminder->status)->toBe(ReminderStatus::Processing)
        ->and($reminder->claimed_at)->not->toBeNull()
        // A claim is bookkeeping. Nothing left the platform.
        ->and($reminder->attempts)->toBe(0)
        ->and($reminder->dispatched_at)->toBeNull();

    Http::assertNothingSent();
});

it('W1 — a crash between the claim and the request leaves attempts at 0 and does not consume the retry budget', function () {
    [, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);
    $dispatcher = app(ReminderDispatcher::class);

    // Claimed, then the worker dies before ever reaching the dispatcher.
    $dispatcher->claimDue(10);
    expect($reminder->fresh()->attempts)->toBe(0);

    // The lease expires and the sweeper recovers it.
    $this->travelTo(CarbonImmutable::now()->addSeconds(400));
    expect($dispatcher->sweep())->toBe(['recovered' => 1, 'failed' => 0]);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Pending)
        ->and($reminder->claimed_at)->toBeNull()
        // The budget is untouched: nothing was ever sent.
        ->and($reminder->attempts)->toBe(0)
        ->and($reminder->dispatched_at)->toBeNull();

    // And it still delivers, on its FIRST physical attempt.
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Sent)
        ->and($reminder->attempts)->toBe(1);
    Http::assertSentCount(1);
});

it('counts the first physical send as attempt 1', function () {
    [, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    $reminder->refresh();
    expect($reminder->attempts)->toBe(1)
        ->and($reminder->status)->toBe(ReminderStatus::Sent)
        ->and($reminder->dispatched_at)->not->toBeNull();
    Http::assertSentCount(1);
});

it('counts a second physical send as attempt 2, and a third is structurally impossible', function () {
    [, , , $reminder] = reminderSubject();
    // A 5xx is not a proven non-delivery: the provider may have accepted the
    // message before failing to answer. Both attempts end unproven.
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

    $dispatcher = app(ReminderDispatcher::class);

    // Attempt 1.
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);
    $reminder->refresh();
    expect($reminder->attempts)->toBe(1)
        ->and($reminder->status)->toBe(ReminderStatus::Processing)
        ->and($reminder->last_error)->toBe('unknown');

    // The lease expires; the budget still allows exactly one more.
    $this->travelTo(CarbonImmutable::now()->addSeconds(400));
    expect($dispatcher->sweep())->toBe(['recovered' => 1, 'failed' => 0]);

    // Attempt 2.
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);
    $reminder->refresh();
    expect($reminder->attempts)->toBe(2);

    // A third is refused by the ceiling, not by luck: the sweeper settles it.
    $this->travelTo(CarbonImmutable::now()->addSeconds(400));
    expect($dispatcher->sweep())->toBe(['recovered' => 0, 'failed' => 1]);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Failed)
        ->and($reminder->last_error)->toBe('attempts_exhausted')
        ->and($reminder->attempts)->toBe(2);

    // Exactly two physical requests ever left Sanad.
    Http::assertSentCount(2);
});

it('refuses a dispatch once the budget is spent, even if a worker asks directly', function () {
    [, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);
    $dispatcher = app(ReminderDispatcher::class);

    // Hand-place the reminder at the ceiling under a fresh claim.
    $reminder->forceFill([
        'status' => ReminderStatus::Processing->value,
        'attempts' => ReminderDispatcher::MAX_ATTEMPTS,
        'claimed_at' => CarbonImmutable::now(),
        'dispatched_at' => CarbonImmutable::now()->subMinute(),
    ])->save();

    $dispatcher->deliver($reminder->id);

    expect($reminder->fresh()->attempts)->toBe(ReminderDispatcher::MAX_ATTEMPTS);
    Http::assertNothingSent();
});

it('lets one claim authorise only one physical send', function () {
    [, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);
    $dispatcher = app(ReminderDispatcher::class);

    $id = $dispatcher->claimDue(10)[0];

    // Two workers act on the SAME claim. The first dispatches; the second
    // reads dispatched_at >= claimed_at and stops without sending.
    $dispatcher->deliver($id);
    $dispatcher->deliver($id);

    expect($reminder->fresh()->attempts)->toBe(1);
    Http::assertSentCount(1);
});

// ---------------------------------------------------------------------------
// Settlement, unknown outcomes and cost
// ---------------------------------------------------------------------------

it('settles an accepted send: one outbound message, one cost row, no quota charge', function () {
    [$user, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted('wamid.OK1'), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    $reminder->refresh();
    $message = Message::query()->where('reminder_id', $reminder->id)->sole();
    $correlation = UsageKeys::correlationForReminder($reminder);

    expect($reminder->status)->toBe(ReminderStatus::Sent)
        ->and($reminder->sent_at)->not->toBeNull()
        ->and($reminder->last_error)->toBeNull()
        ->and($message->direction)->toBe(MessageDirection::Outbound)
        ->and($message->provider_message_id)->toBe('wamid.OK1')
        ->and($message->delivery_status)->toBe(MessageDeliveryStatus::Accepted)
        ->and($message->processing_status)->toBe(MessageProcessingStatus::Processed)
        ->and($message->text_content)->toContain('أتصل على البنك')
        // One ledger row for the one physical request the provider served.
        ->and(DB::table('usage_events')->where('correlation_id', $correlation)->count())->toBe(1)
        ->and(DB::table('usage_events')->where('idempotency_key', 'whatsapp_outbound:reminder:'.$reminder->id.':attempt:1')->count())->toBe(1)
        // A scheduled reminder is never dropped by a message allowance.
        ->and(DB::table('usage_charges')->count())->toBe(0);
});

it('invents no cost for a dispatch whose outcome it cannot prove', function () {
    [, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('timed out')]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Processing)
        ->and($reminder->last_error)->toBe('unknown')
        ->and($reminder->attempts)->toBe(1)
        // Unknown is neither a confirmed failure nor confirmed zero cost:
        // nothing is written either way.
        ->and(DB::table('usage_events')->count())->toBe(0);
});

it('records a second cost row when a second physical send is genuinely accepted', function () {
    [, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        // Attempt 1: accepted, but Sanad never learns it (5xx read as unknown).
        ->push(reminderAccepted('wamid.FIRST'), 200)
        ->push(reminderAccepted('wamid.SECOND'), 200),
    ]);

    $dispatcher = app(ReminderDispatcher::class);

    // Attempt 1 settles normally.
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);
    $reminder->refresh();

    // Force the reminder back for a second REAL send, as the sweeper would
    // after an unproven attempt.
    $reminder->forceFill([
        'status' => ReminderStatus::Processing->value,
        'claimed_at' => CarbonImmutable::now()->addSecond(),
        'sent_at' => null,
    ])->save();
    $this->travelTo(CarbonImmutable::now()->addSeconds(2));

    $dispatcher->deliver($reminder->id);
    $reminder->refresh();

    // Two real sends were served and billed, so there are two rows. They are
    // NOT collapsed merely for belonging to the same reminder.
    expect($reminder->attempts)->toBe(2)
        ->and(DB::table('usage_events')->count())->toBe(2)
        ->and(DB::table('usage_events')->orderBy('id')->pluck('idempotency_key')->all())->toBe([
            'whatsapp_outbound:reminder:'.$reminder->id.':attempt:1',
            'whatsapp_outbound:reminder:'.$reminder->id.':attempt:2',
        ])
        // Still exactly one outbound message row: the unique key holds.
        ->and(Message::query()->where('reminder_id', $reminder->id)->count())->toBe(1);
});

it('treats a positive provider rejection as a confirmed non-delivery and never retries it', function () {
    [, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad recipient']], 400)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Failed)
        ->and($reminder->last_error)->toBe('rejected')
        ->and($reminder->attempts)->toBe(1)
        ->and(DB::table('usage_events')->count())->toBe(0);

    // Terminal: the sweeper never picks it up again.
    $this->travelTo(CarbonImmutable::now()->addSeconds(400));
    expect($dispatcher->sweep())->toBe(['recovered' => 0, 'failed' => 0]);
    Http::assertSentCount(1);
});

it('sends exactly one physical request per attempt — the adapter never retries a proactive send', function () {
    [, , , $reminder] = reminderSubject();
    // A 500 would normally trigger the adapter's own retry loop three times.
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    expect($reminder->fresh()->attempts)->toBe(1);
    // One counted attempt must never become three real messages.
    Http::assertSentCount(1);
});

// ---------------------------------------------------------------------------
// The proactive-message policy
// ---------------------------------------------------------------------------

it('sends free-form text while the customer-service window is open', function () {
    [, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    Http::assertSent(function ($request) {
        return $request['type'] === 'text' && ! isset($request['template']);
    });
    expect($reminder->fresh()->status)->toBe(ReminderStatus::Sent);
});

it('never gambles a free-form message outside the window: with no approved template it fails closed', function () {
    // Last inbound two days ago ⇒ the window is shut.
    [, , , $reminder] = reminderSubject(lastInboundAt: CarbonImmutable::now()->subDays(2));
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Failed)
        ->and($reminder->last_error)->toBe('template_required')
        // A configuration gap is not a transient failure: no request is made,
        // so no attempt is consumed and nothing is retried.
        ->and($reminder->attempts)->toBe(0);
    Http::assertNothingSent();

    $this->travelTo(CarbonImmutable::now()->addSeconds(400));
    expect($dispatcher->sweep())->toBe(['recovered' => 0, 'failed' => 0]);
});

it('uses the configured approved template outside the window', function () {
    [, , , $reminder] = reminderSubject(lastInboundAt: CarbonImmutable::now()->subDays(2));
    config([
        'reminders.whatsapp.template.ready' => true,
        'reminders.whatsapp.template.name' => 'sanad_reminder',
        'reminders.whatsapp.template.language' => 'ar',
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    Http::assertSent(function ($request) {
        return $request['type'] === 'template'
            && $request['template']['name'] === 'sanad_reminder'
            && $request['template']['language']['code'] === 'ar'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'أتصل على البنك';
    });
    expect($reminder->fresh()->status)->toBe(ReminderStatus::Sent);
});

it('invents no template name: a "ready" flag with no configured name still fails closed', function () {
    [, , , $reminder] = reminderSubject(lastInboundAt: CarbonImmutable::now()->subDays(2));
    config(['reminders.whatsapp.template.ready' => true, 'reminders.whatsapp.template.name' => '']);
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    expect($reminder->fresh()->last_error)->toBe('template_required');
    Http::assertNothingSent();
});

it('refuses to send at all when the channel is not configured to send', function () {
    [, , , $reminder] = reminderSubject();
    config(['whatsapp.enabled' => false]);
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Failed)
        ->and($reminder->last_error)->toBe('delivery_disabled')
        ->and($reminder->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('claims nothing at all while delivery is switched off', function () {
    reminderSubject();
    config(['reminders.enabled' => false]);

    expect(app(ReminderDispatcher::class)->claimDue(10))->toBe([]);
});

// ---------------------------------------------------------------------------
// Lateness
// ---------------------------------------------------------------------------

it('never delivers a reminder past the configured lateness window', function () {
    [, , , $reminder] = reminderSubject(['remind_at' => CarbonImmutable::now()->subMinutes(90)]);
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Failed)
        ->and($reminder->last_error)->toBe('too_late')
        ->and($reminder->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('reads the lateness window from configuration, not from a constant', function () {
    [, , , $reminder] = reminderSubject(['remind_at' => CarbonImmutable::now()->subMinutes(90)]);
    config(['reminders.max_lateness_minutes' => 180]);
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    expect($reminder->fresh()->status)->toBe(ReminderStatus::Sent);
});

it('settles a reminder that went stale past its lateness window as too_late, not as a retry', function () {
    [, , , $reminder] = reminderSubject();
    Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('timed out')]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);
    expect($reminder->fresh()->attempts)->toBe(1);

    // Two hours later the occasion has passed.
    $this->travelTo(CarbonImmutable::now()->addHours(2));
    expect($dispatcher->sweep())->toBe(['recovered' => 0, 'failed' => 1]);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Failed)
        ->and($reminder->last_error)->toBe('too_late');
});

// ---------------------------------------------------------------------------
// Recipient resolution — refuse, never guess
// ---------------------------------------------------------------------------

it('delivers to the account of the conversation the reminder was created in', function () {
    [$user, $account, , $reminder] = reminderSubject();
    // A second, unrelated active account on the same channel: provenance must win.
    ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => '+970599999999',
        'status' => ChannelAccountStatus::Active,
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    Http::assertSent(fn ($request) => $request['to'] === ltrim($account->external_identifier, '+'));
});

it('falls back to the single active account when the source message is gone', function () {
    [, $account, , $reminder] = reminderSubject();
    $reminder->forceFill(['source_message_id' => null])->save();
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    expect($reminder->fresh()->status)->toBe(ReminderStatus::Sent);
    Http::assertSent(fn ($request) => $request['to'] === ltrim($account->external_identifier, '+'));
});

it('fails closed rather than guessing between two candidate accounts', function () {
    [$user, , , $reminder] = reminderSubject();
    $reminder->forceFill(['source_message_id' => null])->save();
    ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => '+970599999999',
        'status' => ChannelAccountStatus::Active,
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    $reminder->refresh();
    expect($reminder->status)->toBe(ReminderStatus::Failed)
        ->and($reminder->last_error)->toBe('no_recipient')
        ->and($reminder->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('never delivers through a disconnected account', function () {
    [, $account, , $reminder] = reminderSubject();
    $account->forceFill(['status' => ChannelAccountStatus::Disconnected])->save();
    $reminder->forceFill(['source_message_id' => null])->save();
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    expect($reminder->fresh()->last_error)->toBe('no_recipient');
    Http::assertNothingSent();
});

it('never delivers one subscriber\'s reminder to another', function () {
    [, , , $reminder] = reminderSubject();
    $other = User::factory()->create();
    $theirs = ChannelAccount::factory()->for($other)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => '+970588000000',
        'status' => ChannelAccountStatus::Active,
    ]);
    Conversation::factory()->for($other)->create(['channel_account_id' => $theirs->id]);
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    Http::assertSent(fn ($request) => $request['to'] !== ltrim($theirs->external_identifier, '+'));
});

// ---------------------------------------------------------------------------
// Interaction with the shipped F3-V1 tool contract
// ---------------------------------------------------------------------------

it('leaves a cancelled reminder unclaimable, and a claimed one uncancellable', function () {
    [$user, , , $reminder] = reminderSubject();
    $dispatcher = app(ReminderDispatcher::class);

    // Cancelled before the claim: never selected.
    $reminder->forceFill(['status' => ReminderStatus::Cancelled->value])->save();
    expect($dispatcher->claimDue(10))->toBe([]);

    // Back to pending and claimed: `reminder.cancel@1` refuses, unchanged.
    $reminder->forceFill(['status' => ReminderStatus::Pending->value])->save();
    $dispatcher->claimDue(10);

    expect(fn () => app(ReminderService::class)->cancel($user, $reminder->id))
        ->toThrow(ToolDomainException::class);
});

it('never re-selects a terminal reminder', function () {
    [, , , $reminder] = reminderSubject();
    $dispatcher = app(ReminderDispatcher::class);

    foreach ([ReminderStatus::Sent, ReminderStatus::Failed, ReminderStatus::Cancelled] as $terminal) {
        $reminder->forceFill(['status' => $terminal->value, 'claimed_at' => null])->save();
        expect($dispatcher->claimDue(10))->toBe([]);
    }
});

// ---------------------------------------------------------------------------
// The command surface
// ---------------------------------------------------------------------------

it('queues one delivery job per claimed reminder', function () {
    reminderSubject();
    Queue::fake();

    $this->artisan('sanad:reminders:dispatch')->assertSuccessful();

    Queue::assertPushed(DeliverReminder::class, 1);
});

it('exposes the sweep as a command', function () {
    [, , , $reminder] = reminderSubject();
    app(ReminderDispatcher::class)->claimDue(10);
    $this->travelTo(CarbonImmutable::now()->addSeconds(400));

    $this->artisan('sanad:reminders:sweep')->assertSuccessful();

    expect($reminder->fresh()->status)->toBe(ReminderStatus::Pending);
});

// ---------------------------------------------------------------------------
// Privacy — identifiers only, never content
// ---------------------------------------------------------------------------

it('writes no title, phone number or message body into logs or the reminder row', function () {
    [, $account, , $reminder] = reminderSubject();

    $captured = [];
    Event::listen(
        MessageLogged::class,
        function ($event) use (&$captured): void {
            $captured[] = $event->message.'|'.json_encode($event->context, JSON_UNESCAPED_UNICODE);
        },
    );

    $dispatcher = app(ReminderDispatcher::class);

    // Exercise every logging path in one sequence: unproven, rejected, accepted.
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['error' => ['message' => 'boom']], 500)
        ->push(['error' => ['message' => 'nope']], 400)
        ->push(reminderAccepted(), 200),
    ]);

    $dispatcher->deliver($dispatcher->claimDue(10)[0]);
    $this->travelTo(CarbonImmutable::now()->addSeconds(400));
    $dispatcher->sweep();
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    [, , , $second] = reminderSubject();
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    $blob = implode("\n", $captured);

    expect($captured)->not->toBeEmpty()
        ->and($blob)->not->toContain('أتصل على البنك')
        ->and($blob)->not->toContain($account->external_identifier)
        ->and($blob)->not->toContain(ltrim($account->external_identifier, '+'))
        ->and($blob)->not->toContain('TEST_ACCESS_TOKEN')
        // The stored reason is a bounded code from the closed enum, never text.
        ->and($reminder->fresh()->last_error)->toBe('rejected')
        ->and($second->fresh()->status)->toBe(ReminderStatus::Sent);
});

it('stores only closed-enum reason codes in last_error', function () {
    $codes = array_map(
        static fn (ReminderFailureReason $r): string => $r->value,
        ReminderFailureReason::cases(),
    );

    [, , , $reminder] = reminderSubject(['remind_at' => CarbonImmutable::now()->subMinutes(90)]);
    Http::fake(['graph.facebook.com/*' => Http::response(reminderAccepted(), 200)]);

    $dispatcher = app(ReminderDispatcher::class);
    $dispatcher->deliver($dispatcher->claimDue(10)[0]);

    expect($reminder->fresh()->last_error)->toBeIn($codes)
        // `unknown` is the one non-terminal reason: it records a dispatch whose
        // outcome is neither proven nor disproven.
        ->and(ReminderFailureReason::Unknown->isTerminal())->toBeFalse()
        ->and(ReminderFailureReason::TooLate->isTerminal())->toBeTrue();
});

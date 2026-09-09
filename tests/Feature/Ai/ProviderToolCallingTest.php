<?php

declare(strict_types=1);

use App\Agents\AiAgentOrchestrator;
use App\Agents\MeteredAgentOrchestrator;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\ToolCapability;
use App\Enums\ToolSideEffect;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Support\Tools\ToolCatalog;
use App\Support\Tools\ToolTurnBudget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Phase F4 — the provider may PROPOSE a tool call; Sanad decides everything
 * else. The turn is bounded: at most 3 provider calls, 2 tool rounds and 3 tool
 * invocations per inbound message.
 */
beforeEach(function () {
    aiConfigure();
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'UTC'));
});

/** One OpenAI-compatible response proposing tool calls. */
function groqToolCalls(array $calls, string $text = ''): array
{
    return [
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => $text,
                'tool_calls' => array_map(static fn (array $c, int $i): array => [
                    'id' => 'call_'.$i,
                    'type' => 'function',
                    'function' => ['name' => $c[0], 'arguments' => json_encode($c[1], JSON_UNESCAPED_UNICODE)],
                ], $calls, array_keys($calls)),
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 6],
    ];
}

function groqText(string $content): array
{
    return [
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5],
    ];
}

/** Queue provider responses in order for one turn. */
function groqTurn(array ...$responses): void
{
    $sequence = Http::sequence();

    foreach ($responses as $response) {
        $sequence = $sequence->push($response);
    }

    Http::fake(['api.groq.com/*' => $sequence]);
}

/** A consented subscriber with a stored inbound message. */
function toolTurnSubject(string $text = 'حط عندي مهمة'): array
{
    $user = User::factory()->create(['is_admin' => false, 'timezone' => 'Asia/Hebron']);
    $account = ChannelAccount::factory()->for($user)->create();
    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);
    $message = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => $text,
    ]);

    foreach ([ToolCapability::TasksWrite, ToolCapability::RemindersWrite, ToolCapability::MemoryRead] as $capability) {
        f3Consent($user, $capability);
    }

    return [$user, $conversation, $message];
}

function runTurn(array $subject)
{
    [$user, $conversation, $message] = $subject;

    return app(AiAgentOrchestrator::class)->handle($user, $conversation, $message);
}

it('offers only the tools the server decided to expose, translated for the provider', function () {
    $catalog = app(ToolCatalog::class);
    $names = array_map(static fn ($t): string => $t->name, $catalog->expose());

    // Exactly the V1 execution surface, with provider-safe wire names.
    expect($names)->toBe([
        'memory_read__v1', 'memory_read__v2', 'memory_write__v1', 'memory_forget__v1',
        'task_create__v1', 'task_list__v1', 'task_complete__v1', 'reminder_cancel__v1', 'reminder_create__v2',
    ])
        // The frozen external-write contract is never described to a model.
        ->and($names)->not->toContain('reminder_create__v1');

    foreach ($catalog->executable() as $definition) {
        expect($definition->needsApproval())->toBeFalse($definition->key->value())
            ->and(in_array($definition->sideEffect, [ToolSideEffect::Read, ToolSideEffect::Write], true))->toBeTrue($definition->key->value());
    }

    // The parameters are the closed schema, bounds and all — never an open object.
    $create = collect($catalog->expose())->firstWhere('name', 'task_create__v1');

    expect($create->parameters['type'])->toBe('object')
        ->and($create->parameters['additionalProperties'])->toBeFalse()
        ->and(array_keys($create->parameters['properties']))->toBe(['title', 'due_on', 'priority'])
        ->and($create->parameters['required'])->toBe(['title'])
        ->and($create->parameters['properties']['title'])->toBe(['type' => 'string', 'maxLength' => 120])
        ->and($create->parameters['properties']['priority']['enum'])->toBe(['low', 'normal', 'high']);

    // The wire name round-trips back to the internal key, and nothing else does.
    expect($catalog->resolve('task_create__v1')?->key->value())->toBe('task.create@1')
        ->and($catalog->resolve('reminder_create__v1'))->toBeNull()
        ->and($catalog->resolve('task.create@1'))->toBeNull();
});

it('creates exactly one task when the model asks for one', function () {
    groqTurn(
        groqToolCalls([['task_create__v1', ['title' => 'أراجع البنك بكرا', 'due_on' => '2026-09-09']]]),
        groqText('تمام، سجّلت المهمة.'),
    );

    $reply = runTurn(toolTurnSubject('حط عندي مهمة أراجع البنك بكرا'));
    $task = Task::query()->sole();

    expect($reply->text)->toBe('تمام، سجّلت المهمة.')
        ->and($task->title)->toBe('أراجع البنك بكرا')
        ->and($task->due_at->toDateString())->toBe('2026-09-09')
        ->and(ToolInvocation::query()->where('status', 'succeeded')->count())->toBe(1)
        ->and($reply->metadata['tools'])->toBe(['rounds' => 1, 'invocations' => 1])
        ->and($reply->metadata['ai_calls'])->toHaveCount(2);
});

it('answers "what do I have today?" through task.list@1 without writing anything', function () {
    $subject = toolTurnSubject('شو علي اليوم؟');
    Task::factory()->count(2)->create(['user_id' => $subject[0]->id, 'due_at' => CarbonImmutable::now('UTC'), 'status' => 'pending']);
    Task::factory()->create(['user_id' => $subject[0]->id, 'status' => 'completed']);

    groqTurn(
        groqToolCalls([['task_list__v1', ['scope' => 'today']]]),
        groqText('عندك مهمتان اليوم.'),
    );

    $reply = runTurn($subject);
    $invocation = ToolInvocation::query()->sole();

    expect($reply->text)->toBe('عندك مهمتان اليوم.')
        ->and($invocation->tool_key)->toBe('task.list')
        ->and($invocation->side_effect)->toBe(ToolSideEffect::Read)
        ->and($invocation->output['total'])->toBe(2)
        ->and($invocation->output['tasks'])->toHaveCount(2)
        ->and($invocation->output['truncated'])->toBeFalse()
        // A read wrote nothing.
        ->and(Task::query()->where('status', 'completed')->count())->toBe(1);
});

it('creates exactly one reminder when the model asks for one', function () {
    groqTurn(
        groqToolCalls([['reminder_create__v2', ['title' => 'أتصل على البنك', 'remind_at' => '2026-09-09T09:00']]]),
        groqText('تمام، ذكّرتك بكرا الساعة 9.'),
    );

    $reply = runTurn(toolTurnSubject('ذكرني بكرا الساعة 9 أتصل على البنك'));
    $reminder = Reminder::query()->sole();

    expect($reply->text)->toBe('تمام، ذكّرتك بكرا الساعة 9.')
        ->and($reminder->title)->toBe('أتصل على البنك')
        ->and($reminder->remind_at->utc()->format('Y-m-d\TH:i'))->toBe('2026-09-09T09:00')
        ->and($reminder->status->value)->toBe('pending')
        ->and(Reminder::count())->toBe(1);
});

it('re-processing the same inbound message duplicates nothing', function () {
    $subject = toolTurnSubject('حط عندي مهمة أراجع البنك');

    // One sequence for all three passes: the same proposal, every time.
    groqTurn(
        groqToolCalls([['task_create__v1', ['title' => 'أراجع البنك']]]), groqText('تمام.'),
        groqToolCalls([['task_create__v1', ['title' => 'أراجع البنك']]]), groqText('تمام.'),
        groqToolCalls([['task_create__v1', ['title' => 'أراجع البنك']]]), groqText('تمام.'),
    );

    for ($i = 0; $i < 3; $i++) {
        runTurn($subject);
    }

    // Same stored message + same proposed call ⇒ the same slot, so one task,
    // one invocation, and the replays execute nothing.
    expect(Task::count())->toBe(1)
        ->and(ToolInvocation::count())->toBe(1)
        ->and(ToolInvocation::query()->sole()->version)->toBe(4);
});

it('cannot reach another subscriber task, whatever id the model proposes', function () {
    $subject = toolTurnSubject('خلص المهمة');
    $other = User::factory()->create();
    $theirTask = Task::factory()->create(['user_id' => $other->id, 'status' => 'pending']);

    groqTurn(
        groqToolCalls([['task_complete__v1', ['task_id' => $theirTask->id]]]),
        groqText('ما لقيت المهمة.'),
    );

    runTurn($subject);

    expect($theirTask->fresh()->status->value)->toBe('pending')
        ->and($theirTask->fresh()->completed_at)->toBeNull()
        ->and(ToolInvocation::query()->sole()->failure_kind->value)->toBe('not_found');
});

it('fails closed on a tool that was never exposed, and on one that does not exist', function () {
    $subject = toolTurnSubject('ذكرني');

    groqTurn(
        groqToolCalls([
            ['reminder_create__v1', ['title' => 'x', 'remind_at' => '2026-09-09T09:00']],   // frozen external write
            ['totally_made_up__v9', ['x' => 1]],                                            // never existed
        ]),
        groqText('ما قدرت أعمل هذا.'),
    );

    runTurn($subject);

    // Neither reached an executor, so neither created an invocation or a row.
    expect(ToolInvocation::count())->toBe(0)
        ->and(Reminder::count())->toBe(0);
});

it('refuses an oversized batch whole, never partially, and finishes with tools disabled', function () {
    $subject = toolTurnSubject('اعمل كل هذا');

    groqTurn(
        // Four calls at once: one more than the budget allows.
        groqToolCalls([
            ['task_create__v1', ['title' => 'أ']],
            ['task_create__v1', ['title' => 'ب']],
            ['task_create__v1', ['title' => 'ج']],
            ['task_create__v1', ['title' => 'د']],
        ]),
        groqText('طلبت أكثر من اللازم دفعة واحدة.'),
    );

    $reply = runTurn($subject);

    // NOTHING ran — not even the three that would have fit.
    expect(Task::count())->toBe(0)
        ->and(ToolInvocation::count())->toBe(0)
        ->and($reply->metadata['tools'])->toBe(['rounds' => 0, 'invocations' => 0])
        ->and($reply->text)->toBe('طلبت أكثر من اللازم دفعة واحدة.');

    // The model was told why, in a bounded machine-readable way.
    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->last();
    expect(json_encode($sent, JSON_UNESCAPED_UNICODE))->toContain(ToolTurnBudget::EXCEEDED);
});

it('allows two tool rounds and makes the third provider call final synthesis with tools OFF', function () {
    $subject = toolTurnSubject('حط مهمتين وبعدين قلي شو عندي');

    groqTurn(
        groqToolCalls([['task_create__v1', ['title' => 'أ']], ['task_create__v1', ['title' => 'ب']]]),
        groqToolCalls([['task_list__v1', ['scope' => 'open']]]),
        groqText('عندك مهمتان.'),
    );

    $reply = runTurn($subject);

    expect($reply->text)->toBe('عندك مهمتان.')
        ->and(Task::count())->toBe(2)
        ->and(ToolInvocation::count())->toBe(3)
        ->and($reply->metadata['tools'])->toBe(['rounds' => 2, 'invocations' => 3])
        ->and($reply->metadata['ai_calls'])->toHaveCount(3);

    // Call 3 is synthesis only: tools were not offered on it.
    $offered = array_map(static fn (array $c): bool => $c['tools_offered'], $reply->metadata['ai_calls']);
    expect($offered)->toBe([true, true, false]);

    // Three distinct slots of one message, never reused.
    expect(ToolInvocation::query()->orderBy('id')->pluck('call_index')->all())->toBe([1, 2, 3])
        ->and(ToolInvocation::query()->orderBy('id')->pluck('idempotency_key')->unique())->toHaveCount(3);
});

it('meters every real provider call and never only the first', function () {
    $subject = toolTurnSubject('حط عندي مهمة');

    groqTurn(
        groqToolCalls([['task_create__v1', ['title' => 'أ']]]),
        groqText('تمام.'),
    );

    app(MeteredAgentOrchestrator::class)->handle(...$subject);

    $rows = DB::table('usage_events')->where('type', 'ai_reply')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)   // one per provider call, not one per message
        // One row per PHYSICAL provider request: the logical call position and
        // the queue attempt that actually sent it.
        ->and($rows->pluck('idempotency_key')->all())->toBe([
            'ai_reply:message:'.$subject[2]->id.':call:1:attempt:1',
            'ai_reply:message:'.$subject[2]->id.':call:2:attempt:1',
        ])
        ->and($rows->sum('input_units'))->toBe(32)
        // The tool itself is metered on its own dimension, once.
        ->and(DB::table('usage_events')->where('type', 'tool_action')->count())->toBe(1);
});

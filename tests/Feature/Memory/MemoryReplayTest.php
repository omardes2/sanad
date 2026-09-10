<?php

declare(strict_types=1);

use App\Agents\AiAgentOrchestrator;
use App\Enums\MemoryCategory;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolReplayFailure;
use App\Exceptions\Ai\AiException;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Services\Memory\MemoryCipher;
use App\Services\Tools\ToolConsentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Phase G — what a QUEUE RETRY does to a memory read.
 *
 * `memory.read@2` stores only the shape of its result, never the memories. That
 * is the privacy contract and it does not bend. But an infrastructure retry must
 * not change what the subscriber gets: if the synthesis call dies after the read
 * ran, the retry replays the same invocation slot, and the model still has to be
 * able to answer «شو بتعرف عني؟».
 */
beforeEach(function () {
    aiConfigure();
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'UTC'));
});

// ---------------------------------------------------------------- helpers

/** A consented subscriber with one real memory and a stored inbound question. */
function replaySubject(string $memory = 'بحب القهوة سادة'): array
{
    $user = User::factory()->create(['is_admin' => false, 'timezone' => 'Asia/Hebron']);
    $account = ChannelAccount::factory()->for($user)->create();
    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);
    $message = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => 'شو بتعرف عني؟',
    ]);

    f3Consent($user, ToolCapability::MemoryRead);

    Memory::factory()->create([
        'user_id' => $user->id,
        'content' => $memory,
        'category' => MemoryCategory::Preference->value,
        'importance' => 5,
    ]);

    return [$user, $conversation, $message];
}

/** The provider payload proposing one `memory.read@2` call. */
function replayProposal(string $query = 'قهوة'): array
{
    return [
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
            'id' => 'call_0', 'type' => 'function',
            'function' => ['name' => 'memory_read__v2', 'arguments' => json_encode(['query' => $query], JSON_UNESCAPED_UNICODE)],
        ]]], 'finish_reason' => 'tool_calls']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3],
    ];
}

/** Every tool-role message body the provider was sent, across all requests. */
function replayToolMessages(): array
{
    $out = [];

    foreach (Http::recorded() as [$request]) {
        foreach ($request->data()['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) === 'tool') {
                $out[] = (string) ($message['content'] ?? '');
            }
        }
    }

    return $out;
}

// ------------------------------------------------------------------ test

it('gives the model the real memories again when the queue retries after a failed synthesis', function () {
    [$user, $conversation, $message] = replaySubject();

    Http::fake(['api.groq.com/*' => Http::sequence()
        // ATTEMPT 1: the model asks what Sanad remembers, the read runs…
        ->push(replayProposal())
        // …and the synthesis call dies, so the queue will retry the message.
        ->push(['error' => ['message' => 'server error']], 500)
        // ATTEMPT 2: the same proposal at the same slot — a REPLAY — then an answer.
        ->push(replayProposal())
        ->push([
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'بتحب القهوة سادة.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ]),
    ]);

    $orchestrator = app(AiAgentOrchestrator::class);

    expect(fn () => $orchestrator->handle($user, $conversation, $message))->toThrow(AiException::class);

    // The read really did run on attempt 1, and the model really did see it.
    expect(ToolInvocation::count())->toBe(1)
        ->and(implode("\n", replayToolMessages()))->toContain('بحب القهوة سادة');

    $before = [
        'invocations' => ToolInvocation::count(),
        'events' => DB::table('tool_invocation_events')->count(),
        'audits' => DB::table('audit_logs')->where('subject_type', ToolInvocation::class)->count(),
        'usage' => DB::table('usage_events')->where('type', 'tool_action')->count(),
    ];

    // THE RETRY.
    $reply = $orchestrator->handle($user, $conversation, $message);

    $onRetry = implode("\n", array_slice(replayToolMessages(), count($before) > 0 ? 1 : 0));

    expect($reply->text)->toBe('بتحب القهوة سادة.')
        // The model got usable memory content on the SECOND attempt too.
        ->and($onRetry)->toContain('بحب القهوة سادة')
        // And it was a replay, not a second execution: nothing was added.
        ->and(ToolInvocation::count())->toBe($before['invocations'])
        ->and(DB::table('tool_invocation_events')->count())->toBe($before['events'])
        ->and(DB::table('audit_logs')->where('subject_type', ToolInvocation::class)->count())->toBe($before['audits'])
        ->and(DB::table('usage_events')->where('type', 'tool_action')->count())->toBe($before['usage'])
        // The privacy contract is untouched: the row still holds only the shape.
        ->and(ToolInvocation::query()->sole()->output)->toBe(['memories_count' => 1, 'truncated' => false])
        ->and(json_encode(DB::table('tool_invocations')->pluck('output')->all(), JSON_UNESCAPED_UNICODE))
        ->not->toContain('القهوة');
});

it('tells the provider plainly that it cannot reach the memories, instead of passing storage metadata off as a result', function () {
    [$user, $conversation, $message] = replaySubject();

    Http::fake(['api.groq.com/*' => Http::sequence()
        // ATTEMPT 1: the read runs and the model sees the memory…
        ->push(replayProposal())
        // …then the synthesis call dies.
        ->push(['error' => ['message' => 'server error']], 500)
        // ATTEMPT 2: the same slot replays, and the model answers from whatever
        // the tool result says.
        ->push(replayProposal())
        ->push([
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ما بقدر أوصل لمعلوماتك المحفوظة حاليًا.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ]),
    ]);

    $orchestrator = app(AiAgentOrchestrator::class);

    expect(fn () => $orchestrator->handle($user, $conversation, $message))->toThrow(AiException::class);

    // Between the two attempts the subscriber withdraws consent.
    $this->actingAs($user);
    app(ToolConsentService::class)->revoke(
        $user->id,
        ToolCapability::MemoryRead,
        1,
        ToolConsentReason::SubscriberRequest,
    );

    $sentBefore = count(replayToolMessages());

    $reply = $orchestrator->handle($user, $conversation, $message);

    $onRetry = implode("\n", array_slice(replayToolMessages(), $sentBefore));

    expect($reply->text)->toBe('ما بقدر أوصل لمعلوماتك المحفوظة حاليًا.')
        // An EXPLICIT failure code, not a success carrying audit metadata.
        ->and($onRetry)->toContain('"ok":false')
        ->and($onRetry)->toContain(ToolReplayFailure::NotGranted->value)
        // Nothing about the memories survives: not the content, not the count,
        // not the projection that is sitting on the row.
        ->and($onRetry)->not->toContain('بحب القهوة سادة')
        ->and($onRetry)->not->toContain('memories_count')
        ->and($onRetry)->not->toContain('truncated')
        // Still one invocation, still succeeded, still redacted.
        ->and(ToolInvocation::count())->toBe(1)
        ->and(ToolInvocation::query()->sole()->output)->toBe(['memories_count' => 1, 'truncated' => false]);
});

it('tells the provider it cannot reach the memories when the key is gone, rather than answering that there are none', function () {
    [$user, $conversation, $message] = replaySubject();

    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(replayProposal())
        ->push(['error' => ['message' => 'server error']], 500)
        ->push(replayProposal())
        ->push([
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'تعذّر الوصول للذاكرة.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ]),
    ]);

    $orchestrator = app(AiAgentOrchestrator::class);

    expect(fn () => $orchestrator->handle($user, $conversation, $message))->toThrow(AiException::class);

    // The key is no longer usable when the retry runs.
    config(['memory.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
    app(MemoryCipher::class)->flush();

    $sentBefore = count(replayToolMessages());
    $orchestrator->handle($user, $conversation, $message);

    $onRetry = implode("\n", array_slice(replayToolMessages(), $sentBefore));

    // «You have no memories» would be a lie; «I cannot read them» is the truth.
    expect($onRetry)->toContain('"ok":false')
        ->and($onRetry)->toContain(ToolReplayFailure::RehydrationUnavailable->value)
        ->and($onRetry)->not->toContain('"memories":[]')
        ->and($onRetry)->not->toContain('memories_count')
        ->and($onRetry)->not->toContain('بحب القهوة سادة')
        ->and(ToolInvocation::count())->toBe(1);
});

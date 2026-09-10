<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Voice\VoiceClaim;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\Voice\VoiceNoteTranscriber;
use App\Services\Voice\VoiceTranscriptionSweeper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Testing-only probe: ONE transcription step per process, one machine-readable
 * line, so the PostgreSQL races run in genuinely separate processes with no
 * shared transaction and real row locks.
 *
 *   ingest     <wamid> <from> → ingested:<messagesWithThatWamid>
 *   claim      <messageId>    → claimed:<token>  |  claimed::<reason>
 *   dispatch   <messageId> <token> → <result>:<reason>:<providerRequests>
 *   transcribe <messageId>    → <result>:<reason>:<providerRequests>
 *   state      <messageId>    → <status>:<attempts>:<transcript|->
 *   sweep                     → swept:<recovered>
 *
 * `claim` and `dispatch` are separate because fencing is only meaningful if
 * holding a claim and acting on it can come apart in time — which is exactly
 * what a stalled worker does. `dispatch` takes the token because a worker with
 * no claim identity could not be fenced out, and that is the race under test.
 *
 * `providerRequests` is counted from the FAKED HTTP client, so "did this
 * process actually call the provider" is a recorded fact rather than something
 * inferred from a row another process may have moved between reads. That is the
 * number every claim in this phase is really about.
 *
 * Both external services are faked here: this probe exercises ownership and the
 * attempt budget, never a live network, and a separate process must not depend
 * on the environment holding real credentials.
 */
class VoiceTranscriptionProbe extends Command
{
    protected $signature = 'sanad:voice-transcription-probe {op} {args?*} {--outcome=ok} {--sleep=0}';

    protected $description = 'Testing only: perform one voice-transcription step and print the outcome';

    protected $hidden = true;

    public function handle(): int
    {
        // Configure BEFORE resolving anything: WhatsAppConfig is constructed
        // from config at resolution time, so a container resolution that
        // happened first would carry the environment's disabled channel.
        $this->prepare();

        /** @var list<string> $args */
        $args = (array) $this->argument('args');

        return match ((string) $this->argument('op')) {
            'ingest' => $this->ingest((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'claim' => $this->claim((int) ($args[0] ?? 0)),
            'dispatch' => $this->dispatch((int) ($args[0] ?? 0), (string) ($args[1] ?? '')),
            'transcribe' => $this->transcribe((int) ($args[0] ?? 0)),
            'state' => $this->state((int) ($args[0] ?? 0)),
            'sweep' => $this->sweep(),
            default => self::FAILURE,
        };
    }

    /**
     * Ingest one inbound voice note end to end, exactly as the webhook does.
     *
     * Several processes run this with the SAME wamid, which is the duplicate
     * redelivery a platform really does perform.
     */
    private function ingest(string $wamid, string $from): int
    {
        $envelope = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_123',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550000000', 'phone_number_id' => 'PROBE_PNID'],
                        'contacts' => [['profile' => ['name' => 'Probe'], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $wamid,
                            'timestamp' => (string) time(),
                            'type' => 'audio',
                            'audio' => [
                                'id' => 'probe-media',
                                'mime_type' => 'audio/ogg; codecs=opus',
                                'voice' => true,
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];

        $event = WebhookEvent::create([
            'provider' => 'whatsapp',
            // A DIFFERENT envelope hash per process: the same envelope replayed
            // is short-circuited earlier, and the race under test is the wamid.
            'external_event_id' => hash('sha256', $wamid.getmypid().uniqid('', true)),
            'payload' => $envelope,
            'status' => WebhookEventStatus::Received,
            'received_at' => now(),
        ]);

        app()->call([new ProcessWhatsAppWebhook($event->id), 'handle']);

        $this->line('ingested:'.Message::query()->where('external_message_id', $wamid)->count());

        return self::SUCCESS;
    }

    private function claim(int $messageId): int
    {
        $claim = app(VoiceNoteTranscriber::class)->claim($messageId);

        $this->line(is_string($claim) ? 'claimed::'.$claim : 'claimed:'.$claim->token);

        return self::SUCCESS;
    }

    private function dispatch(int $messageId, string $token): int
    {
        $this->stagger();

        return $this->report(fn () => app(VoiceNoteTranscriber::class)
            ->transcribeUnderClaim(new VoiceClaim($messageId, $token)));
    }

    private function transcribe(int $messageId): int
    {
        $this->stagger();

        return $this->report(fn () => app(VoiceNoteTranscriber::class)->transcribe($messageId));
    }

    /**
     * Run one step and print what happened, with the number of PAID requests
     * THIS process actually made — a recorded fact, not something inferred
     * from a row another process may have moved between reads.
     */
    private function report(callable $step): int
    {
        try {
            $outcome = $step();
            $result = $outcome->result;
            $reason = $outcome->reason?->value ?? $outcome->note ?? '-';
        } catch (Throwable $e) {
            // A rethrown transient media failure: the queue's business, and
            // here just another non-provider outcome.
            $result = 'threw';
            $reason = class_basename($e);
        }

        $requests = collect(Http::recorded())
            ->filter(static fn (array $pair): bool => str_contains((string) $pair[0]->url(), '/audio/transcriptions'))
            ->count();

        $this->line($result.':'.$reason.':'.$requests);

        return self::SUCCESS;
    }

    /** A deliberate stagger, so several processes are inside the pipeline at once. */
    private function stagger(): void
    {
        if (($micro = (int) $this->option('sleep')) > 0) {
            usleep($micro);
        }
    }

    private function state(int $messageId): int
    {
        $message = Message::query()->find($messageId);

        if ($message === null) {
            $this->line('missing');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s:%d:%s',
            $message->transcription_status?->value ?? '-',
            (int) $message->transcription_attempts,
            $message->text_content ?? '-',
        ));

        return self::SUCCESS;
    }

    private function sweep(): int
    {
        $this->line('swept:'.app(VoiceTranscriptionSweeper::class)->sweep()['recovered']);

        return self::SUCCESS;
    }

    private function prepare(): void
    {
        config([
            // ONE STEP PER PROCESS. Under PHPUnit the parent exports
            // QUEUE_CONNECTION=sync, and a child process inherits it — which
            // would run the whole chain inline (transcribe, then reply, then a
            // real send) and make a test about ingestion silently a test about
            // everything. The probe discards what it dispatches; each step is
            // invoked explicitly by the test that means to exercise it.
            'queue.default' => 'null',
            'whatsapp.enabled' => true,
            'whatsapp.access_token' => 'PROBE_TOKEN',
            'whatsapp.phone_number_id' => 'PROBE_PNID',
            'whatsapp.graph_base_url' => 'https://graph.facebook.com',
            'whatsapp.graph_version' => 'v21.0',
            'voice.enabled' => true,
            'voice.lease_seconds' => 300,
            'voice.max_attempts' => 2,
            'ai.catalog_source' => 'config',
            'ai.providers.groq.api_key' => 'PROBE_KEY',
            'ai.providers.groq.transcription_model' => 'probe-transcribe',
        ]);

        $audio = (string) file_get_contents(base_path('tests/Fixtures/voice/voice-note-7500ms.ogg'));

        Http::fake([
            // Step 1: media metadata, carrying a signed URL.
            'graph.facebook.com/v21.0/*' => Http::response([
                'url' => 'https://lookaside.example/media/probe',
                'mime_type' => 'audio/ogg; codecs=opus',
                'file_size' => strlen($audio),
                'id' => 'probe-media',
            ], 200),
            // Step 2: the bytes.
            // A CLOSURE, not a shared Response: a streamed body is consumed
            // once, and two attempts at the same voice note must each get their
            // own bytes rather than the second finding an exhausted stream.
            'lookaside.example/*' => static fn () => Http::response($audio, 200),
            // Step 3: the paid request.
            'api.groq.com/*' => match ((string) $this->option('outcome')) {
                // 5xx: the provider may have processed before failing to
                // answer, so the outcome is unproven — the retry-budget case.
                'unknown' => Http::response(['error' => 'boom'], 500),
                'rejected' => Http::response(['error' => 'nope'], 400),
                'empty' => Http::response(['text' => ''], 200),
                default => Http::response(['text' => 'مرحبا، هذه رسالة صوتية.', 'language' => 'ar'], 200),
            },
        ]);
    }
}

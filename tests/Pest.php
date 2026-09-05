<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use App\Data\InboundMessageData;
use App\Enums\ChannelType;
use App\Enums\MessageType;
use App\Enums\SubscriptionStatus;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessInboundMessage;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\ChannelAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Testing\TestResponse;

/**
 * Create a plan with an AI-reply limit config.
 *
 * @param  array{daily: ?int, monthly: ?int, weight?: int}  $aiLimit
 * @param  array<string, mixed>  $attrs
 */
function billingPlan(array $aiLimit = ['daily' => 5, 'monthly' => 50, 'weight' => 1], array $attrs = []): Plan
{
    return Plan::create(array_merge([
        'name' => 'Test Plan',
        'slug' => 'test-'.str()->random(8),
        'price' => 0,
        'currency' => 'ILS',
        'billing_period' => 'monthly',
        'trial_days' => 0,
        'limits' => ['ai_reply' => $aiLimit],
        'features' => [],
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 0,
    ], $attrs));
}

/**
 * Create a non-admin subscriber, optionally with an active subscription on $plan.
 *
 * @param  array<string, mixed>  $subAttrs
 */
function billingSubscriber(?Plan $plan = null, array $subAttrs = []): User
{
    $user = User::factory()->create(['is_admin' => false]);

    if ($plan !== null) {
        Subscription::create(array_merge([
            'subscriber_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ], $subAttrs));
    }

    return $user->refresh();
}

/**
 * Create a demo user with a Web channel account for pipeline tests.
 */
function pipelineWebAccount(): ChannelAccount
{
    $user = User::factory()->create();

    return ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::Web,
        'external_identifier' => 'web-user-'.$user->id,
    ]);
}

/**
 * Build an inbound web message DTO for the given account.
 */
function pipelineInbound(ChannelAccount $account, string $externalId, string $text = 'مرحبا'): InboundMessageData
{
    return new InboundMessageData(
        channel: ChannelType::Web,
        externalMessageId: $externalId,
        externalUserId: $account->external_identifier,
        type: MessageType::Text,
        text: $text,
    );
}

/**
 * Execute the ProcessInboundMessage job synchronously (deps auto-injected).
 */
function pipelineRunJob(int $messageId): void
{
    app()->call([new ProcessInboundMessage($messageId), 'handle']);
}

// ---------------------------------------------------------------------------
// WhatsApp test helpers
// ---------------------------------------------------------------------------

/**
 * Configure the WhatsApp integration with deterministic test credentials.
 *
 * @param  array<string, mixed>  $overrides
 */
function whatsappConfigure(array $overrides = []): void
{
    config(array_merge([
        'whatsapp.enabled' => true,
        'whatsapp.graph_base_url' => 'https://graph.facebook.com',
        'whatsapp.graph_version' => 'v21.0',
        'whatsapp.access_token' => 'TEST_ACCESS_TOKEN',
        'whatsapp.app_secret' => 'test-app-secret',
        'whatsapp.verify_token' => 'test-verify-token',
        'whatsapp.phone_number_id' => 'PNID_123',
        'whatsapp.business_account_id' => 'WABA_123',
        'whatsapp.request_timeout' => 10,
    ], $overrides));
}

/**
 * Compute the X-Hub-Signature-256 header for a raw body using the configured
 * app secret.
 */
function whatsappSignature(string $raw): string
{
    return 'sha256='.hash_hmac('sha256', $raw, (string) config('whatsapp.app_secret'));
}

/**
 * POST a raw body to the webhook with an optional signature header.
 */
function postWhatsAppRaw(string $raw, ?string $signature): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== null) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
    }

    return test()->call('POST', '/webhooks/whatsapp', [], [], [], $server, $raw);
}

/**
 * POST a validly-signed envelope (array) to the webhook.
 *
 * @param  array<string, mixed>  $envelope
 */
function postWhatsAppEnvelope(array $envelope): TestResponse
{
    $raw = json_encode($envelope, JSON_UNESCAPED_UNICODE);

    return postWhatsAppRaw($raw, whatsappSignature($raw));
}

/**
 * Build a WhatsApp inbound TEXT envelope.
 *
 * @param  array<string, mixed>  $opts
 * @return array<string, mixed>
 */
function whatsappTextEnvelope(string $wamid, string $from, string $text, array $opts = []): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => $opts['waba_id'] ?? 'WABA_123',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'display_phone_number' => '15550000000',
                        'phone_number_id' => $opts['phone_number_id'] ?? 'PNID_123',
                    ],
                    'contacts' => [[
                        'profile' => ['name' => $opts['name'] ?? 'Tester'],
                        'wa_id' => $from,
                    ]],
                    'messages' => [[
                        'from' => $from,
                        'id' => $wamid,
                        'timestamp' => (string) ($opts['timestamp'] ?? 1757000000),
                        'type' => $opts['type'] ?? 'text',
                        'text' => ['body' => $text],
                    ]],
                ],
            ]],
        ]],
    ];
}

/**
 * Build a WhatsApp status envelope for a provider message id.
 *
 * @param  array<string, mixed>  $opts
 * @return array<string, mixed>
 */
function whatsappStatusEnvelope(string $providerMessageId, string $status, array $opts = []): array
{
    $statusEntry = [
        'id' => $providerMessageId,
        'status' => $status,
        'timestamp' => (string) ($opts['timestamp'] ?? 1757000100),
        'recipient_id' => $opts['recipient_id'] ?? '970599000001',
    ];

    if ($status === 'failed') {
        $statusEntry['errors'] = [['code' => $opts['error_code'] ?? 131047, 'title' => 'error']];
    }

    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => $opts['waba_id'] ?? 'WABA_123',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'display_phone_number' => '15550000000',
                        'phone_number_id' => $opts['phone_number_id'] ?? 'PNID_123',
                    ],
                    'statuses' => [$statusEntry],
                ],
            ]],
        ]],
    ];
}

/**
 * Configure the AI orchestrator with a deterministic Groq test provider.
 *
 * @param  array<string, mixed>  $overrides
 */
function aiConfigure(array $overrides = []): void
{
    config(array_merge([
        'ai.enabled' => true,
        'ai.provider' => 'groq',
        'ai.failure_behavior' => 'retry',
        'ai.history_limit' => 10,
        'ai.timeout' => 20,
        'ai.max_output_tokens' => 600,
        'ai.temperature' => 0.5,
        'ai.fallback_message' => 'عذرًا، حدث خطأ مؤقت.',
        'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
        'ai.providers.groq.api_key' => 'test-groq-key',
        'ai.providers.groq.model' => 'llama-3.3-70b-versatile',
    ], $overrides));
}

/**
 * Store an envelope as a WebhookEvent and run the processing job synchronously.
 *
 * @param  array<string, mixed>  $envelope
 */
function runWhatsAppWebhook(array $envelope): WebhookEvent
{
    $event = WebhookEvent::create([
        'provider' => 'whatsapp',
        'external_event_id' => hash('sha256', json_encode($envelope).uniqid('', true)),
        'payload' => $envelope,
        'status' => WebhookEventStatus::Received,
        'received_at' => now(),
    ]);

    app()->call([new ProcessWhatsAppWebhook($event->id), 'handle']);

    return $event->fresh();
}

/**
 * Create a user + WhatsApp channel account for the given E.164 number.
 */
function whatsappAccount(string $e164 = '+970599000001'): ChannelAccount
{
    $user = User::factory()->create();

    return ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => $e164,
    ]);
}

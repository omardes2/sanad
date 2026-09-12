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

use App\Contracts\Ai\AiProvider as AiProviderContract;
use App\Contracts\Ai\SupportsChat;
use App\Contracts\Ai\SupportsTranscription;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Data\Ai\TranscriptionRequest;
use App\Data\Ai\TranscriptionResult;
use App\Data\InboundMessageData;
use App\Enums\AiOperation;
use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Enums\CostSource;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Enums\TranscriptionStatus;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessInboundMessage;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\ChannelAccount;
use App\Models\FollowUp;
use App\Models\Plan;
use App\Models\Reminder;
use App\Models\ReminderSchedule;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Ai\AiManager;
use App\Services\FollowUps\FollowUpAskMaterialiser;
use App\Services\FollowUps\FollowUpService;
use App\Services\Rbac\RbacSynchronizer;
use App\Services\Reminders\ReminderMaterialiser;
use App\Services\Reminders\ReminderScheduleService;
use App\Services\Settings\SettingsRepository;
use App\Support\Rbac\Role;
use Illuminate\Support\Facades\Http;
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

// ---- RBAC (Phase C0) --------------------------------------------------------

/**
 * Sync roles/permissions from the code registry into the test database (what
 * `sanad:rbac:bootstrap --apply` does), without promoting anyone.
 */
function rbacSync(): void
{
    $rbac = app(RbacSynchronizer::class);
    $rbac->apply($rbac->plan());
}

/**
 * A dashboard account holding exactly one role (is_admin stays false so the
 * role, not the legacy flag, is what grants access).
 */
function userWithRole(Role $role, array $attrs = []): User
{
    rbacSync();

    $user = User::factory()->create(array_merge(['is_admin' => false], $attrs));
    $user->assignRole($role->value);

    return $user->fresh();
}

// ---- Settings (Phase C1) ------------------------------------------------------

function settings(): SettingsRepository
{
    return app(SettingsRepository::class);
}

// ---- Credentials / health (Phase C3) -------------------------------------------

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ProviderCredential;
use App\Models\ProviderHealthCheck;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Settings\SettingsCache;
use App\Support\Security\SecretString;
use Carbon\CarbonImmutable;

/**
 * A fresh CREDENTIALS_KEY value (base64 of 32 random bytes).
 */
function c3Key(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

/**
 * Point the vault at a key (and optional previous keys) for this test.
 */
function c3VaultOn(?string $key = null, string $previous = ''): string
{
    $key ??= c3Key();
    config(['credentials.key' => $key, 'credentials.previous_keys' => $previous, 'credentials.cipher' => 'aes-256-gcm']);

    return $key;
}

function c3VaultOff(): void
{
    config(['credentials.key' => null, 'credentials.previous_keys' => '']);
}

/**
 * Database catalog with two configured providers: groq (preferred via
 * AI_PROVIDER, priority 100) and openai (priority 10), one chat model each.
 *
 * @return array{groq: AiProvider, openai: AiProvider, llama: AiModel, mini: AiModel}
 */
function c3Catalog(): array
{
    aiConfigure([
        'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.model' => 'gpt-4.1-mini',
        'ai.catalog_source' => 'database',
    ]);

    $groq = AiProvider::factory()->create(['key' => 'groq', 'driver' => 'groq', 'priority' => 100, 'credentials_ref' => 'GROQ_API_KEY']);
    $openai = AiProvider::factory()->create(['key' => 'openai', 'driver' => 'openai', 'priority' => 10, 'credentials_ref' => 'OPENAI_API_KEY']);
    $llama = AiModel::factory()->for($groq, 'provider')->create(['external_id' => 'llama-3.3-70b-versatile', 'priority' => 5]);
    $mini = AiModel::factory()->for($openai, 'provider')->create(['external_id' => 'gpt-4.1-mini']);

    CatalogCache::flush();

    return compact('groq', 'openai', 'llama', 'mini');
}

/**
 * Record a successful auth verification for a credential (what a passing
 * Test Connection on that row writes), so the normal activation path is open.
 */
function c3Verify(ProviderCredential $credential, string $status = 'ok', ?CarbonImmutable $at = null): ProviderHealthCheck
{
    return ProviderHealthCheck::query()->create([
        'provider_id' => $credential->provider_id, 'kind' => 'auth', 'trigger' => 'manual', 'status' => $status,
        'credential_id' => $credential->id, 'credential_source' => 'vault', 'checked_by_ref' => 'user:test',
        'checked_at' => $at ?? CarbonImmutable::now(),
    ]);
}

// ---- Routing cutover (Phase C4) --------------------------------------------------

/**
 * A successful ENV-credential auth probe for a provider, carrying the
 * fingerprint snapshot of the key the runtime uses (what a passing Test
 * Connection writes) — the exact-credential readiness proof.
 */
function c4EnvHealth(AiProvider $provider, ?string $secret = null, ?CarbonImmutable $at = null): ProviderHealthCheck
{
    $secret ??= (string) config("ai.providers.{$provider->key}.api_key");

    return ProviderHealthCheck::query()->create([
        'provider_id' => $provider->id, 'kind' => 'auth', 'trigger' => 'manual', 'status' => 'ok',
        'credential_id' => null, 'credential_source' => 'env', 'checked_by_ref' => 'user:test',
        'details' => ['credential_fingerprint' => SecretString::fingerprintOf($secret)],
        'checked_at' => $at ?? CarbonImmutable::now(),
    ]);
}

/**
 * Stage-C ready state: the database catalog mirrors the config route (groq
 * preferred, openai second), the source is pinned to `config`, and both env
 * keys have a fresh auth proof.
 *
 * @return array{groq: AiProvider, openai: AiProvider, llama: AiModel, mini: AiModel}
 */
function c4Catalog(): array
{
    $fx = c3Catalog();
    config(['ai.catalog_source' => 'config', 'ai.routing.mode' => 'env', 'ai.overrides.catalog_source' => null, 'ai.overrides.routing_mode' => null]);
    c4EnvHealth($fx['groq']);
    c4EnvHealth($fx['openai']);
    CatalogCache::flush();
    app(SettingsCache::class)->flush();

    return $fx;
}

/**
 * Phase D — one ledger row for the finance tests (defaults: priced model_price,
 * system-attributed, inside 2026-09). Shared here so every Finance test file can run alone.
 *
 * @param  array<string, mixed>  $attrs
 */
function financeRow(array $attrs): UsageEvent
{
    $cost = (string) ($attrs['total_cost'] ?? '0.000000');

    return UsageEvent::factory()->create(array_merge([
        'user_id' => null,
        'subscriber_id' => null,
        'plan_id' => null,
        'plan_slug' => null,
        'type' => 'ai_reply',
        'operation' => 'chat',
        'channel' => 'web',
        'provider' => 'groq',
        'model' => 'llama-3.3-70b-versatile',
        'input_units' => 10,
        'output_units' => 5,
        'cached_units' => 0,
        'cost' => $cost,
        'provider_cost' => $cost,
        'communication_cost' => '0.000000',
        'external_cost' => '0.000000',
        'total_cost' => $cost,
        'currency' => 'USD',
        'cost_source' => CostSource::ModelPrice,
        'occurred_at' => CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC'),
    ], $attrs));
}

// ---- Customer payments (Phase E1) -----------------------------------------------

use App\Data\Payments\ManualPaymentInput;
use App\Data\Payments\RefundInput;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Services\Payments\CustomerPaymentService;
use App\Services\Payments\RefundService;

/**
 * Record a manual payment through the service (console actor unless a user is
 * authenticated). Defaults: 100.00 USD received one hour ago, fee UNKNOWN.
 *
 * @param  array<string, mixed>  $overrides  ManualPaymentInput constructor arguments
 */
function e1Payment(User $subscriber, array $overrides = []): CustomerPayment
{
    return app(CustomerPaymentService::class)->recordManual(new ManualPaymentInput(...array_merge([
        'subscriberId' => $subscriber->id,
        'idempotencyKey' => 'test:'.str()->random(12),
        'amount' => '100.00',
        'currency' => 'USD',
        'receivedAt' => CarbonImmutable::now('UTC')->subHour(),
    ], $overrides)));
}

/**
 * Record a refund through the service. Defaults: 10.00, refunded now, reason `test`.
 *
 * @param  array<string, mixed>  $overrides  RefundInput constructor arguments
 */
function e1Refund(CustomerPayment $payment, array $overrides = []): CustomerRefund
{
    return app(RefundService::class)->record(new RefundInput(...array_merge([
        'customerPaymentId' => $payment->id,
        'idempotencyKey' => 'refund:'.str()->random(12),
        'amount' => '10.00',
        'refundedAt' => CarbonImmutable::now('UTC'),
        'reasonCode' => 'test',
    ], $overrides)));
}

// ---- Cost invoices & reconciliation (Phase E2) ------------------------------------

use App\Data\Reconciliation\CostInvoiceInput;
use App\Data\Reconciliation\EvidenceAllocation;
use App\Data\Reconciliation\InvoiceLineInput;
use App\Data\Reconciliation\ReconciliationInput;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Models\CostInvoice;
use App\Models\CostInvoiceLine;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Support\Reconciliation\ReconciliationRules;

/** The AI provider row a provider-component invoice must name (created once per test). */
function e2Provider(string $key = 'groq'): AiProvider
{
    return AiProvider::query()->where('key', $key)->first() ?? AiProvider::factory()->create(['key' => $key, 'driver' => $key, 'priority' => 100]);
}

/**
 * Record a DRAFT invoice through the service. Defaults: provider/groq, 100 USD, covering August 2026.
 *
 * @param  array<string, mixed>  $overrides  CostInvoiceInput constructor arguments
 */
function e2Invoice(array $overrides = []): CostInvoice
{
    if (($overrides['component'] ?? 'provider') === 'provider' && in_array($overrides['counterpartyKey'] ?? 'groq', ['groq', 'openai'], true)) {
        e2Provider($overrides['counterpartyKey'] ?? 'groq'); // only the known catalog keys are auto-created
    }

    return app(CostInvoiceService::class)->recordDraft(new CostInvoiceInput(...array_merge([
        'component' => 'provider',
        'counterpartyKey' => 'groq',
        'idempotencyKey' => 'inv:'.str()->random(12),
        'issuedAt' => CarbonImmutable::parse('2026-09-02', 'UTC'),
        'periodStart' => CarbonImmutable::parse('2026-08-01', 'UTC'),
        'periodEnd' => CarbonImmutable::parse('2026-09-01', 'UTC'),
        'currency' => 'USD',
        'totalAmount' => '100.000000',
    ], $overrides)));
}

/**
 * Add a signed line. Defaults: next line number, service, 100.
 *
 * @param  array<string, mixed>  $overrides  InvoiceLineInput constructor arguments
 */
function e2Line(CostInvoice $invoice, array $overrides = []): CostInvoiceLine
{
    $next = (int) CostInvoiceLine::query()->where('cost_invoice_id', $invoice->id)->max('line_no') + 1;

    return app(CostInvoiceService::class)->addLine(new InvoiceLineInput(...array_merge([
        'costInvoiceId' => $invoice->id,
        'lineNo' => $next,
        'kind' => 'service',
        'descriptionCode' => 'api_usage',
        'amount' => '100.000000',
    ], $overrides)));
}

/** A CONFIRMED invoice with the given signed lines (kind => amount pairs), total = Σ lines. */
function e2ConfirmedInvoice(array $lines = ['service' => '100.000000'], array $overrides = []): CostInvoice
{
    $total = 0;
    foreach ($lines as $amount) {
        $total += CostReconciliationService::scaledOf($amount);
    }
    $invoice = e2Invoice($overrides + ['totalAmount' => ReconciliationRules::format($total)]);
    $n = 1;
    foreach ($lines as $kind => $amount) {
        e2Line($invoice, ['lineNo' => $n++, 'kind' => is_string($kind) ? preg_replace('/\d+$/', '', $kind) : 'service', 'amount' => $amount]);
    }

    return app(CostInvoiceService::class)->confirm($invoice->id, $invoice->fresh()->stateToken());
}

/**
 * Reconcile a scope from invoice evidence. Defaults: provider/groq, 2026-08, USD, no previous reconciliation.
 *
 * @param  list<array{0: int, 1: string, 2?: int}>  $allocations  [lineId, amount, fxRateId?]
 * @param  array<string, mixed>  $overrides  ReconciliationInput constructor arguments
 */
function e2Reconcile(array $allocations, array $overrides = []): CostReconciliation
{
    return app(CostReconciliationService::class)->reconcile(new ReconciliationInput(...array_merge([
        'component' => 'provider',
        'counterpartyKey' => 'groq',
        'month' => '2026-08',
        'currency' => 'USD',
        'expectedCurrentReconciliationId' => null,
        'source' => 'invoice',
        'allocations' => array_map(static fn (array $a) => new EvidenceAllocation($a[0], $a[1], $a[2] ?? null), $allocations),
        'reasonCode' => 'monthly',
    ], $overrides)));
}

/** The rule name an E2 service refuses with, or "none". */
/** A fresh opaque idempotency key for one adjustment write (E5.2b: every new adjustment requires one). */
function e2Key(): string
{
    return 'adj:'.str()->random(16);
}

function e2Rule(callable $fn): string
{
    try {
        $fn();
    } catch (ReconciliationRuleException $e) {
        return $e->rule;
    }

    return 'none';
}

// ---- FX & reporting currency (Phase E3) ---------------------------------------------

use App\Data\Fx\RecordRateInput;
use App\Data\Fx\ReportingConversionInput;
use App\Exceptions\Fx\FxRuleException;
use App\Models\FxConversion;
use App\Models\FxPair;
use App\Models\FxRate;
use App\Models\FxRateScope;
use App\Services\Fx\FxPairBook;
use App\Services\Fx\FxRateBook;
use App\Services\Fx\ReportingConversionService;

/** The canonical pair for two currencies in the given official orientation (created once). */
function fxPair(string $base = 'USD', string $quote = 'ILS'): FxPair
{
    return app(FxPairBook::class)->find($base, $quote) ?? app(FxPairBook::class)->create($base, $quote);
}

/**
 * Record a manual quote revision. Defaults: USD/ILS 3.650000000000 on 2026-08-10, no previous revision.
 *
 * @param  array<string, mixed>  $overrides  RecordRateInput constructor arguments
 */
function fxRate(array $overrides = []): FxRate
{
    $base = $overrides['baseCurrency'] ?? 'USD';
    $quote = $overrides['quoteCurrency'] ?? 'ILS';
    fxPair($base, $quote);

    return app(FxRateBook::class)->record(new RecordRateInput(...array_merge([
        'baseCurrency' => $base,
        'quoteCurrency' => $quote,
        'rateDate' => '2026-08-10',
        'rate' => '3.650000000000',
        'evidenceRef' => 'boi:2026-08-10',
    ], $overrides)));
}

/**
 * Freeze a reporting conversion with an explicit rate id.
 *
 * @param  array<string, mixed>  $overrides  ReportingConversionInput constructor arguments
 */
function fxConvert(string $subjectType, int $subjectId, string $target, int $fxRateId, array $overrides = []): FxConversion
{
    return app(ReportingConversionService::class)->convert(new ReportingConversionInput(...array_merge([
        'subjectType' => $subjectType,
        'subjectId' => $subjectId,
        'targetCurrency' => $target,
        'fxRateId' => $fxRateId,
    ], $overrides)));
}

/** The rule name an E3 service refuses with, or "none". */
function fxRule(callable $fn): string
{
    try {
        $fn();
    } catch (FxRuleException $e) {
        return $e->rule;
    }

    return 'none';
}

// ---- Period close (Phase E4) ---------------------------------------------------------

use App\Exceptions\Close\CloseBlockedException;
use App\Exceptions\Close\CloseRuleException;
use App\Models\FinancePeriodClose;
use App\Services\Close\PeriodCloseService;
use App\Services\Fx\ReportingCurrencyService;

/**
 * A fully closable August 2026 in reporting currency USD:
 *  payments: 100.00 USD (fee 3.00) native; 365.00 ILS (fee 3.65) converted at 3.65 ⇒ 100.00 (fee 1.00)
 *  refund:   10.00 USD native
 *  ledger:   provider groq 50.000000 USD priced ⇒ expected provider = groq
 *  cost:     groq reconciliation 60.000000 USD (native) + adjustment −5.000000; communication and external CONFIRMED ZERO
 *  ⇒ Gross 200.00 · Refunds 10.00 · Net 190.00 · Fees 4.00 · Net after fees 186.00 · Cost 55.000000 · Contribution 131.000000
 *
 * @return array<string, mixed>
 */
function closableMonth(): array
{
    config(['billing.cost_currency' => 'USD']);
    $subscriber = billingSubscriber();
    $usd = e1Payment($subscriber, ['amount' => '100.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC'), 'gatewayFeeAmount' => '3.00', 'feeCurrency' => 'USD']);
    $ils = e1Payment($subscriber, ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC'), 'gatewayFeeAmount' => '3.65', 'feeCurrency' => 'ILS']);
    $refund = e1Refund($usd, ['amount' => '10.00', 'refundedAt' => CarbonImmutable::parse('2026-08-12 10:00:00', 'UTC')]);
    $rate = fxRate(['rate' => '3.65', 'rateDate' => '2026-08-10']);
    $conversion = fxConvert('customer_payment', $ils->id, 'USD', $rate->id);

    financeRow(['provider' => 'groq', 'provider_cost' => '50.000000', 'total_cost' => '50.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-15 10:00:00', 'UTC')]);
    $invoice = e2ConfirmedInvoice(['service' => '60.000000']);
    $reconciliation = e2Reconcile([[$invoice->lines()->first()->id, '60.000000']]);
    $adjustment = app(CostReconciliationService::class)->adjust($reconciliation->id, '-5.000000', 'credit_note', 'cn:1', e2Key());
    $zero = fn (string $component, string $cp) => e2Reconcile([], ['component' => $component, 'counterpartyKey' => $cp, 'source' => 'confirmed_zero', 'reasonCode' => 'none', 'evidenceRef' => 'att:'.$component, 'typedConfirmation' => 'ZERO']);
    $communication = $zero('communication', 'meta-whatsapp');
    $external = $zero('external', 'none-declared');

    return compact('subscriber', 'usd', 'ils', 'refund', 'rate', 'conversion', 'invoice', 'reconciliation', 'adjustment', 'communication', 'external');
}

/** Close a month as the console actor (super_admin semantics in tests come from acting users where relevant). */
function closeMonth(string $month = '2026-08', ?int $expected = null, ?string $key = null): FinancePeriodClose
{
    return app(PeriodCloseService::class)->close($month, $expected, $key ?? 'close:'.str()->random(10), 'CLOSE '.$month);
}

/** Change the reporting currency through its race-safe writer, stating the value the caller just read (E5.2c). */
function rcSet(string $code, ?string $reason = null): string
{
    $service = app(ReportingCurrencyService::class);

    return $service->change($code, $code, $service->current(), $reason);
}

/** A fresh opaque idempotency key for one reopen (E5.2c: every reopen requires one). */
function e4Key(): string
{
    return 'reopen:'.str()->random(16);
}

/** The rule name an E4 service refuses with, "blocked:<codes>" for a blocked close, or "none". */
function closeRule(callable $fn): string
{
    try {
        $fn();
    } catch (CloseRuleException $e) {
        return $e->rule;
    } catch (CloseBlockedException $e) {
        return 'blocked:'.implode('|', array_map(static fn (string $c): string => explode(' ', $c)[0], $e->conditions));
    }

    return 'none';
}

// ---- Payments operational UI (Phase E5.2a) ---------------------------------------------

/** A subscription event of this subscriber carrying a valid service period (the subscriber's subscription is reused). */
function periodEvent(User $user, string $start = '2026-09-01', string $end = '2026-10-01'): SubscriptionEvent
{
    $subscription = Subscription::query()->firstOrCreate(['subscriber_id' => $user->id], ['plan_id' => (Plan::query()->first() ?? billingPlan())->id, 'status' => 'active', 'started_at' => now()]);

    return SubscriptionEvent::query()->create([
        'subscription_id' => $subscription->id, 'subscriber_id' => $user->id, 'event_type' => 'extended', 'from_status' => 'active', 'to_status' => 'active',
        'to_period_start' => CarbonImmutable::parse($start, 'UTC'), 'to_period_end' => CarbonImmutable::parse($end, 'UTC'), 'effective_at' => now(), 'source' => 'admin', 'actor_ref' => 'console',
    ]);
}

use App\Models\AuditLog;
use Symfony\Component\Process\Process;

// ---- PostgreSQL race helpers for the payment probes (E1 / E5.2a) ------------------

/** A fresh opaque idempotency key for one allocation write (E5.2a: every new allocation requires one). */
function e1Key(): string
{
    return 'k:'.str()->random(16);
}

function e1Run(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:payment-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/** @return list<string> */
function e1Outcomes(array $processes): array
{
    $outcomes = [];
    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

function e1Cleanup(User $user, ?Plan $plan = null): void
{
    $paymentIds = CustomerPayment::query()->where('subscriber_id', $user->id)->pluck('id');
    $refundIds = CustomerRefund::query()->whereIn('customer_payment_id', $paymentIds)->pluck('id');
    DB::table('refund_allocations')->whereIn('customer_refund_id', $refundIds)->delete();
    DB::table('payment_allocations')->whereIn('customer_payment_id', $paymentIds)->delete();
    DB::table('customer_refunds')->whereIn('id', $refundIds)->delete();
    DB::table('customer_payment_events')->whereIn('customer_payment_id', $paymentIds)->delete();
    AuditLog::where('subject_type', (new CustomerPayment)->getMorphClass())->whereIn('subject_id', $paymentIds)->delete();
    DB::table('customer_payments')->whereIn('id', $paymentIds)->delete();
    DB::table('subscription_events')->where('subscriber_id', $user->id)->delete();
    Subscription::query()->where('subscriber_id', $user->id)->delete();
    $user->delete();
    $plan?->delete();
}

function e1PeriodEvent(User $user, Plan $plan): SubscriptionEvent
{
    $subscription = Subscription::create(['subscriber_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'started_at' => now()]);

    return SubscriptionEvent::query()->create([
        'subscription_id' => $subscription->id, 'subscriber_id' => $user->id, 'event_type' => 'extended', 'from_status' => 'active', 'to_status' => 'active',
        'to_period_start' => CarbonImmutable::parse('2026-09-01', 'UTC'), 'to_period_end' => CarbonImmutable::parse('2026-10-01', 'UTC'),
        'effective_at' => now(), 'source' => 'admin', 'actor_ref' => 'console',
    ]);
}

/*
 * Phase E2 PostgreSQL race helpers (shared by every reconciliation race file so each runs standalone).
 */
function e2Run(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:reconciliation-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/** @return list<string> */
function e2Outcomes(array $processes): array
{
    $outcomes = [];
    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

function e2Counterparty(): string
{
    $key = 'pgrace-'.strtolower(str()->random(6));
    AiProvider::factory()->create(['key' => $key, 'driver' => 'groq', 'priority' => 1]);

    return $key;
}

function e2Cleanup(string $counterparty): void
{
    $invoiceIds = CostInvoice::query()->where('counterparty_key', $counterparty)->pluck('id');
    $scopeIds = CostReconciliationScope::query()->where('counterparty_key', $counterparty)->pluck('id');
    $reconciliationIds = CostReconciliation::query()->whereIn('scope_id', $scopeIds)->pluck('id');
    DB::table('cost_adjustments')->whereIn('cost_reconciliation_id', $reconciliationIds)->delete();
    DB::table('cost_invoice_allocations')->whereIn('cost_reconciliation_id', $reconciliationIds)->orWhereIn('cost_invoice_id', $invoiceIds)->delete();
    DB::table('cost_reconciliation_scopes')->whereIn('id', $scopeIds)->update(['current_reconciliation_id' => null]);
    DB::table('cost_reconciliations')->whereIn('id', $reconciliationIds)->delete();
    DB::table('cost_reconciliation_scopes')->whereIn('id', $scopeIds)->delete();
    DB::table('cost_invoice_lines')->whereIn('cost_invoice_id', $invoiceIds)->delete();
    DB::table('cost_invoice_events')->whereIn('cost_invoice_id', $invoiceIds)->delete();
    AuditLog::where('subject_type', (new CostInvoice)->getMorphClass())->whereIn('subject_id', $invoiceIds)->delete();
    AuditLog::where('subject_type', (new CostReconciliationScope)->getMorphClass())->whereIn('subject_id', $scopeIds)->delete();
    DB::table('cost_invoices')->whereIn('id', $invoiceIds)->delete();
    DB::table('ai_providers')->where('key', $counterparty)->delete();
}

/*
 * Phase E3 PostgreSQL race helpers (shared by every FX race file so each runs standalone).
 */
function fxRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:fx-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/** @return list<string> */
function fxOutcomes(array $processes): array
{
    $outcomes = [];
    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

/** Two synthetic currency codes nobody else uses, so cleanup touches only this test's rows. */
function fxCodes(): array
{
    $letters = static fn (): string => chr(random_int(65, 90)).chr(random_int(65, 90));
    $a = 'X'.$letters();
    $b = 'Y'.$letters();

    return [$a, $b];
}

function fxCleanup(array $codes, ?User $user = null): void
{
    $pairIds = FxPair::query()->whereIn('base_currency', $codes)->orWhereIn('quote_currency', $codes)->pluck('id');
    $rateIds = FxRate::query()->whereIn('fx_pair_id', $pairIds)->pluck('id');
    $convIds = FxConversion::query()->whereIn('fx_rate_id', $rateIds)->pluck('id');
    $convScopeIds = FxConversion::query()->whereIn('id', $convIds)->pluck('scope_id');
    DB::table('fx_conversion_scopes')->whereIn('id', $convScopeIds)->update(['current_conversion_id' => null]);
    DB::table('fx_conversions')->whereIn('id', $convIds)->delete();
    DB::table('fx_conversion_scopes')->whereIn('id', $convScopeIds)->delete();
    DB::table('cost_invoice_allocations')->whereIn('fx_rate_id', $rateIds)->delete();
    DB::table('fx_rate_scopes')->whereIn('fx_pair_id', $pairIds)->update(['current_rate_id' => null]);
    DB::table('fx_rates')->whereIn('id', $rateIds)->delete();
    AuditLog::where('subject_type', (new FxRateScope)->getMorphClass())->whereIn('subject_id', FxRateScope::query()->whereIn('fx_pair_id', $pairIds)->pluck('id'))->delete();
    DB::table('fx_rate_scopes')->whereIn('fx_pair_id', $pairIds)->delete();
    AuditLog::where('subject_type', (new FxPair)->getMorphClass())->whereIn('subject_id', $pairIds)->delete();
    DB::table('fx_pairs')->whereIn('id', $pairIds)->delete();

    if ($user !== null) {
        $paymentIds = CustomerPayment::query()->where('subscriber_id', $user->id)->pluck('id');
        DB::table('customer_payment_events')->whereIn('customer_payment_id', $paymentIds)->delete();
        AuditLog::where('subject_type', (new CustomerPayment)->getMorphClass())->whereIn('subject_id', $paymentIds)->delete();
        DB::table('customer_payments')->whereIn('id', $paymentIds)->delete();
        $user->delete();
    }
}

// ---- Tools (Phase F1) ------------------------------------------------------------------

use App\Exceptions\Tools\ToolRuleException;

/** The rule name a tool service refuses with, or "none". */
function toolRule(callable $fn): string
{
    try {
        $fn();
    } catch (ToolRuleException $e) {
        return $e->rule;
    }

    return 'none';
}

// ---- Tool invocations (Phase F2) -------------------------------------------------------

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ToolConsent;
use App\Models\ToolInvocation;
use App\Services\Tools\ReadToolExecutor;
use App\Services\Tools\ToolInvocationStore;
use App\Support\Tools\ToolCallPlan;

/** A stored inbound message of one subscriber — the persisted fact an invocation identity is derived from. */
function f2Message(User $subscriber): Message
{
    return Message::factory()->create([
        'conversation_id' => Conversation::factory()->create(['user_id' => $subscriber->id])->id,
        'user_id' => $subscriber->id,
    ]);
}

function f2Executor(): ReadToolExecutor
{
    return app(ReadToolExecutor::class);
}

function f2Store(): ToolInvocationStore
{
    return app(ToolInvocationStore::class);
}

function f2Plan(): ToolCallPlan
{
    return app(ToolCallPlan::class);
}

/** The audit rows of one invocation, oldest first. */
function f2Audits(ToolInvocation $row)
{
    return AuditLog::query()->where('subject_type', $row->getMorphClass())->where('subject_id', $row->id)->orderBy('id')->get();
}

/** The ledger rows linked to one invocation, by its persisted identity. */
function f2Usage(ToolInvocation $row)
{
    return DB::table('usage_events')->where('tool_invocation_ref', (string) $row->id)->get();
}

/** One probe process (separate PHP, no shared transaction). */
function f2Run(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:tool-invocation-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/** @return list<string> */
function f2Outcomes(array $processes): array
{
    $outcomes = [];

    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

/** Remove everything one race created, so the shared PostgreSQL database stays clean. */
function f2Cleanup(User $subscriber): void
{
    $ids = ToolInvocation::query()->where('subscriber_id', $subscriber->id)->pluck('id');
    DB::table('tool_invocation_events')->whereIn('tool_invocation_id', $ids)->delete();
    AuditLog::query()->where('subject_type', (new ToolInvocation)->getMorphClass())->whereIn('subject_id', $ids)->delete();
    DB::table('usage_events')->whereIn('tool_invocation_ref', $ids->map(fn ($id) => (string) $id))->delete();
    DB::table('tool_invocations')->whereIn('id', $ids)->delete();

    $consentIds = DB::table('tool_consents')->where('subscriber_id', $subscriber->id)->pluck('id');
    AuditLog::query()->where('subject_type', (new ToolConsent)->getMorphClass())->whereIn('subject_id', $consentIds)->delete();
    DB::table('tool_consents')->whereIn('id', $consentIds)->delete();
    DB::table('memories')->where('user_id', $subscriber->id)->delete();
    DB::table('messages')->where('user_id', $subscriber->id)->delete();
    DB::table('conversations')->where('user_id', $subscriber->id)->delete();
    DB::table('usage_events')->where('user_id', $subscriber->id)->delete();
    $subscriber->delete();
}

// ---- Write tools (Phase F3-V1) ---------------------------------------------------------

use App\Enums\ReminderStatus;
use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolConsentStatus;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Models\Task;
use App\Services\Tools\ToolConsentService;
use App\Services\Tools\ToolExecutor;

function f3Executor(): ToolExecutor
{
    return app(ToolExecutor::class);
}

/** Grant one capability as the subscriber themself (the only actor F1 allows). */
function f3Consent(User $subscriber, ToolCapability $capability): void
{
    $previous = auth()->user();
    auth()->setUser($subscriber);
    app(ToolConsentService::class)->grant($subscriber->id, $capability, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();

    if ($previous !== null) {
        auth()->setUser($previous);
    }
}

/** A subscriber who has consented to both write capabilities, with a stored message. */
function f3Subject(): array
{
    $subscriber = User::factory()->create(['is_admin' => false, 'timezone' => 'Asia/Hebron']);
    f3Consent($subscriber, ToolCapability::TasksWrite);
    f3Consent($subscriber, ToolCapability::RemindersWrite);

    return [$subscriber, f2Message($subscriber)];
}

/** Everything about the domain tables a refused or conflicting write must leave untouched. */
function f3DomainSnapshot(User $subscriber): array
{
    return [
        'tasks' => Task::query()->where('user_id', $subscriber->id)->orderBy('id')->get(['id', 'title', 'status', 'completed_at'])->toArray(),
        'reminders' => Reminder::query()->where('user_id', $subscriber->id)->orderBy('id')->get(['id', 'title', 'status', 'remind_at'])->toArray(),
    ];
}

/** Remove everything an F3 race created, so the shared PostgreSQL database stays clean. */
function f3Cleanup(User $subscriber): void
{
    DB::table('reminders')->where('user_id', $subscriber->id)->delete();
    DB::table('tasks')->where('user_id', $subscriber->id)->delete();
    f2Cleanup($subscriber);
}

// ---- Admin surface (dashboard alignment) ---------------------------------

/**
 * Grant a consent the ONLY legal way: as the subscriber themself. Staff cannot
 * create consent (`ToolAuthorization::assertMayGrantConsent`), so a test fixture
 * must not pretend otherwise — it acts as the subscriber and then steps back out.
 */
function admConsent(User $subscriber, ToolCapability $capability, ToolConsentStatus $status = ToolConsentStatus::Granted): ToolConsent
{
    $previous = auth()->user();
    auth()->setUser($subscriber);

    $consent = app(ToolConsentService::class)->grant($subscriber->id, $capability, 0, ToolConsentReason::SubscriberRequest);

    if ($status === ToolConsentStatus::Revoked) {
        $consent = app(ToolConsentService::class)->revoke($subscriber->id, $capability, $consent->version, ToolConsentReason::SubscriberRequest);
    }

    $previous === null ? auth()->forgetUser() : auth()->setUser($previous);

    return $consent->fresh();
}

/**
 * One invocation row with defaults that satisfy the PostgreSQL check constraints
 * (`version >= 1`, `call_index >= 1`, output only when succeeded).
 */
function admInvocation(User $subscriber, array $attrs = []): ToolInvocation
{
    static $slot = 0;
    $slot++;

    return ToolInvocation::query()->create(array_merge([
        'subscriber_id' => $subscriber->id,
        'tool_key' => 'memory.read',
        'tool_version' => 2,
        'capability' => ToolCapability::MemoryRead->value,
        'side_effect' => ToolSideEffect::Read->value,
        'idempotency_key' => 'adm:test:'.$slot.':'.uniqid(),
        'input_hash' => hash('sha256', 'adm'.$slot),
        'input' => [],
        'input_fields' => ['query'],
        'status' => ToolInvocationStatus::Planned->value,
        'call_index' => 1,
        'version' => 1,
    ], $attrs));
}

// ---- Voice notes (Phase H) --------------------------------------------------

/**
 * Build a WhatsApp inbound AUDIO envelope.
 *
 * Shaped exactly like the platform's own: an audio message carries a media
 * REFERENCE and a `voice` flag, never bytes and never a URL.
 *
 * @param  array<string, mixed>  $opts
 * @return array<string, mixed>
 */
function whatsappVoiceEnvelope(string $wamid, string $from, array $opts = []): array
{
    $envelope = whatsappTextEnvelope($wamid, $from, '', $opts + ['type' => 'audio']);
    $message = &$envelope['entry'][0]['changes'][0]['value']['messages'][0];

    unset($message['text']);

    $message['audio'] = array_filter([
        'id' => $opts['media_id'] ?? 'media-'.str()->random(8),
        'mime_type' => $opts['mime_type'] ?? 'audio/ogg; codecs=opus',
        'sha256' => 'ZmFrZQ==',
        'voice' => $opts['voice'] ?? true,
    ], static fn ($v): bool => $v !== null);

    return $envelope;
}

/** The bytes of the checked-in synthetic Ogg/Opus fixture (7.5 seconds). */
function voiceFixtureBytes(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/voice/voice-note-7500ms.ogg'));
}

/**
 * Fake both external services the voice path touches: WhatsApp media (metadata
 * then bytes) and the transcription provider.
 *
 * @param  array<string, mixed>  $opts
 */
function voiceFakeHttp(array $opts = []): void
{
    $audio = $opts['audio'] ?? voiceFixtureBytes();

    Http::fake([
        'graph.facebook.com/v21.0/*' => $opts['metadata'] ?? Http::response([
            'url' => 'https://lookaside.example/media/test',
            'mime_type' => $opts['media_mime'] ?? 'audio/ogg; codecs=opus',
            'file_size' => $opts['file_size'] ?? strlen((string) $audio),
            'id' => 'media-1',
        ], 200),
        // A CLOSURE, not a shared Response: a streamed body is consumed once,
        // and a second attempt at the same voice note must get its own bytes
        // rather than find an exhausted stream.
        'lookaside.example/*' => $opts['binary'] ?? static fn () => Http::response($audio, 200),
        'api.groq.com/*' => $opts['transcription'] ?? Http::response([
            'text' => $opts['text'] ?? 'مرحبا، هذه رسالة صوتية.',
            'language' => 'ar',
        ], 200),
    ]);
}

/**
 * Configure everything the voice path needs to actually run: WhatsApp media
 * access, a routable transcription model, and a plan that includes voice.
 *
 * @param  array<string, mixed>  $overrides
 */
function voiceConfigure(array $overrides = []): void
{
    whatsappConfigure();

    config(array_merge([
        'whatsapp.enabled' => true,
        'whatsapp.access_token' => 'TEST_ACCESS_TOKEN',
        'whatsapp.phone_number_id' => 'PNID_123',
        'whatsapp.graph_base_url' => 'https://graph.facebook.com',
        'whatsapp.graph_version' => 'v21.0',
        'voice.enabled' => true,
        'voice.require_voice_flag' => true,
        'voice.max_attempts' => 2,
        'voice.lease_seconds' => 300,
        'ai.catalog' => [],
        'ai.catalog_source' => 'config',
        'ai.provider' => 'groq',
        'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
        'ai.providers.groq.api_key' => 'test-groq-key',
        'ai.providers.groq.transcription_model' => 'test-transcribe',
    ], $overrides));
}

/**
 * A subscriber on a plan that includes voice, with a WhatsApp account.
 *
 * @return array{0: User, 1: ChannelAccount}
 */
function voiceSubscriber(bool $withVoice = true, string $e164 = '+970599000001'): array
{
    $plan = billingPlan(['daily' => 100, 'monthly' => 1000, 'weight' => 1], [
        'features' => [PlanFeature::Voice->value => $withVoice],
    ]);

    $user = billingSubscriber($plan);
    $user->forceFill(['locale' => 'ar'])->save();

    $account = ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => $e164,
    ]);

    return [$user->refresh(), $account];
}

/**
 * A stored inbound voice-note message, exactly as ingestion writes one.
 *
 * @param  array<string, mixed>  $attrs
 */
function voiceNote(User $user, ChannelAccount $account, array $attrs = []): Message
{
    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);

    return Message::factory()->for($user)->for($conversation)->create(array_merge([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Audio,
        'external_message_id' => 'wamid.'.str()->random(10),
        'text_content' => null,
        'media_path' => null,
        'metadata' => ['provider' => 'whatsapp', 'voice' => true],
        'processing_status' => MessageProcessingStatus::Queued,
        'voice_media_id' => 'media-'.str()->random(8),
        'voice_mime_type' => 'audio/ogg; codecs=opus',
        'transcription_status' => TranscriptionStatus::Pending,
        'transcription_attempts' => 0,
    ], $attrs));
}

/**
 * Register a CHAT-ONLY provider at runtime, under a key the app has never heard
 * of, via the documented `AiManager::extend()` extension point.
 *
 * Two things are proved by having this available. A provider that does not
 * implement `SupportsTranscription` must be rejected for transcription however
 * the catalog advertises it — operator data must never be able to crash a
 * worker. And a provider can be added to this platform without touching app
 * code at all, which is what "no vendor lock-in" has to mean in practice.
 */
function voiceRegisterChatOnlyProvider(string $key = 'chatonly'): AiProviderContract
{
    $provider = new class($key) implements SupportsChat
    {
        public function __construct(private readonly string $key) {}

        public function name(): string
        {
            return $this->key;
        }

        public function supports(AiOperation $operation): bool
        {
            return $operation === AiOperation::Chat;
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function chat(AiRequest $request): AiResponse
        {
            return new AiResponse('chat only');
        }
    };

    app(AiManager::class)->extend($key, fn () => $provider);

    return $provider;
}

/**
 * Register a TRANSCRIBING provider at runtime under an unknown key, recording
 * every request it serves.
 *
 * This is the real test of the abstraction: a third vendor is one adapter plus
 * catalog data, and the generic pipeline must drive it with no change at all.
 *
 * @param  array<int, mixed>  $calls  filled with each TranscriptionRequest served
 */
function voiceRegisterTranscribingProvider(string $key, array &$calls, string $text = 'نصّ المزوّد'): AiProviderContract
{
    $provider = new class($key, $calls, $text) implements SupportsTranscription
    {
        /** @param array<int, mixed> $calls */
        public function __construct(
            private readonly string $key,
            private array &$calls,
            private readonly string $text,
        ) {}

        public function name(): string
        {
            return $this->key;
        }

        public function supports(AiOperation $operation): bool
        {
            return $operation === AiOperation::Transcription;
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function transcribe(TranscriptionRequest $request): TranscriptionResult
        {
            $this->calls[] = $request;

            return new TranscriptionResult(
                text: $this->text,
                provider: $this->key,
                model: $request->spec->model,
                language: 'ar',
            );
        }
    };

    app(AiManager::class)->extend($key, fn () => $provider);

    return $provider;
}

// ---- Recurring reminders (Phase H2) ----------------------------------------

/**
 * A subscriber in a real DST zone, with a WhatsApp account and an open
 * conversation — everything a recurring series needs to exist and be delivered.
 *
 * @return array{0: User, 1: ChannelAccount, 2: Conversation}
 */
function recSubscriber(string $timezone = 'Asia/Hebron', string $e164 = '+970599000001'): array
{
    $user = User::factory()->create([
        'is_admin' => false,
        'timezone' => $timezone,
        'locale' => 'ar',
    ]);

    $account = ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => $e164,
    ]);

    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);

    return [$user->refresh(), $account, $conversation];
}

/**
 * Create one series through the DOMAIN SERVICE — the same path the tool takes, so
 * a test never exercises a shape the tool could not produce.
 *
 * @param  array<string, mixed>  $input
 */
function recSchedule(User $subscriber, array $input = []): ReminderSchedule
{
    $created = app(ReminderScheduleService::class)->create(
        $subscriber,
        array_merge(['title' => 'اشرب الدوا', 'pattern' => 'daily', 'local_time' => '09:00'], $input),
        ChannelType::WhatsApp,
    );

    return ReminderSchedule::query()->findOrFail($created['schedule_id']);
}

/** Run one materialisation pass. */
function recMaterialise(): array
{
    return app(ReminderMaterialiser::class)->run();
}

/** The occurrence identities of one series, in date order. */
function recOccurrenceKeys(ReminderSchedule $schedule): array
{
    return Reminder::query()
        ->where('reminder_schedule_id', $schedule->getKey())
        ->orderBy('remind_at')
        ->pluck('occurrence_key')
        ->all();
}

// ---- Follow-Up Until Done (Phase H3) ---------------------------------------

/**
 * Deterministic follow-up configuration. The template is READY by default so a
 * test is about the loop rather than about the external dependency; the tests
 * that are about the dependency turn it off explicitly.
 *
 * @param  array<string, mixed>  $overrides
 */
function fuConfigure(array $overrides = []): void
{
    config(array_merge([
        'follow_ups.enabled' => true,
        'follow_ups.max_open_per_subscriber' => 5,
        'follow_ups.max_asks_per_follow_up' => 3,
        'follow_ups.min_ask_interval_hours' => 24,
        'follow_ups.answer_window_hours' => 72,
        'follow_ups.list_limit' => 10,
        'follow_ups.whatsapp.template.ready' => true,
        'follow_ups.whatsapp.template.name' => 'sanad_follow_up_v1',
        'follow_ups.whatsapp.template.language' => 'ar',
        'reminders.enabled' => true,
        'reminders.max_lateness_minutes' => 600,
        'reminders.whatsapp.free_form_window_hours' => 24,
        'whatsapp.enabled' => true,
        'whatsapp.access_token' => 'TEST_ACCESS_TOKEN',
        'whatsapp.phone_number_id' => 'PNID_123',
        'whatsapp.graph_base_url' => 'https://graph.facebook.com',
        'whatsapp.graph_version' => 'v21.0',
    ], $overrides));
}

/**
 * One subscriber entitled to follow-ups, with a WhatsApp account and a
 * conversation.
 *
 * @return array{0: User, 1: ChannelAccount, 2: Conversation}
 */
function fuSubscriber(bool $entitled = true, string $timezone = 'Asia/Hebron'): array
{
    $plan = Plan::create([
        'name' => 'Follow-Up Test',
        'slug' => 'follow-up-test-'.bin2hex(random_bytes(5)),
        'price' => 0,
        'currency' => 'ILS',
        'billing_period' => 'monthly',
        'trial_days' => 0,
        'limits' => ['ai_reply' => ['daily' => 1000, 'monthly' => 10000, 'weight' => 1]],
        // The entitlement is its OWN feature: a plan with reminders but without
        // this one must not be able to open a loop.
        'features' => [PlanFeature::FollowUp->value => $entitled, PlanFeature::Reminders->value => true],
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 0,
    ]);

    $user = User::factory()->create(['is_admin' => false, 'timezone' => $timezone, 'locale' => 'ar']);

    Subscription::create([
        'subscriber_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'started_at' => now(),
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $account = ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => '+97059'.random_int(1000000, 9999999),
        'status' => ChannelAccountStatus::Active,
    ]);

    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);

    return [$user->refresh(), $account, $conversation];
}

/** One inbound message — the only thing that is ever authority in this domain. */
function fuInbound(User $user, Conversation $conversation, string $text, ?CarbonImmutable $at = null): Message
{
    return Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => $text,
        'created_at' => $at ?? CarbonImmutable::now(),
    ]);
}

/**
 * Open one loop through the DOMAIN SERVICE — the same path the tool takes, so a
 * test never exercises a shape the tool could not produce.
 *
 * @param  array<string, mixed>  $input
 */
function fuCreate(User $user, Conversation $conversation, array $input = [], ?Message $message = null): FollowUp
{
    $message ??= fuInbound($user, $conversation, 'تابع معي بكرا الساعة ٩ إذا دفعت الفاتورة');

    $created = app(FollowUpService::class)->create(
        $user,
        array_merge([
            'question' => 'دفعت فاتورة الكهربا؟',
            'first_ask_at' => CarbonImmutable::now('UTC')->addHours(20)->format('Y-m-d\TH:i'),
        ], $input),
        ChannelType::WhatsApp,
        $message,
    );

    return FollowUp::query()->findOrFail($created['follow_up_id']);
}

/** Advance one loop by at most one step, as the scheduled command would. */
function fuAdvance(FollowUp $followUp): string
{
    return app(FollowUpAskMaterialiser::class)->advance($followUp);
}

/** The asks of one loop, in order. */
function fuAsks(FollowUp $followUp): array
{
    return Reminder::query()
        ->where('follow_up_id', $followUp->getKey())
        ->orderBy('ask_index')
        ->get()
        ->all();
}

/**
 * A loop that has already asked once and is waiting for the answer — the state
 * most resolution tests need to start from.
 */
function fuAwaiting(User $user, Conversation $conversation, string $question = 'دفعت فاتورة الكهربا؟'): FollowUp
{
    $followUp = fuCreate($user, $conversation, ['question' => $question]);
    $followUp->forceFill(['next_ask_at' => CarbonImmutable::now('UTC')->subMinute()])->save();

    fuAdvance($followUp->fresh());

    $asks = fuAsks($followUp);
    $ask = end($asks);
    $ask->forceFill([
        'attempts' => 1,
        'dispatched_at' => CarbonImmutable::now()->subHours(2),
        'sent_at' => CarbonImmutable::now()->subHours(2),
        'status' => ReminderStatus::Sent->value,
    ])->save();

    fuAdvance($followUp->fresh());

    return $followUp->fresh();
}

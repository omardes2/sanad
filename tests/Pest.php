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
use App\Enums\CostSource;
use App\Enums\MessageType;
use App\Enums\SubscriptionStatus;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessInboundMessage;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\ChannelAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Rbac\RbacSynchronizer;
use App\Services\Settings\SettingsRepository;
use App\Support\Rbac\Role;
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

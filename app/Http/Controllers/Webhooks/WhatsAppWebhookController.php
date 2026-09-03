<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookEventStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WebhookEvent;
use App\Support\WhatsApp\WhatsAppConfig;
use App\Support\WhatsApp\WhatsAppSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Public WhatsApp Cloud API webhook (no CSRF). Two endpoints:
 *
 *  GET  /webhooks/whatsapp — Meta subscription verification handshake.
 *  POST /webhooks/whatsapp — signed event delivery.
 *
 * The HTTP layer does the minimum: verify identity, persist the raw envelope
 * idempotently, and queue processing. No AI, no WhatsApp send, no heavy work
 * happens here. Nothing sensitive (tokens, secrets, message bodies, full
 * phone numbers) is ever logged.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppConfig $config) {}

    /**
     * GET verification. Returns hub.challenge (200) on success, else 403.
     * Fails closed when the integration is disabled or the verify token is
     * unset.
     */
    public function verify(Request $request): Response
    {
        if (! $this->config->enabled() || ! $this->config->canVerifyWebhook()) {
            return response('Forbidden', 403);
        }

        $mode = (string) $request->query('hub_mode', '');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || ! is_string($token) || ! is_string($challenge) || $challenge === '') {
            return response('Forbidden', 403);
        }

        if (! hash_equals($this->config->verifyToken(), $token)) {
            Log::warning('sanad.whatsapp.verify_failed');

            return response('Forbidden', 403);
        }

        // Echo the challenge verbatim as plain text.
        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * POST event delivery. Validates the signature over the RAW body, persists
     * the envelope idempotently, queues processing, and returns 200 quickly.
     */
    public function handle(Request $request): JsonResponse|Response
    {
        if (! $this->config->enabled() || ! $this->config->canValidateSignature()) {
            return response('Forbidden', 403);
        }

        $raw = $request->getContent();

        if (! WhatsAppSignature::isValid($raw, $request->header('X-Hub-Signature-256'), $this->config->appSecret())) {
            Log::warning('sanad.whatsapp.signature_invalid');

            return response('Forbidden', 403);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return response('Bad Request', 400);
        }

        // Stable envelope id = SHA-256 of the raw payload. This dedups whole
        // webhook redeliveries; messages.external_message_id remains the
        // second-line barrier against duplicate Meta messages.
        $envelopeId = hash('sha256', $raw);

        try {
            $event = WebhookEvent::create([
                'provider' => 'whatsapp',
                'external_event_id' => $envelopeId,
                'payload' => $decoded,
                'status' => WebhookEventStatus::Received,
                'received_at' => now(),
            ]);
            $isNew = true;
        } catch (UniqueConstraintViolationException) {
            $event = WebhookEvent::query()
                ->where('provider', 'whatsapp')
                ->where('external_event_id', $envelopeId)
                ->first();
            $isNew = false;
        }

        if ($isNew && $event !== null) {
            ProcessWhatsAppWebhook::dispatch($event->id)
                ->onQueue('webhooks')
                ->afterCommit();

            Log::info('sanad.whatsapp.webhook_received', ['event_id' => $event->id]);
        }

        return response()->json(['status' => 'ok'], 200);
    }
}

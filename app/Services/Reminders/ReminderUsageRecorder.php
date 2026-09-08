<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Data\Billing\UsageRecord;
use App\Data\ChannelDeliveryResult;
use App\Data\Reminders\ReminderRecipient;
use App\Enums\UsageDimension;
use App\Models\Reminder;
use App\Services\Billing\UsageRecorder;
use App\Support\Billing\UsageKeys;
use App\Support\SafeError;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what the CHANNEL PROVIDER actually served: one ledger row per
 * ACCEPTED outbound reminder message.
 *
 * Identity is server-owned — `whatsapp_outbound:reminder:<id>:attempt:<k>`,
 * where `k` is the reminder's own physical dispatch counter. The provider's
 * message id goes in metadata as diagnostics and is never the identity.
 *
 * Two genuinely separate physical sends of one reminder produce TWO rows. They
 * are not deduplicated merely for sharing a reminder: the provider served and
 * charged for both, and collapsing them would under-report what we paid.
 *
 * WHAT IS NEVER WRITTEN: a row for an attempt whose outcome is unknown. Nothing
 * is invented — but the absence of a row is NOT a statement that the attempt
 * cost nothing. An unknown dispatch may have been accepted and billed by the
 * provider without Sanad ever learning of it, so reconciliation must read a
 * missing outbound row as UNKNOWN, never as confirmed zero.
 *
 * NO QUOTA IS CHARGED. A reminder the subscriber already scheduled must not
 * vanish because a message allowance ran out; `UsageDimension::Reminder` stays
 * unenforced until that is a deliberate product decision.
 */
final class ReminderUsageRecorder
{
    public function __construct(private readonly UsageRecorder $recorder) {}

    public function record(
        Reminder $reminder,
        ReminderRecipient $recipient,
        ChannelDeliveryResult $result,
        bool $viaTemplate,
    ): void {
        $correlationId = UsageKeys::correlationForReminder($reminder);

        try {
            $this->recorder->record(new UsageRecord(
                subscriber: $reminder->user,
                dimension: UsageDimension::WhatsAppOutbound,
                idempotencyKey: UsageKeys::deliveryAttempt(UsageDimension::WhatsAppOutbound, $correlationId, $reminder->attempts),
                correlationId: $correlationId,
                operation: 'reminder:deliver',
                provider: $recipient->account->channel->value,
                channel: $recipient->account->channel->value,
                quantity: 1,
                metadata: array_filter([
                    'reminder_id' => $reminder->getKey(),
                    'attempt' => $reminder->attempts,
                    'template' => $viaTemplate,
                    // Diagnostics only, never an identity.
                    'provider_message_id' => $result->providerMessageId,
                ], static fn ($v): bool => $v !== null),
            ));
        } catch (Throwable $e) {
            // The message is already out. A ledger failure must never cause a
            // second unsolicited send, so it is reported and not rethrown.
            Log::error('sanad.reminder.usage_not_recorded', [
                'reminder_id' => $reminder->getKey(),
                'attempt' => $reminder->attempts,
                'error' => SafeError::summarize($e),
            ]);
        }
    }
}

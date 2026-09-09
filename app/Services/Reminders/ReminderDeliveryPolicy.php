<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Data\OutboundTemplate;
use App\Data\Reminders\ReminderDeliveryPlan;
use App\Data\Reminders\ReminderRecipient;
use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\ReminderFailureReason;
use App\Models\Message;
use App\Models\Reminder;
use App\Support\WhatsApp\WhatsAppConfig;
use Carbon\CarbonImmutable;

/**
 * Decides, before dispatch, whether a proactive reminder may be sent and in
 * what form.
 *
 * WhatsApp only permits a free-form message inside a customer-service window
 * that opens with the subscriber's own message; outside it the permitted
 * mechanism is a pre-approved template. A reminder is proactive by nature and
 * routinely fires outside that window — `كل أول شهر ذكرني أدفع الإيجار` always
 * will.
 *
 * So the policy is decided HERE, from the subscriber's last inbound message on
 * the same account, and not discovered by sending a message we expect to be
 * refused. Without a configured template that case is `template_required`: an
 * external-readiness failure, terminal and never retried, because retrying a
 * configuration gap only produces the same answer.
 *
 * The window is measured per CHANNEL ACCOUNT, not per conversation: it is a
 * property of the pair of phone numbers, so every thread with that subscriber
 * opens and closes it together.
 */
final class ReminderDeliveryPolicy
{
    public function __construct(private readonly WhatsAppConfig $whatsapp) {}

    public function decide(Reminder $reminder, ReminderRecipient $recipient): ReminderDeliveryPlan
    {
        if (! (bool) config('reminders.enabled', true)) {
            return ReminderDeliveryPlan::refused(ReminderFailureReason::DeliveryDisabled);
        }

        return match ($recipient->account->channel) {
            // The simulator renders the persisted row; there is no external
            // transport and therefore no proactive-message restriction.
            ChannelType::Web => ReminderDeliveryPlan::freeForm(),
            ChannelType::WhatsApp => $this->whatsAppPlan($reminder, $recipient),
            default => ReminderDeliveryPlan::refused(ReminderFailureReason::ChannelUnsupported),
        };
    }

    private function whatsAppPlan(Reminder $reminder, ReminderRecipient $recipient): ReminderDeliveryPlan
    {
        // Not configured to send at all: refuse before burning an attempt.
        if (! $this->whatsapp->canSend()) {
            return ReminderDeliveryPlan::refused(ReminderFailureReason::DeliveryDisabled);
        }

        if ($this->insideFreeFormWindow($recipient)) {
            return ReminderDeliveryPlan::freeForm();
        }

        $template = $this->configuredTemplate();

        return $template === null
            ? ReminderDeliveryPlan::refused(ReminderFailureReason::TemplateRequired)
            : ReminderDeliveryPlan::template(new OutboundTemplate(
                name: $template['name'],
                language: $template['language'],
                parameters: [$reminder->title],
            ));
    }

    private function insideFreeFormWindow(ReminderRecipient $recipient): bool
    {
        $hours = max(0, (int) config('reminders.whatsapp.free_form_window_hours', 24));

        if ($hours === 0) {
            return false;
        }

        $lastInboundAt = Message::query()
            ->where('direction', MessageDirection::Inbound->value)
            ->whereIn('conversation_id', fn ($q) => $q
                ->select('id')
                ->from('conversations')
                ->where('channel_account_id', $recipient->account->id))
            ->max('created_at');

        if ($lastInboundAt === null) {
            return false;
        }

        return CarbonImmutable::parse($lastInboundAt)
            ->greaterThan(CarbonImmutable::now()->subHours($hours));
    }

    /**
     * The approved template, or null while none is configured. No template name
     * is ever invented here: `ready` stays false and `name` stays empty until a
     * real one has been approved on the provider side and put in the
     * environment.
     *
     * @return array{name: string, language: string}|null
     */
    private function configuredTemplate(): ?array
    {
        if (! (bool) config('reminders.whatsapp.template.ready', false)) {
            return null;
        }

        $name = trim((string) config('reminders.whatsapp.template.name', ''));
        $language = trim((string) config('reminders.whatsapp.template.language', ''));

        return $name === '' || $language === '' ? null : ['name' => $name, 'language' => $language];
    }
}

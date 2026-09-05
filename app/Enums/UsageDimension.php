<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A metered capability. Plans express limits/weights per dimension in their
 * JSON `limits`, so NEW dimensions are added here (and referenced in plan
 * limits) WITHOUT any schema change or redesign of subscriptions/usage.
 *
 * Only AiReply is enforced/charged in this phase; the rest are defined so the
 * engine and plans can meter them later (tokens, media, calls, tools) with no
 * structural change.
 */
enum UsageDimension: string
{
    case AiReply = 'ai_reply';
    case AiInputTokens = 'ai_input_tokens';
    case AiOutputTokens = 'ai_output_tokens';
    case WhatsAppInbound = 'whatsapp_inbound';
    case WhatsAppOutbound = 'whatsapp_outbound';
    case VoiceMessage = 'voice_message';
    case VoiceMinute = 'voice_minute';
    case Image = 'image';
    case File = 'file';
    case Reminder = 'reminder';
    case Task = 'task';
    case CallMinute = 'call_minute';
    case ToolAction = 'tool_action';

    public function label(): string
    {
        return match ($this) {
            self::AiReply => 'ردود الذكاء الاصطناعي',
            self::AiInputTokens => 'توكنز المدخلات',
            self::AiOutputTokens => 'توكنز المخرجات',
            self::WhatsAppInbound => 'رسائل واردة (واتساب)',
            self::WhatsAppOutbound => 'رسائل صادرة (واتساب)',
            self::VoiceMessage => 'رسائل صوتية',
            self::VoiceMinute => 'دقائق صوتية',
            self::Image => 'صور',
            self::File => 'ملفات',
            self::Reminder => 'تذكيرات',
            self::Task => 'مهام',
            self::CallMinute => 'دقائق مكالمات',
            self::ToolAction => 'إجراءات/أدوات',
        };
    }
}

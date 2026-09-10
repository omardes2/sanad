<?php

declare(strict_types=1);

namespace App\Services\Launch\Checks;

use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;
use App\Support\WhatsApp\WhatsAppConfig;

/**
 * WhatsApp readiness, reported the way `WhatsAppStatus` already proved is safe:
 * PRESENCE BOOLEANS ONLY. `WhatsAppConfig`'s capability methods answer "can we
 * do X" without the page ever reading an access token, an app secret or a
 * verify token — so there is no value here that could leak one.
 */
final class WhatsAppChecks
{
    public static function messaging(): GateOutcome
    {
        $config = app(WhatsAppConfig::class);

        $enabled = $config->enabled();
        $canSend = $config->canSend();
        $canValidate = $config->canValidateSignature();
        $canVerify = $config->canVerifyWebhook();
        $hasPhone = $config->phoneNumberId !== null;
        $hasWaba = $config->businessAccountId !== null;

        $details = [
            GateDetail::boolean('التكامل مفعَّل', $enabled, 'مفعَّل', 'معطّل'),
            GateDetail::boolean('رمز الوصول (إرسال)', $canSend),
            GateDetail::boolean('التوقيع (استقبال آمن)', $canValidate),
            GateDetail::boolean('رمز التحقّق (Webhook)', $canVerify),
            GateDetail::boolean('معرّف رقم الهاتف', $hasPhone),
            GateDetail::boolean('معرّف حساب الأعمال (WABA)', $hasWaba),
            GateDetail::plain('إصدار Graph API', $config->graphVersion !== '' ? $config->graphVersion : '—'),
        ];

        if (! $enabled) {
            return GateOutcome::notReady('تكامل واتساب معطّل.', $details);
        }

        if (! $canSend || ! $canValidate || ! $canVerify) {
            return GateOutcome::notReady('تكامل واتساب ناقص الإعداد: لا يمكن الإرسال أو الاستقبال بأمان.', $details);
        }

        return GateOutcome::ready('واتساب مضبوط للإرسال والاستقبال الموقَّع.', $details);
    }
}

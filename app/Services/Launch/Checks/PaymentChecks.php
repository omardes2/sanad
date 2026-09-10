<?php

declare(strict_types=1);

namespace App\Services\Launch\Checks;

use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;

/**
 * The bank payment dependency.
 *
 * CyberSource stays `BLOCKED_EXTERNAL` until the bank supplies the integration
 * details. Nothing about the integration is guessed here — not an endpoint, not
 * a field name, not a flow. Two rules from the payment phase are restated on the
 * screen because they are the ones most easily lost: a browser redirect is never
 * payment authority, and no card data ever enters Sanad.
 */
final class PaymentChecks
{
    public static function cybersource(): GateOutcome
    {
        return GateOutcome::blockedExternal(
            'CyberSource محجوب خارجيًا بانتظار تفاصيل البنك — لا يُخمَّن أي منها.',
            [
                GateDetail::bad('الحالة', 'BLOCKED_EXTERNAL: BANK_CYBERSOURCE_DETAILS'),
                GateDetail::plain('المطلوب من البنك', 'تفاصيل التكامل الرسمية'),
                GateDetail::plain('قاعدة ثابتة', 'إعادة توجيه المتصفّح ليست إثباتًا للدفع أبدًا'),
                GateDetail::plain('قاعدة ثابتة', 'لا تدخل بيانات البطاقات إلى سَنَد'),
                GateDetail::plain('المتاح اليوم', 'تسجيل المدفوعات والاستردادات يدويًا عبر لوحة المالية'),
            ],
        );
    }
}

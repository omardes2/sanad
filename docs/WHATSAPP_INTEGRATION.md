# تكامل واتساب | WhatsApp Integration — SANAD

> ما أُنجز في **Sprint 0D — WhatsApp Cloud API Text Transport**.
> نقل **نصّي** كامل عبر WhatsApp Cloud API: استقبال آمن عبر Webhook، وإرسال عبر
> Graph API. **لا يوجد اتصال حيّ بـ Meta**، ولا Access Token حقيقي في المستودع.

## نظرة عامة على التدفق

### الوارد (Inbound)
```
Meta → POST /webhooks/whatsapp
   → التحقق من X-Hub-Signature-256 على raw body (HMAC-SHA256 + WHATSAPP_APP_SECRET)
   → حفظ WebhookEvent بشكل idempotent (external_event_id = SHA-256(raw))
   → dispatch ProcessWhatsAppWebhook (طابور "webhooks") بعد commit → 200 سريع
ProcessWhatsAppWebhook (Job)
   → يمرّ على كل entry[] / changes[] / value / messages[] / statuses[]
   → يتجاهل ما لا يخصّ Phone Number ID / WABA المهيّأين
   → الرسائل النصية → WhatsAppChannelAdapter::toInbound → MessageProcessor (نفس pipeline سَنَد)
   → الرسائل غير النصية → acknowledged + تُسجّل unsupported، دون رد
   → statuses[] → تحديث حالة تسليم الرسالة الصادرة (monotonic)
```

### الصادر (Outbound)
```
ProcessInboundMessage (طابور "messages")
   → PlaceholderAgentOrchestrator ينتج الرد
   → WhatsAppChannelAdapter::send() →
       POST {graph_base_url}/{graph_version}/{phone_number_id}/messages
       Authorization: Bearer {access_token}
       { messaging_product: whatsapp, to: <digits>, type: text, text: { body } }
   → استخراج provider_message_id (wamid) وتخزينه على الرسالة الصادرة (delivery_status = accepted)
```

## المكوّنات

| المكوّن | المسار |
|---------|--------|
| الإعداد المركزي | `config/whatsapp.php` · `App\Support\WhatsApp\WhatsAppConfig` |
| التحقق من التوقيع | `App\Support\WhatsApp\WhatsAppSignature` |
| تطبيع الهاتف E.164 + redaction | `App\Support\WhatsApp\WhatsAppPhone` |
| Webhook | `App\Http\Controllers\Webhooks\WhatsAppWebhookController` · `routes/webhooks.php` |
| معالجة الحدث | `App\Jobs\ProcessWhatsAppWebhook` (طابور `webhooks`) |
| المحوّل | `App\Channels\WhatsAppChannelAdapter` (toInbound + send) |
| نتيجة التسليم | `App\Data\ChannelDeliveryResult` · `App\Enums\MessageDeliveryStatus` |

## Callback URL (المستقبلي)

بعد تجهيز HTTPS عام:
```
https://<your-domain>/webhooks/whatsapp
```
- **GET**: تحقّق الاشتراك (verification handshake).
- **POST**: تسليم الأحداث الموقّعة.

## متغيرات البيئة (بدون قيم)

```env
WHATSAPP_ENABLED=false
WHATSAPP_GRAPH_BASE_URL=https://graph.facebook.com
WHATSAPP_GRAPH_VERSION=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_APP_SECRET=
WHATSAPP_VERIFY_TOKEN=
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_BUSINESS_ACCOUNT_ID=
WHATSAPP_REQUEST_TIMEOUT=10
```
> **fail closed:** عندما يكون `WHATSAPP_ENABLED=true` والإعدادات ناقصة، يفشل التكامل
> بأمان (403 على الـWebhook، واستثناء واضح عند الإرسال) بدل العمل بمفتاح فارغ.

## التحقق من التوقيع (Signature verification)

- يُحسب `hash_hmac('sha256', <raw body>, WHATSAPP_APP_SECRET)` على **raw body** بالضبط
  (لا JSON مُعاد ترميزه)، ويُقارن بترويسة `X-Hub-Signature-256` عبر `hash_equals`.
- توقيع ناقص/غير صحيح ⇒ **403** بلا حفظ أو dispatch.
- **لا يوجد أي تجاوز للتوقيع في production.**
- GET verification تستخدم `hash_equals` لمقارنة `hub.verify_token`.

## إعداد Queue worker

```bash
# طابور الـwebhooks (استقبال Meta)
php artisan queue:work redis --queue=webhooks

# طابور المعالجة/الرد
php artisan queue:work redis --queue=messages

# أو Horizon (يشرف على الطابورين)
php artisan horizon
```

## Idempotency و at-least-once

- **حاجز الغلاف (envelope):** `webhook_events.external_event_id = SHA-256(raw payload)` +
  قيد `unique(provider, external_event_id)` ⇒ إعادة تسليم نفس الـWebhook = حدث واحد + Job واحد.
- **حاجز الرسالة:** `messages.external_message_id` (wamid الوارد) unique ⇒ لا رسالة واردة مكررة.
- **حاجز الرد:** `messages.in_reply_to_message_id` unique ⇒ رد واحد لكل رسالة واردة.
- **حالات التسليم:** انتقالات monotonic (`sent → delivered → read`) لا ترجع للخلف، والمكررة idempotent.
- **الإرسال الخارجي at-least-once:** إعادة محاولة الـJob قد تعيد استدعاء Graph API؛ منع تكرار
  **سجل** الرد مضمون داخليًا، لكن قد يصل الرد للمزوّد أكثر من مرة ما لم يدعم idempotency على مستواه.

## حالات تسليم الرسالة (Delivery status)

منفصلة عن `processing_status` الداخلية:

| العمود | الغرض |
|--------|-------|
| `provider_message_id` | wamid للرسالة الصادرة (unique) — مفتاح ربط status webhooks |
| `delivery_status` | `pending/accepted/sent/delivered/read/failed` (`MessageDeliveryStatus`) |
| `sent_at` / `delivered_at` / `read_at` | طوابع زمنية للانتقالات |
| `delivery_error_code` | رمز خطأ آمن فقط (لا payload حسّاس) |

## الأمان والخصوصية

- التحقق من التوقيع على **raw body**، بلا تجاوز في production.
- **ممنوع** تسجيل: Access Token، App Secret، Verify Token، نص الرسالة، أو رقم الهاتف
  كاملًا. السجلّات تحتوي معرّفات فقط (رقم الهاتف يُقنّع لآخر 4 أرقام).
- الاستثناءات لا تحتوي token/رقم/نص — رمز HTTP أو سبب مختصر فقط.
- **الاحتفاظ بالـpayload:** يُخزَّن غلاف الـWebhook الخام في `webhook_events.payload` **فقط**
  لأغراض إعادة المحاولة والتدقيق. لا تُصدَّر هذه البيانات في السجلّات أو الاستثناءات.

## حدود Sprint 0D

- **نصّ فقط.** الوسائط (صور/صوت/ملفات) تُقبل بـ200 وتُسجّل unsupported بدون رد.
- **لا Templates**، لا إرسال استباقي.
- الردّ يأتي من `PlaceholderAgentOrchestrator` (لا OpenAI بعد).
- استقبال رسالة من رقم واتساب صالح غير معروف ⇒ **onboarding تلقائي**: يُنشأ مشترك
  مستقل (`is_admin=false`) + `ChannelAccount` خاصّ به (انظر قسم الموثوقية أدناه).

## خطوات ربط Meta (بعد تجهيز HTTPS)

1. جهّز نطاقًا عامًا بـ HTTPS يوجّه إلى التطبيق.
2. في Meta App → WhatsApp → Configuration:
   - **Callback URL:** `https://<domain>/webhooks/whatsapp`
   - **Verify token:** نفس قيمة `WHATSAPP_VERIFY_TOKEN`.
3. اشترك في حقل **messages**.
4. اضبط في البيئة (خارج المستودع): `WHATSAPP_ENABLED=true` والقيم الحقيقية
   (`ACCESS_TOKEN`، `APP_SECRET`، `PHONE_NUMBER_ID`، `BUSINESS_ACCOUNT_ID`).
5. شغّل عمّال الطوابير (`webhooks` و`messages`) أو Horizon.

> ⚠️ **تنبيه أمني:** لا تنسخ **Access Token** أو أي سرّ داخل المحادثات أو Git أو لقطات
> الشاشة. تُحقن الأسرار عبر بيئة الخادم فقط.

## الموثوقية والتشغيل في الإنتاج (Sprint 1.1)

### استهلاك الطوابير تلقائيًا عبر Horizon
`config/horizon.php` يضبط المشرف الافتراضي على الطوابير
`['webhooks', 'messages', 'default']` (بترتيب الأولوية)، فيصرّفها Horizon تلقائيًا
دون أي أمر يدوي. `timeout` (60ث) أقل من `retry_after` (90ث) لمنع تشغيل مزدوج.

تشغيل الإنتاج:

```sh
php artisan horizon        # يستهلك webhooks + messages + default تلقائيًا
```

> لم يعد مطلوبًا الأمر اليدوي `queue:work redis --queue=webhooks ...`.

### Onboarding تلقائي للمشتركين
أول رسالة نصّية من رقم واتساب **صالح** غير معروف تُنشئ عبر
`WhatsAppSubscriberProvisioner`:

- مستخدمًا مشتركًا مستقلًا (`is_admin=false`, `status=active`, `phone=E.164`)،
- و`ChannelAccount` (channel=whatsapp، `external_identifier=E.164`، `status=active`)،

ثم تُعالَج الرسالة عبر `MessageProcessor` كالمعتاد. لا يُربط أي رقم بحساب المدير.

### تطبيع المعرّفات إلى E.164
معرّف المُرسِل يُطبَّع دائمًا عبر `WhatsAppPhone::toE164` (يضيف `+`) عند الاستقبال
وإنشاء/إيجاد الحساب والمقارنة، فرقم بـ`+` أو بدونه يُطابق الحساب نفسه. رقم غير صالح
يُتخطّى بأمان دون إنشاء أي سجل. القيد الفريد `(channel, external_identifier)` يضمن
عدم التكرار وسلامة التزامن (idempotency).

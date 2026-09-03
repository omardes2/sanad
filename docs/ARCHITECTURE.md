# المعمارية | Architecture — SANAD

> نظرة على المعمارية كما هي في **Sprint 0**، مع الإشارة إلى نقاط التوسّع المستقبلية.

## نظرة عامة

سَنَد تطبيق **Laravel** أحادي (Monolith) قابل للتحوّل إلى **Multi-user SaaS**.
في مرحلة الإنتاج ستكون البوابة الأساسية للتفاعل هي **WhatsApp Cloud API** (webhook)،
مع واجهة ويب إدارية بسيطة مبنية على **Livewire**.

```
                ┌────────────────────────────────────────────┐
                │                  المستخدم                    │
                │            (WhatsApp — مستقبلًا)              │
                └───────────────────┬────────────────────────┘
                                    │  webhook (لاحقًا)
                                    ▼
   ┌──────────────────────────────────────────────────────────────┐
   │                     تطبيق Laravel (SANAD)                      │
   │                                                                │
   │   HTTP Layer                                                   │
   │   ├── routes/web.php    → صفحة Livewire الرئيسية (RTL)         │
   │   └── routes/api.php    → GET /api/health                      │
   │                                                                │
   │   Domain (مستقبلًا): Tasks · Reminders · Memory · Expenses     │
   │   AI Layer (مستقبلًا): Function Calling / Tools                │
   │                                                                │
   │   Queues (Redis) ── Jobs ──► Horizon (إشراف)                   │
   │   Scheduler ──────────────► المهام الدورية (الملخّص اليومي)     │
   └───────────┬───────────────────────────────┬──────────────────┘
               │                               │
        ┌──────▼──────┐                 ┌──────▼──────┐
        │ PostgreSQL  │                 │    Redis    │
        │ (بيانات)     │                 │ cache/queue │
        │             │                 │  /session   │
        └─────────────┘                 └─────────────┘
```

## المكوّنات في Sprint 0

| المكوّن | التقنية | الحالة |
|---------|---------|--------|
| إطار العمل | Laravel 13 (PHP 8.4) | ✅ |
| الواجهة | Livewire 3 + Blade | ✅ (صفحة مؤقتة) |
| التنسيق | Tailwind CSS 4 (Vite) + RTL | ✅ |
| قاعدة البيانات | PostgreSQL 16 | ✅ |
| الكاش/الطوابير/الجلسات | Redis 7 | ✅ |
| مراقبة الطوابير | Laravel Horizon | ✅ (مهيّأ) |
| Health Check | `GET /api/health` | ✅ |

## الطبقات (Layers)

1. **HTTP / Presentation** — مسارات الويب (Livewire) و API (health). لاحقًا: WhatsApp webhook.
2. **Application / Domain** — نماذج البيانات (Users، Channels، Conversations، Messages،
   Tasks، Reminders، Memory، Expenses، Webhook/Usage/Audit) **مبنيّة في Sprint 0B**؛
   و**مسار الرسائل** (DTOs، Adapters، MessageProcessor، Queue Job) **مبني في Sprint 0C**.
3. **AI / Tooling** — `AgentOrchestrator` كعقد مجرّد، بتنفيذ `PlaceholderAgentOrchestrator`
   حتميّ الآن؛ تنفيذ مدعوم بالذكاء (OpenAI + Function Calling) سيحلّ محلّه لاحقًا دون تغيير المتصلين.
4. **Infrastructure** — PostgreSQL، Redis، Queues (طابور `messages`)، Scheduler، Horizon.

### مسار الرسائل (Sprint 0C)

قناة واردة → `ChannelAdapter::toInbound()` → `MessageProcessor` (idempotency + حفظ + dispatch)
→ طابور `messages` → `ProcessInboundMessage` → `AgentOrchestrator` → حفظ الرد →
`ChannelAdapter::send()` (يُعيد `ChannelDeliveryResult`). اختيار القناة عبر `ChannelRegistry`
(DI) لا شروط متناثرة. محاكي محلي على `/dev/chat` (local/testing فقط). التفاصيل في
**[MESSAGE_PIPELINE.md](MESSAGE_PIPELINE.md)**.

### نقل واتساب النصّي (Sprint 0D)

`WhatsAppChannelAdapter` أصبح تكاملًا فعليًا (نصّ فقط): استقبال عبر **Webhook موقّع**
(`POST /webhooks/whatsapp`، توقيع HMAC-SHA256 على raw body، طابور `webhooks`،
`ProcessWhatsAppWebhook`) وإرسال عبر **Graph API** (Laravel HTTP client، retry على
network/429/5xx). حالات التسليم (`sent/delivered/read/failed`) تُتابَع منفصلة عن المعالجة
الداخلية. الإعداد المركزي في `config/whatsapp.php` ويفشل بأمان (fail-closed). **لا اتصال حيّ
بـ Meta ولا أسرار في المستودع.** التفاصيل في **[WHATSAPP_INTEGRATION.md](WHATSAPP_INTEGRATION.md)**.

### نموذج المجال (Sprint 0B)

أُرسي نموذج بيانات كامل يدعم: المستخدمين، حسابات القنوات، المحادثات، الرسائل،
المهام، التذكيرات، الذاكرة، المصاريف، أحداث الـWebhook، تتبّع استخدام/تكلفة الذكاء
الاصطناعي، وسجلّات التدقيق. التفاصيل الكاملة (الأعمدة، الفهارس، القيود، سياسة الحذف،
قواعد UTC/العملات، وسبب تأجيل pgvector) في **[DATABASE.md](DATABASE.md)**.

مبادئ رئيسية: PHP Backed Enums بدل `database enum` (توافق PostgreSQL/SQLite)،
UTC داخليًا، `decimal` للأموال، cascade للبيانات الشخصية و`nullOnDelete` للسجلّات
المحفوظة.

## التوقيت والتوطين (i18n)

- **التخزين الداخلي دائمًا UTC** (`APP_TIMEZONE=UTC`). أي وقت في قاعدة البيانات بتوقيت UTC.
- **العرض للمستخدم** يُحوَّل إلى منطقته الزمنية؛ الافتراضي `Asia/Hebron`
  (`config('sanad.default_timezone')`).
- اللغة الافتراضية `ar` والاحتياطية `en` (`config('app.locale')` / `fallback_locale`).
- العملة الافتراضية `ILS` (`config('sanad.default_currency')`).

## الجاهزية لـ Multi-user SaaS

الأساس مُهيّأ للتوسّع دون إعادة هيكلة كبرى:

- **الإعدادات مركزية** في `config/sanad.php` (منطقة زمنية/عملة افتراضية لكل مستخدم لاحقًا).
- **الطوابير على Redis** تسمح بمعالجة غير متزامنة قابلة للتوسّع أفقيًا.
- **فصل التوقيت** (UTC داخليًا) شرط أساسي لخدمة مستخدمين في مناطق زمنية مختلفة.
- عند إضافة المصادقة لاحقًا، سيرتبط كل مورد (مهمة/تذكير/ذاكرة) بـ `user_id`،
  مع إمكانية إضافة عزل على مستوى المستأجر (tenant) إذا لزم.

## قرارات معمارية

انظر [DECISIONS.md](DECISIONS.md) لسجل القرارات (ADR).

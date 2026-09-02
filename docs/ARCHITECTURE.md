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
2. **Application / Domain** — منطق الأعمال (Tasks، Reminders، Memory…) — لم يُبنَ بعد.
3. **AI / Tooling** — طبقة تجريد لمزوّد الذكاء (OpenAI) و Function Calling — لم تُبنَ بعد.
4. **Infrastructure** — PostgreSQL، Redis، Queues، Scheduler، Horizon.

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

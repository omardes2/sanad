# قاعدة البيانات | Database — SANAD

> نموذج البيانات كما أُرسي في **Sprint 0B — Domain Model & Database Foundation**.
> لا يحتوي هذا Sprint على منطق WhatsApp/OpenAI؛ فقط الجداول والنماذج والعلاقات.

## مبادئ عامة

- **PHP Backed Enums** لكل الحقول من نوع status/type/direction/priority/channel — تُخزَّن
  كنصوص (`string`)، **بدون `database enum`** لضمان توافق PostgreSQL و SQLite (اختبارات).
- **التوقيت داخليًا UTC دائمًا** (`APP_TIMEZONE=UTC`). كل أعمدة `timestamp` بتوقيت UTC،
  ويُحوَّل للعرض إلى منطقة المستخدم (`config('sanad.default_user_timezone')`).
- **الأموال بنوع `decimal` فقط، ولا يُستخدم `float` أبدًا.**
- **حذف البيانات:** البيانات الشخصية التابعة للمستخدم تُحذف تسلسليًا (`cascade`)،
  بينما السجلات التي يجب الاحتفاظ بها (audit_logs، usage_events) تُفصل عن المستخدم
  بـ `nullOnDelete`.
- بنية جاهزة لأن تصبح **Multi-user SaaS** (كل مورد مرتبط بـ `user_id`).

## الجداول

### `users`
المستخدمون. WhatsApp أولًا، لذا **`phone`** هو المُعرِّف الأساسي لمستخدم عائد.

| عمود | نوع | ملاحظات |
|------|-----|---------|
| `name` | string | |
| `phone` | string, nullable, **unique** | بصيغة E.164 مثل `+970599123456` |
| `email` | string, nullable, **unique** | |
| `password` | string, nullable | مستخدمو WhatsApp بلا كلمة مرور |
| `timezone` | string | افتراضي `config('sanad.default_user_timezone')` = `Asia/Hebron` |
| `locale` | string(8) | افتراضي `ar` |
| `currency` | string(3) | افتراضي `ILS` |
| `preferred_reply_mode` | enum `ReplyMode` | `text` / `voice` / `auto` |
| `status` | enum `UserStatus` | `pending` / `active` / `suspended` |
| `onboarding_completed_at` | timestamp, nullable | |

> **منع تكرار المستخدم:** قيد `unique` على `phone` يضمن ألا يُنشأ مستخدم جديد لكل
> رسالة إذا كان الرقم موجودًا؛ منطق `firstOrCreate(phone)` سيُبنى في Sprint لاحق.

### `channel_accounts`
حسابات قنوات التواصل لكل مستخدم (WhatsApp/Web).

- `user_id` → `users` (**cascade**)
- `channel` enum `ChannelType` · `external_identifier` · `display_name?` · `metadata? json` · `status` enum `ChannelAccountStatus`
- **unique(`channel`, `external_identifier`)** — يمنع تكرار نفس المُعرِّف داخل القناة.

### `conversations`
- `user_id` → `users` (**cascade**) · `channel_account_id` → `channel_accounts` (**cascade**)
- `external_conversation_id?` · `status` enum `ConversationStatus` · `last_message_at?`
- index: (`user_id`,`status`)، `last_message_at`.

### `messages`
- `conversation_id` → `conversations` (**cascade**) · `user_id` → `users` (**cascade**)
- `direction` enum `MessageDirection` · `type` enum `MessageType`
- `external_message_id?` **unique** — يمنع معالجة نفس رسالة المزوّد مرتين (تُسمح قيم `null` متعددة للرسائل الصادرة)
- `in_reply_to_message_id?` → `messages` (**nullOnDelete**) **unique** — يربط الرد الصادر برسالته
  الواردة، والقيد الفريد يضمن **ردًّا واحدًا لكل رسالة واردة** (حاجز idempotency الأساسي للرد؛ أُضيف
  في Sprint 0C). علاقتا Eloquent: `inReplyTo()` و`reply()`.
- `text_content?` · `media_path?` · `metadata? json` · `processing_status` enum `MessageProcessingStatus` · `processed_at?`
- index: (`conversation_id`,`created_at`)، (`direction`,`processing_status`).

### `tasks`
- `user_id` → `users` (**cascade**) · `source_message_id?` → `messages` (**nullOnDelete**)
- `title` · `description?` · `status` enum `TaskStatus` · `priority` enum `TaskPriority` · `due_at?` · `completed_at?`
- index: (`user_id`,`status`)، `due_at`.

### `reminders`
- `user_id` → `users` (**cascade**) · `task_id?` → `tasks` (**nullOnDelete**) · `source_message_id?` → `messages` (**nullOnDelete**)
- `title` · `remind_at` (UTC) · `timezone` · `channel` enum `ChannelType` · `status` enum `ReminderStatus` · `sent_at?` · `attempts` (default 0) · `last_error?`
- **index (`status`,`remind_at`)** لخدمة الـScheduler في جلب التذكيرات المستحقة، + (`user_id`,`status`).

### `memories`
- `user_id` → `users` (**cascade**) · `source_message_id?` → `messages` (**nullOnDelete**)
- `category` · `content` · `importance` (1..5، يُفرض على مستوى التطبيق) · `metadata? json` · `archived_at?`
- **لا pgvector/embeddings في هذا Sprint** (انظر أدناه).

### `expenses`
- `user_id` → `users` (**cascade**) · `source_message_id?` → `messages` (**nullOnDelete**)
- `amount` **decimal(15,2)** · `currency` string(3) · `category?` · `merchant?` · `expense_date` (date) · `notes?`
- index: (`user_id`,`expense_date`)، (`user_id`,`category`).

### `webhook_events`
سجل خام للأحداث الواردة (idempotency).
- `provider` · `external_event_id` · `payload json` · `status` enum `WebhookEventStatus` · `received_at` · `processed_at?` · `error_message?`
- **unique(`provider`, `external_event_id`)** لضمان عدم ابتلاع الحدث مرتين.

### `usage_events`
تتبّع استخدام وتكلفة الذكاء الاصطناعي (الدفتر المالي، انظر PHASE_B2/PHASE_D).
- `user_id?` → `users` (**nullOnDelete** — نحتفظ بالسجل)
- `type` · `provider` · `model?` · `input_units` (default 0) · `output_units` (default 0) · `cost` **decimal(12,6)** · `metadata? json`.
- فهارس المالية (D1): `occurred_at`، `(plan_id, occurred_at)`، `(provider, model, occurred_at)`.

### `finance_mrr_snapshots` (D1)
لقطة MRR **محسوبة** يومية (UTC) لكل (عملة، باقة)، يكتبها `sanad:finance:snapshot` لليوم الحالي فقط ولا تُعدَّل أبدًا.
- `snapshot_date` · `captured_at` · `currency` · `plan_id?` (مرجع تاريخي **بلا FK**) · `plan_key` (`plan:<id>` هوية ثابتة لا تعتمد على slug، أو `none` كـmarker) · `plan_slug?` · `plan_price?` · `billing_period?` · `active_count` · `trialing_count` · `past_due_count` · `mrr_normalized` decimal(12,6) · `calculation_version`.
- فريد `(snapshot_date, currency, plan_key)`؛ الاشتراكات بلا باقة بعملة `XXX`.

### `subscription_events` (E0، append-only)
تاريخ حالات الاشتراك؛ يكتبه `SubscriptionHistory` داخل معاملة التغيير مع audit.
- `subscription_id` · `subscriber_id` (مرجعان تاريخيان **بلا FK**) · `event_type` (`baseline/activated/suspended/cancelled/extended/plan_changed/status_changed`) · `from_status?` · `to_status` · `from_plan_id?` · `to_plan_id?` · `effective_at` (UTC) · `source` · `actor_ref` · `reason?` · `correlation_id?` · `metadata?` · `baseline_key?` (فريد) · `created_at` فقط.

### `plan_price_versions` (E0)
نسخ الشروط المالية للباقة على فترات `[effective_from, effective_until)`؛ يكتبها `PlanPriceBook` تحت قفل صف الباقة.
- `plan_id` → `plans` (**restrictOnDelete**) · `price` · `currency` · `billing_period` · `effective_from` · `effective_until?` · `source` (`baseline/admin`) · `created_by?`.
- فهرس جزئي فريد: نسخة مفتوحة واحدة لكل باقة؛ على PostgreSQL قيود الفترة وعدم السلبية.

### `customer_payments` (E1)
هوية الدفعة وحقائقها الثابتة؛ الحالة الحالية **projection** يحدّثها `CustomerPaymentService` فقط تحت `FOR UPDATE`.
- `subscriber_id` (مرجع تاريخي **بلا FK**) · `user_id?` → `users` (**nullOnDelete**) · `gateway` (`manual` الآن) · `gateway_payment_ref?` (فريد مع `gateway` عند وجوده؛ لا يُخترع) · `idempotency_key` (إلزامي، فريد) · `amount` decimal(12,2) · `currency` · `gateway_fee_amount?` (**NULL = FEES UNKNOWN لا صفر**) · `fee_currency?` (= `currency` أو NULL) · `received_at` timestamp(6) (لحظة التحصيل) · `reference?`(64) · `reason_code?`(32) · `evidence_ref?`(191) — لا نص حر · `current_status` · `latest_event_id?` (state token) · `recorded_by_ref` · timestamps.
- الحقائق (المبلغ/العملة/التاريخ/المراجع/المفتاح) لا تتغيّر بعد الإنشاء (الموديل يرفض) ولا يُحذف الصف؛ على PostgreSQL قيود `amount > 0` واتساق الرسوم/عملتها.

### `customer_payment_events` (E1، append-only)
دورة حياة الدفعة الرسمية: `created / succeeded / failed / disputed / dispute_resolved` (enum + قيد CHECK على PostgreSQL).
- `customer_payment_id` → `customer_payments` (**restrictOnDelete**) · `event_type` · `occurred_at`(6) · `source` (`manual/gateway/system`) · `actor_ref` · `reason_code?` · `evidence_ref?` · `metadata?` · `created_at` فقط. لا update ولا delete. فهرس جزئي فريد `customer_payment_events_one_success_per_payment`: حدث `succeeded` واحد لكل دفعة.

### `customer_refunds` (E1، append-only)
استرداد جزئي/كلي ضدّ دفعة **نجحت فعليًا**؛ `Σ ≤ amount` الدفعة تحت قفل صفها، نفس العملة، `refunded_at ≥ received_at`.
- `customer_payment_id` (**restrictOnDelete**) · `gateway` · `gateway_refund_ref?` (فريد مع `gateway`) · `idempotency_key` (فريد) · `amount` · `currency` · `refunded_at`(6) · `reason_code` (إلزامي) · `evidence_ref?` · `recorded_by_ref` · `created_at` فقط.

### `payment_allocations` (E1، append-only)
إسناد النقد المحصَّل إلى فترة خدمة حدث اشتراك واحد (E0) — **attribution لا إيراد**؛ لا يُعدَّل عند الاسترداد.
- `customer_payment_id` (**restrictOnDelete**) · `subscription_event_id` → `subscription_events` (**restrictOnDelete**) · `subscription_id` · `subscriber_id` · `period_start` / `period_end` (snapshot من `to_period_*` للحدث؛ لا تُكتب يدويًا) · `amount` · `currency` · `allocated_at`(6) · `actor_ref` · `reason_code?` · `created_at` فقط. على PostgreSQL `amount > 0` و`period_end > period_start`.

### `refund_allocations` (E1، append-only)
إسناد استرداد إلى التخصيص الذي يعكسه: `Σ` لكل استرداد ≤ الاسترداد و`Σ` على كل تخصيص ≤ التخصيص.
- `customer_refund_id` (**restrictOnDelete**) · `payment_allocation_id` (**restrictOnDelete**) · `amount` · `currency` · `allocated_at`(6) · `actor_ref` · `reason_code?` · `created_at` فقط.

### `audit_logs`
سجل تدقيق **append-only**.
- `user_id?` → `users` (**nullOnDelete**)
- `action` · `subject_type?`/`subject_id?` (polymorphic) · `metadata? json`
- **`created_at` فقط — بلا `updated_at`** (`const UPDATED_AT = null`).

## العلاقات (ملخّص)

```
User 1─* ChannelAccount 1─* Conversation 1─* Message
User 1─* Conversation        User 1─* Message
User 1─* Task 1─* Reminder   Task ?─1 Message (source)
User 1─* Reminder            Reminder ?─1 Task, ?─1 Message
User 1─* Memory ?─1 Message  User 1─* Expense ?─1 Message
User 1─* UsageEvent (nullable)   User 1─* AuditLog (nullable)
```

## سياسة الحذف

| الجدول | عند حذف المستخدم |
|--------|-------------------|
| channel_accounts, conversations, messages, tasks, reminders, memories, expenses | **يُحذف** (cascade) |
| usage_events, audit_logs | **يبقى**، ويصبح `user_id = null` |
| tasks/reminders/memories/expenses.`source_message_id` عند حذف الرسالة | يصبح `null` (السجل يبقى) |

## قواعد UTC والعملات

- كل `timestamp` يُخزَّن ويُقرأ بتوقيت **UTC**؛ التحويل لمنطقة المستخدم عند العرض فقط.
- كل مبلغ مالي `decimal`؛ العملة عمود `string(3)` (ISO 4217)، الافتراضي `ILS`.

## لماذا تأجيل pgvector؟

- محرك الذاكرة الدلالي (بحث تشابه، embeddings) ليس ضمن نطاق Sprint 0B.
- إضافة `pgvector` تتطلب امتداد PostgreSQL غير متوفّر في SQLite، ما يكسر تشغيل
  الاختبارات على SQLite in-memory.
- لذلك `memories` تخزّن نصًا عاديًا الآن؛ ستُضاف أعمدة `embedding` وامتداد pgvector
  (ومسار اختبار منفصل) عند بناء **محرك الذاكرة** في Sprint لاحق.

## التشغيل والبيانات التجريبية

```bash
php artisan migrate:fresh          # الجداول فقط (بلا بيانات)
php artisan migrate:fresh --seed   # الجداول + بيانات تجريبية (local/testing فقط)
php artisan test                   # على SQLite in-memory
```

**حماية production:** `DatabaseSeeder` يستدعي `DemoDataSeeder` فقط عندما تكون البيئة
`local` أو `testing`. لذلك تشغيل `php artisan db:seed` (أو `migrate:fresh --seed`) على
production **لا يُنشئ** أي مستخدم/رسائل/مصاريف تجريبية. لتشغيل البيانات التجريبية صراحةً
في أي بيئة:

```bash
php artisan db:seed --class=DemoDataSeeder
```

البيانات التجريبية وهمية بالكامل (بما فيها رقم الهاتف `+970599000001`) وليست بيانات حقيقية.

النماذج في `app/Models`، الـEnums في `app/Enums`، الـFactories في `database/factories`،
والبيانات التجريبية في `database/seeders/DemoDataSeeder.php`.

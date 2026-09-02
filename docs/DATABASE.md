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
تتبّع استخدام وتكلفة الذكاء الاصطناعي.
- `user_id?` → `users` (**nullOnDelete** — نحتفظ بالسجل)
- `type` · `provider` · `model?` · `input_units` (default 0) · `output_units` (default 0) · `cost` **decimal(12,6)** · `metadata? json`.

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

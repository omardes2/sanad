# خارطة الطريق | Roadmap — SANAD

> خطة تطوّر تدريجية عبر Sprints. الحالي هو **Sprint 0**.

## ✅ Sprint 0 — Foundation (الحالي)

إرساء الأساس التقني دون أي منطق منتج:

- [x] مشروع Laravel 13 (PHP 8.4) في جذر المستودع.
- [x] Livewire 3 + Tailwind CSS 4 + دعم RTL.
- [x] PostgreSQL + Redis عبر Docker Compose.
- [x] الطوابير (Redis) + المجدول + الكاش + السجلات + Horizon.
- [x] `GET /api/health`.
- [x] صفحة رئيسية مؤقتة (عربية RTL).
- [x] Pint + Pest + GitHub Actions.
- [x] توثيق كامل (`docs/`).

**غير مشمول:** WhatsApp، OpenAI، الصوت، المهام، التذكيرات، الذاكرة، الفوترة، المصادقة، لوحة التحكم، النشر.

## ✅ Sprint 0B — Domain Model & Database Foundation

نموذج بيانات سَنَد الأساسي، دون ربط WhatsApp أو OpenAI:

- [x] 12 PHP Backed Enum في `app/Enums` (بدون `database enum`).
- [x] تحديث جدول `users` (phone E.164، timezone/locale/currency، reply mode، status…).
- [x] 10 جداول: channel_accounts، conversations، messages، tasks، reminders،
      memories، expenses، webhook_events، usage_events، audit_logs.
- [x] النماذج والعلاقات والـcasts والـscopes (`Reminder::due`، `Task::incomplete`،
      `Message::inbound`، `WebhookEvent::pending`).
- [x] Factories لكل النماذج + `DemoDataSeeder` ببيانات وهمية.
- [x] فهارس وقيود idempotency (unique للهاتف/الرسالة/حدث الويبهوك).
- [x] 15+ محور اختبار (Pest) على SQLite، وmigrations تعمل على PostgreSQL أيضًا.
- [x] توثيق `docs/DATABASE.md`.

**غير مشمول:** WhatsApp/OpenAI/Voice، إرسال التذكيرات، المصادقة، Dashboard،
Billing، pgvector، Deployment.

## ✅ Sprint 0C — Message Pipeline & Local Chat Simulator

نظام استقبال ومعالجة الرسائل، مستقل عن WhatsApp و OpenAI:

- [x] DTOs: `InboundMessageData`, `OutboundMessageData`, `AgentResponseData`, `ProcessResult`.
- [x] Contracts: `ChannelAdapter`, `AgentOrchestrator` + `ChannelRegistry` (DI).
- [x] Adapters: `WebSimulatorChannelAdapter` (محلي)، `WhatsAppChannelAdapter` (هيكل يرمي عند الإرسال الحقيقي).
- [x] `PlaceholderAgentOrchestrator` (رد حتمي دون OpenAI).
- [x] `MessageProcessor` (idempotency، race-safe، accepted/duplicate/rejected).
- [x] `ProcessInboundMessage` Job (طابور `messages`، tries/backoff، unique، status transitions، failed()).
- [x] صفحة `/dev/chat` Livewire RTL (local/testing فقط، 404 في production).
- [x] تعديل CI: `pull_request` + `push` إلى main فقط (لا تشغيل مزدوج).
- [x] اختبارات Pest شاملة + `docs/MESSAGE_PIPELINE.md`.

**غير مشمول:** WhatsApp/OpenAI حقيقيًا، Voice، وسائط، أدوات ذكية، مصادقة، Dashboard، Billing، Deployment.

## ✅ Sprint 0D — WhatsApp Cloud API Text Transport

تحويل محوّل واتساب من هيكل إلى تكامل نصّي جاهز للنشر (بلا اتصال حيّ بـ Meta):

- [x] إعداد مركزي `config/whatsapp.php` + `WhatsAppConfig` (fail-closed).
- [x] Webhook: `GET/POST /webhooks/whatsapp` بلا CSRF، توقيع HMAC-SHA256 على raw body.
- [x] حفظ `WebhookEvent` idempotent (SHA-256 للغلاف) + `ProcessWhatsAppWebhook` (طابور `webhooks`).
- [x] معالجة كل entry/changes/messages/statuses؛ نصّ فقط → `MessageProcessor`؛ الوسائط acknowledged.
- [x] تطبيع الهاتف E.164؛ تجاهل ما لا يخصّ Phone Number ID/WABA المهيّأين.
- [x] `WhatsAppChannelAdapter::send` عبر Graph API (retry: network/429/5xx فقط)؛ `ChannelDeliveryResult`.
- [x] حالات التسليم `MessageDeliveryStatus` + انتقالات monotonic عبر status webhooks.
- [x] اختبارات Pest شاملة (`Http::fake` فقط) + `docs/WHATSAPP_INTEGRATION.md`.

**غير مشمول:** اتصال حيّ بـ Meta، Templates، وسائط/صوت، OpenAI، مصادقة/Dashboard، نشر.

## ✅ Sprint 1 — Auth & Operator Dashboard

لوحة تحكّم داخلية للمشغّل مع مصادقة آمنة، تعيد استخدام النماذج القائمة:

- [x] مصادقة بلا تسجيل عام؛ صفحة دخول Livewire مع throttling وتجديد الجلسة.
- [x] أمر `sanad:make-admin` لإنشاء/ترقية أول مدير بكلمة مرور مخفية.
- [x] صلاحية عبر عمود `is_admin` (إضافة على جدول `users`) + middleware `admin`.
- [x] لوحة تحكم عربية RTL متجاوبة (Livewire + Tailwind): نظرة عامة + صفحات
      المحادثات والرسائل والمهام والتذكيرات والمصروفات.
- [x] صفحة حالة تكامل واتساب: مفعّل/غير مفعّل + وجود الإعدادات + Horizon/الطوابير،
      دون عرض أي رموز أو أسرار.
- [x] حماية كل صفحات اللوحة بـ `auth` + `admin` (guest → login، non-admin → 403).
- [x] اختبارات Pest للمصادقة والصلاحيات والصفحات والأمر وعدم تسريب الأسرار.

> **ملاحظة تسلسل:** بند "Sprint 1 — WhatsApp Inbound" في الجدول أدناه أُنجز فعليًا
> ضمن Sprint 0D، وبند المصادقة/اللوحة (المقترح سابقًا كـ Sprint 0E) نُفِّذ هنا تحت
> اسم Sprint 1 وفق أولويات المالك؛ الخارطة إرشادية كما هو منصوص أدناه.

**غير مشمول:** تحرير البيانات من اللوحة، OpenAI، الوسائط، تعدّد المستخدمين، النشر.

## ✅ Sprint 1.1 — WhatsApp Production Reliability & Onboarding

- [x] Horizon يستهلك طوابير `webhooks`/`messages` تلقائيًا (config).
- [x] onboarding تلقائي: كل رقم واتساب صالح ⇒ مشترك مستقل + channel account (بلا ربط بالمدير).
- [x] تطبيع E.164 في كل المسارات + idempotency عبر القيد الفريد.

## ✅ Sprint 2 — AI Orchestrator Foundation

- [x] بنية مزوّدين قابلة للتبديل خلف عقد `AiProvider` + `AiManager` (Groq أولًا).
- [x] `AiAgentOrchestrator` (بديل مباشر للـPlaceholder) — شخصية سَنَد عربية أولًا وتُطابِق لغة المستخدم.
- [x] خطّ مساهمي سياق قابل للتوسّع (persona + history) — جاهز لطبقة الذاكرة والأدوات لاحقًا.
- [x] معالجة timeout/429/5xx + fallback آمن دون تعطيل مسار واتساب، وتسجيل بلا أسرار.
- [x] اختبارات `Http::fake` شاملة.

**غير مشمول:** طبقة الذاكرة طويلة المدى، الأدوات/الإجراءات، الوسائط، الاشتراكات/الحصص.

## 🔜 Sprint التالي — (مقترح)

- [ ] تحليل ساكن: PHPStan/Larastan.
- [ ] طبقة ذاكرة المستخدم طويلة المدى (Contributor جديد على خطّ السياق).
- [ ] الاشتراكات والحصص لكل رقم واتساب.

## 🗺️ Sprints لاحقة (رؤية مبدئية)

| Sprint | الموضوع | أبرز المخرجات |
|--------|---------|----------------|
| 1 | WhatsApp Inbound | استقبال رسائل واتساب النصية عبر webhook والرد الأساسي |
| 2 | AI Understanding | فهم النية عبر OpenAI + Function Calling (طبقة الأدوات) |
| 3 | Tasks & Reminders | إنشاء/إدارة المهام والتذكيرات وإرسالها عبر المجدول |
| 4 | Memory | ذاكرة طويلة المدى للمستخدم |
| 5 | Media | Voice Notes، الصور، الفواتير، PDF، الروابط |
| 6 | Expenses | تسجيل المصاريف وقراءة الفواتير |
| 7 | Daily Summary | الملخّص اليومي والرد الصوتي |
| 8 | Billing & SaaS | الفوترة، تعدّد المستخدمين، لوحة التحكم |

> الخارطة إرشادية وقابلة للتعديل حسب الأولويات.

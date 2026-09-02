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

## 🔜 Sprint 0C — (مقترح)

- [ ] المصادقة الأساسية (Fortify/Breeze) وبنية الجلسات لواجهة إدارية.
- [ ] تحليل ساكن: PHPStan/Larastan.
- [ ] طبقة تجريد لمزوّد الذكاء (AI provider interface) دون ربط فعلي.
- [ ] هيكل استقبال WhatsApp webhook (توثيق العقد + تخزين `webhook_events` فقط).

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

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

## 🔜 Sprint 0B — Hardening & Tooling (مقترح)

تعزيز الأساس قبل بناء الميزات:

- [ ] المصادقة الأساسية (Laravel Fortify/Breeze أو Livewire starter) وبنية المستخدمين.
- [ ] تحليل ساكن: PHPStan/Larastan + قواعد Pint موسّعة.
- [ ] هجرات أساسية للمستخدمين + بنية جاهزة لـ multi-tenant.
- [ ] طبقة تجريد لمزوّد الذكاء (AI provider interface) دون ربط فعلي.
- [ ] هيكل استقبال WhatsApp webhook (توثيق العقد فقط، دون معالجة).
- [ ] تغطية اختبارات أوسع + CI matrix (PHP 8.4).

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

<div align="center">

# سَنَد | SANAD

**مساعدك الذكي الذي يفهم، يتذكّر وينفّذ.**

مساعد شخصي ذكي عربي يعمل (مستقبلًا) عبر WhatsApp.

</div>

---

> ⚠️ **الحالة:** Sprint 0 — Foundation. هذا هو الأساس التقني فقط؛ ميزات المنتج
> (WhatsApp، الذكاء الاصطناعي، المهام، التذكيرات…) **لم تُنفَّذ بعد**.

## ما هو سَنَد؟

سَنَد مساعد شخصي عربي هدفه فهم طلبات المستخدم عبر الرسائل النصية والصوتية والصور
والفواتير والملفات، ثم إنشاء المهام والتذكيرات، حفظ ذاكرة طويلة المدى، تسجيل المصاريف،
وإرسال ملخّص يومي — بالرد نصيًا أو صوتيًا. راجع [docs/PROJECT.md](docs/PROJECT.md).

## الحزمة التقنية

| الطبقة | التقنية |
|--------|---------|
| الإطار | Laravel 13 · PHP 8.4 |
| الواجهة | Livewire 3 · Tailwind CSS 4 · RTL |
| قاعدة البيانات | PostgreSQL 16 |
| الكاش/الطوابير/الجلسات | Redis 7 · Laravel Horizon |
| الاختبارات | Pest 4 |
| التنسيق | Laravel Pint |
| CI | GitHub Actions |

## التشغيل السريع

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
docker compose up -d          # PostgreSQL + Redis
php artisan migrate --seed    # الجداول + بيانات تجريبية (DemoDataSeeder)
npm run build
php artisan serve             # http://localhost:8000
```

فحص الصحّة: `curl http://localhost:8000/api/health`

الدليل الكامل: [docs/LOCAL_DEVELOPMENT.md](docs/LOCAL_DEVELOPMENT.md).

## الجودة

```bash
php artisan test         # اختبارات Pest
./vendor/bin/pint --test # فحص تنسيق PHP
npm run build            # بناء الأصول
```

## التوثيق

- [docs/PROJECT.md](docs/PROJECT.md) — فكرة المشروع والنطاق
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — المعمارية
- [docs/DATABASE.md](docs/DATABASE.md) — نموذج البيانات والجداول والعلاقات
- [docs/ROADMAP.md](docs/ROADMAP.md) — خارطة الطريق و Sprints
- [docs/DECISIONS.md](docs/DECISIONS.md) — سجل القرارات المعمارية
- [docs/LOCAL_DEVELOPMENT.md](docs/LOCAL_DEVELOPMENT.md) — التشغيل المحلي

## الترخيص

خاص — جميع الحقوق محفوظة.

# التشغيل المحلي | Local Development — SANAD

دليل تشغيل سَنَد على جهازك للتطوير.

## المتطلبات

- **PHP 8.4** مع الإضافات: `pdo_pgsql`, `redis`, `mbstring`, `intl`, `bcmath`, `openssl`.
- **Composer 2.x**
- **Node.js 20+** و **npm**
- **Docker + Docker Compose** (لتشغيل PostgreSQL و Redis)، أو خدمات محلية مكافئة.

## 1) الإعداد لأول مرة

```bash
# 1. الاعتماديات
composer install
npm install

# 2. ملف البيئة
cp .env.example .env
php artisan key:generate

# 3. تشغيل البنية التحتية (PostgreSQL + Redis)
docker compose up -d

# 4. الهجرات
php artisan migrate

# 5. (اختياري) بيانات تجريبية للتطوير المحلي
php artisan db:seed          # يشغّل DemoDataSeeder في local/testing فقط
# أو صراحةً في أي بيئة:
php artisan db:seed --class=DemoDataSeeder

# 6. بناء ملفات الواجهة
npm run build
```

> **حماية production:** `DatabaseSeeder` يستدعي `DemoDataSeeder` **فقط** في بيئتَي
> `local` و`testing`. تشغيل `php artisan db:seed` على production **لا** يُنشئ أي
> مستخدم أو رسائل أو مصاريف تجريبية. لإجباره صراحةً استخدم
> `php artisan db:seed --class=DemoDataSeeder`.

## 2) التشغيل اليومي

في نوافذ طرفية منفصلة (أو عبر `php artisan solo`/`concurrently`):

```bash
# خادم التطبيق
php artisan serve            # http://localhost:8000

# مترجم الأصول مع Hot Reload
npm run dev

# معالج الطوابير عبر Horizon (يشرف على عمّال Redis) — لازم لطابور "messages"
php artisan horizon
# بديل: php artisan queue:work redis --queue=messages

# المجدول (للمهام الدورية — مثل الملخّص اليومي لاحقًا)
php artisan schedule:work
```

### محاكي المحادثة المحلي `/dev/chat`

بعد تشغيل الخادم و**عامل الطوابير** (Horizon أو `queue:work`)، افتح:

```
http://localhost:8000/dev/chat
```

اختر مستخدمًا تجريبيًا، اكتب رسالة (جرّب «مرحبا»)، وسيظهر رد سَنَد تلقائيًا بعد معالجة
الـ Queue. الصفحة متاحة في **local/testing فقط** وتعيد **404** في production.
راجع **[MESSAGE_PIPELINE.md](MESSAGE_PIPELINE.md)** لتفاصيل مسار الرسائل.

> بديل عن Horizon أثناء التطوير: `php artisan queue:work redis`.

## 3) البنية التحتية عبر Docker Compose

`docker-compose.yml` يشغّل خدمتين فقط:

| الخدمة | المنفذ | ملاحظات |
|--------|--------|---------|
| PostgreSQL 16 | `5432` | قاعدة `sanad` / مستخدم `sanad` |
| Redis 7 | `6379` | كاش + طوابير + جلسات |

أوامر مفيدة:

```bash
docker compose up -d       # تشغيل
docker compose ps          # الحالة
docker compose logs -f     # السجلات
docker compose down        # إيقاف
docker compose down -v     # إيقاف + حذف البيانات
```

> إن لم يتوفّر Docker: شغّل PostgreSQL و Redis محليًا بنفس المنافذ، وأنشئ
> المستخدم/القاعدة يدويًا بما يطابق `.env`.

## 4) فحص الصحّة (Health Check)

```bash
curl http://localhost:8000/api/health
```

يعيد JSON يوضّح حالة التطبيق و PostgreSQL و Redis، **دون أي أسرار**.

## 5) الطوابير، المجدول، الكاش، السجلات

- **الطوابير (Queues):** على Redis. شغّل `php artisan horizon` (أو `queue:work redis`).
  لوحة Horizon متاحة على `/horizon` (محميّة في الإنتاج عبر Gate — تُضبط لاحقًا).
- **المجدول (Scheduler):** عرّف المهام في `routes/console.php` وشغّل
  `php artisan schedule:work` محليًا (أو Cron في الإنتاج:
  `* * * * * php artisan schedule:run`).
- **الكاش (Cache):** `CACHE_STORE=redis`. مسح: `php artisan cache:clear`.
- **السجلات (Logging):** قناة `stack` → `storage/logs/laravel.log`.
  متابعة حيّة: `php artisan pail`.

## 6) الجودة والاختبارات

```bash
# الاختبارات (Pest) — تعمل على SQLite in-memory، بلا خدمات خارجية
php artisan test          # أو: ./vendor/bin/pest

# تنسيق PHP (Laravel Pint)
./vendor/bin/pint         # إصلاح
./vendor/bin/pint --test  # فحص فقط (كما في CI)

# بناء الأصول
npm run build
```

## 7) مشاكل شائعة

| المشكلة | الحل |
|---------|------|
| `could not connect to Postgres` | تأكد من `docker compose up -d` وصحّة `DB_*` في `.env`. |
| `Connection refused` لـ Redis | تأكد أن Redis يعمل على `6379` و `REDIS_CLIENT=phpredis`. |
| صفحة بلا تنسيق | شغّل `npm run dev` أو `npm run build`. |
| تغييرات `.env` لا تظهر | `php artisan config:clear`. |

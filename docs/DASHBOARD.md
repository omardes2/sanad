# لوحة تحكّم المشغّل | Operator Dashboard — SANAD

لوحة تحكّم عربية RTL متجاوبة (Livewire + Tailwind) للاطّلاع على بيانات سَنَد،
محميّة بالمصادقة والصلاحيات. للقراءة فقط في هذه المرحلة (لا تحرير للبيانات).

## المصادقة والصلاحيات

- **لا يوجد تسجيل عام.** تُنشأ الحسابات فقط عبر أمر Artisan (أدناه).
- **الدخول:** `GET /login` (Livewire) — مع throttling (5 محاولات لكل بريد+IP)
  وتجديد الجلسة بعد النجاح. الخروج: `POST /logout` (محميّ CSRF).
- **الصلاحية:** عمود `is_admin` (boolean) على جدول `users` القائم — لا جدول أدوار
  منفصل. جميع مسارات اللوحة تمرّ بـ `auth` ثم `admin`:
  - زائر غير مسجّل → إعادة توجيه إلى `/login`.
  - مستخدم مسجّل غير مدير → `403`.

## إنشاء أول مدير

```sh
php artisan sanad:make-admin --name="اسم المدير" --email="admin@example.com"
# يُطلب إدخال كلمة المرور مرتين بشكل مخفي (لا تُمرَّر كوسيطة أمر إطلاقًا).
```

- كلمة المرور: 12 محرفًا على الأقل، مع تأكيد؛ تُجزّأ عبر cast النموذج (bcrypt).
- تشغيل الأمر على بريد موجود يُرقّيه إلى مدير (ويحدّث كلمة مروره).

## الصفحات

| المسار | الوصف |
|--------|-------|
| `/dashboard` | نظرة عامة: عدّادات المستخدمين/المحادثات/الرسائل/المهام/التذكيرات/المصروفات |
| `/dashboard/conversations` | قائمة المحادثات (مع عدد الرسائل وآخر نشاط) |
| `/dashboard/messages` | أحدث الرسائل: الاتجاه/النوع/المعالجة/التسليم |
| `/dashboard/tasks` | المهام: العنوان/الحالة/الأولوية/الاستحقاق |
| `/dashboard/reminders` | التذكيرات: الموعد/القناة/الحالة/المحاولات |
| `/dashboard/expenses` | المصروفات: المبلغ/الفئة/المتجر/التاريخ |
| `/dashboard/whatsapp` | حالة تكامل واتساب (أدناه) |
| `/dashboard/finance` (D2، `finance.view`) | المالية **المحسوبة**: Current MRR/ARR/ARPU لكل عملة (as-of now)، تكلفة معروفة/غير مسعَّرة/coverage لنافذة UTC، breakdowns، أعلى المشتركين (معرّف داخلي)، MRR Snapshot History (run-rate لا إيراد)، `Gross Margin: NOT AVAILABLE — Phase E`. تصدير CSV بـ`finance.export` على `/dashboard/finance/export` |
| `/dashboard/finance/payments` (E1، `finance.payments.manage` = super_admin + finance) | صفحة إدارية دنيا: **Record Manual Payment** (created → succeeded، مفتاح idempotency مولَّد مع النموذج، رسوم فارغة = `FEES UNKNOWN`)، **Record Refund**، **Allocate Payment** (الفترة من حدث اشتراك للمشترك نفسه فقط)، **Allocate Refund**، وملخّص Cash Collected / Refunds / Net Cash / Fees / Allocated لكل عملة لنافذة UTC. كل إجراء يعيد فحص الصلاحية server-side. لا لوحة، لا رسوم بيانية، لا Revenue، لا Gross Profit (E5). |
| `/dashboard/finance/reconciliation` (E2، `finance.reconcile` = super_admin + finance) | صفحة إدارية دنيا: **Record Invoice** (مسودة بمفتاح idempotency مولَّد) · **Add Line** (موقَّع) · **Confirm / Void / Supersede** (بـtoken الحالة) · **Create Reconciliation** (تخصيصات دليل صريحة من أسطر مؤكَّدة أو مبلغ يدوي مُدلَّل) · **Confirm Zero** (كتابة `ZERO` حرفيًا + سبب + دليل) · **Add Adjustment**، وجدول التكلفة المسوّاة لكل نطاق لنافذة أشهر UTC (Base / Adjustments / Adjusted / Known Calculated frozen / coverage / Variance أو UNKNOWN / flags). كل إجراء يعيد فحص الصلاحية server-side. لا CSV، لا cash contribution، لا Gross Profit. |

جميع القوائم مرقّمة الصفحات (pagination) وتعيد استخدام النماذج القائمة مباشرةً.

## صفحة حالة واتساب — بلا أسرار

تعرض **فقط**:

- مفعّل / غير مفعّل (`WHATSAPP_ENABLED`).
- وجود كل إعداد مطلوب كـ boolean (Access Token، App Secret، Verify Token،
  معرّف رقم الهاتف، معرّف WABA، إصدار Graph API) — **دون عرض أي قيمة**.
- جاهزية الإرسال/الاستقبال (مشتقّة من `WhatsAppConfig`).
- حالة Horizon (يعمل/متوقّف/غير متاح)، اتصال Redis، وأحجام الطوابير
  (`webhooks`، `messages`، `default`).

لا يُقرأ أو يُطبع أي token أو secret أو رقم هاتف على هذه الصفحة (اختبار انحدار
يؤكّد ذلك).

## التجربة محليًا

```sh
# 1) الخدمات
php artisan migrate
php artisan horizon        # اختياري — لعرض Horizon كـ"يعمل" في صفحة الحالة

# 2) إنشاء مدير
php artisan sanad:make-admin --name="مدير" --email="admin@sanad.local"

# 3) التشغيل
php artisan serve          # ثم افتح http://localhost:8000/login
```

بعد الدخول ستُحوَّل تلقائيًا إلى `/dashboard`.

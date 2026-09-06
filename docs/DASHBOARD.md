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
| `/dashboard/finance` (D2 + E5.1، `finance.view`) | نظرة مالية بثلاث مفردات منفصلة لا تُخلط في رقم واحد: **Run-rate الحالي** (MRR/ARR/ARPU لكل عملة as-of now) · **Calculated** (تكلفة معروفة/غير مسعَّرة/coverage لنافذة UTC، breakdowns، أعلى المشتركين بمعرّف داخلي، `Gross Margin: NOT AVAILABLE`) · **Cash** (النافذة نفسها، `LIVE / CURRENT`: Gross Cash Collected / Refunds / Net Cash / Gateway Fees أو `FEES UNKNOWN` لكل عملة أصلية، وإجماليات بعملة التقرير فقط عند اكتمال كل البنود وإلا `INCOMPLETE / NOT AVAILABLE`) · **Reconciled** (صف لكل شهر تقويمي UTC بعملة التقرير الحالية: `FROZEN CLOSE REVISION n` من صف الإقفال الحالي بلا إعادة تقييم، أو `LIVE / CURRENT` من preflight مع الشروط المانعة؛ سلسلة بلا إجمالي — لا جمع عبر الأشهر أو العملات أو المراجعات) · MRR Snapshot History. لافتات مشتركة للشروط المانعة/التحذيرات. روابط CSV بـ`finance.export`: `/dashboard/finance/export` (Calculated، D2) و`/finance/cash/export?from&to` و`/finance/cost/export?from=YYYY-MM&to=YYYY-MM` و`/finance/fx/export?from&to`. |
| `/dashboard/finance/close/{close}` (E5.1، `finance.view`) | تفاصيل إقفال **مجمَّد**: المراجعة وسلسلتها (previous / reopened)، عملة التقرير، الفترة UTC، الأرقام السبعة المجمَّدة، الشروط المسجَّلة، hash القانوني، صفوف المدخلات من `finance_period_close_inputs` مجمّعة بالنوع (المبلغ الأصلي، الحالة، مبلغ التقرير، conversion / fx_rate_id / rate_date / snapshot / direction، مراجع التسوية) — لا preflight حي لأي رقم. `CHECK CURRENT DRIFT` إجراء عند الطلب فقط ونتيجته معلوماتية بجوار القيم المجمَّدة. سجل تدقيق للقراءة مع رابط إلى صفحة التدقيق بفلاتر الموضوع. CSV بـ`finance.export` على `/finance/close/{close}/export` يقرأ الصفوف المجمَّدة فقط. لا PII. |
| `/dashboard/finance/payments` (E1، `finance.payments.manage` = super_admin + finance) | صفحة إدارية دنيا: **Record Manual Payment** (created → succeeded، مفتاح idempotency مولَّد مع النموذج، رسوم فارغة = `FEES UNKNOWN`)، **Record Refund**، **Allocate Payment** (الفترة من حدث اشتراك للمشترك نفسه فقط)، **Allocate Refund**، وملخّص Cash Collected / Refunds / Net Cash / Fees / Allocated لكل عملة لنافذة UTC. كل إجراء يعيد فحص الصلاحية server-side. لا لوحة، لا رسوم بيانية، لا Revenue، لا Gross Profit (E5). |
| `/dashboard/finance/reconciliation` (E2، `finance.reconcile` = super_admin + finance) | صفحة إدارية دنيا: **Record Invoice** (مسودة بمفتاح idempotency مولَّد) · **Add Line** (موقَّع) · **Confirm / Void / Supersede** (بـtoken الحالة) · **Create Reconciliation** (تخصيصات دليل صريحة من أسطر مؤكَّدة أو مبلغ يدوي مُدلَّل) · **Confirm Zero** (كتابة `ZERO` حرفيًا + سبب + دليل) · **Add Adjustment**، وجدول التكلفة المسوّاة لكل نطاق لنافذة أشهر UTC (Base / Adjustments / Adjusted / Known Calculated frozen / coverage / Variance أو UNKNOWN / flags). كل إجراء يعيد فحص الصلاحية server-side. لا CSV، لا cash contribution، لا Gross Profit. |
| `/dashboard/finance/fx` (E3، `finance.fx.manage` = super_admin + finance) | صفحة إدارية دنيا: **Create FX Pair** (زوج قانوني واحد باتجاه رسمي) · **Record Rate for Date** / **Correct** (مراجعة جديدة بمعرّف المراجعة الحالية المتوقعة) · **Convert subject for Reporting** (بـ`fx_rate_id` صريح لتاريخ سياسة الموضوع) · **Set Reporting Currency** (كتابة الرمز حرفيًا)، وعرض النقد والتكلفة المسوّاة بعملة التقرير: الأصل دائمًا + `NATIVE` / `CONVERTED` (السعر وتاريخه واتجاهه) / `NOT CONVERTED`، والإجمالي `INCOMPLETE / NOT AVAILABLE` إن نقص بند. صفحة التسوية (E2) تقبل `fx_rate_id` صريحًا لكل تخصيص دليل بعملة مختلفة. |
| `/dashboard/finance/close` (E4 + E5.1، `finance.view` للقراءة؛ الإقفال/إعادة الفتح `finance.close_period` = super_admin فقط) | Preflight لشهر UTC (`LIVE / CURRENT`: المقاييس السبعة أو `NOT AVAILABLE`، لافتات الشروط المانعة/المعلوماتية، hash)، **Close** (كتابة `CLOSE YYYY-MM`)، **Reopen** (كتابة `REOPEN YYYY-MM` + سبب + دليل)، سجل الإقفالات والمراجعات من الصفوف المجمَّدة فقط (بلا preflight لكل صف): الإقفال الحالي يعرض `DRIFT SINCE CLOSE` / `NO DRIFT` بمقارنة hash الحي المعروض أصلًا، والمراجعات الأقدم بزر `CHECK CURRENT DRIFT` عند الطلب؛ روابط التفاصيل وCSV. finance يرى الصفحة للعرض فقط. |

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

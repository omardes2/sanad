# Phase D — Financial Analytics (Calculated)

> **مفردات ثابتة:** كل رقم في Phase D هو **Calculated / Estimated** (تقديري محاسبي داخلي). لا تُستخدم كلمات
> `Actual / Collected / Reconciled` في الكود ولا في اللوحة قبل Phase E.

## 1) Audit قبل D (main `95150da`)

| البند | الواقع |
|---|---|
| مصدر التكلفة | `usage_events` (يكتبه `UsageRecorder` فقط) بأعمدة `provider_cost / communication_cost / external_cost / total_cost` وعملة `billing.cost_currency` (USD) و`cost_source`. |
| المعروف vs غير المسعَّر | `cost_source ∈ {model_price, config_rate}` معروف؛ `none / currency_mismatch / NULL` غير مسعَّر — صفره **ليس** تكلفة. |
| التواصل | الحاسبة تستطيع تسعير بُعدَي WhatsApp من `billing.cost_rates` لكن **لا منتِج** يسجّل أحداث WhatsApp في الدفتر؛ ومسار WhatsApp كان يعيد `config_rate` حتى بمعدّل 0 (معروف بصفر). |
| الخارجي | `external_cost` بلا منتِج إطلاقًا. |
| الإيراد | `plans.price` قابل للتعديل بلا تاريخ ولا تدقيق؛ `subscriptions` صف واحد لكل مشترك بلا تاريخ حالات؛ لا جداول payments/invoices/refunds/fx. |
| العملات | باقات بـILS أو USD، تكلفة بـUSD، لا FX. |
| RBAC | `usage.view_costs` و`usage.export` (super + finance) فقط؛ لا صلاحية للإيراد/الهامش. |
| الفهارس | لا فهرس على `occurred_at` وحده ولا `(plan_id, occurred_at)` ولا `(provider, model, occurred_at)`. |

## 2) القرارات المعتمدة

1. **MRR snapshots** (`finance_mrr_snapshots`): تبدأ من أول تشغيل بعد D فقط؛ لا backfill، لا back-dating، لا خيار تاريخ. الصف self-contained (`snapshot_date, captured_at, currency, plan_id` مرجع تاريخي بلا FK، `plan_slug, plan_price, billing_period, active_count, trialing_count, past_due_count, mrr_normalized, calculation_version`). إعادة التشغيل لنفس اليوم = no-op معلَن، ولا يُعاد كتابة أي snapshot.
2. **الإيراد التاريخي**: لا يُحسب من الحالة/السعر الحاليين. D يعرض `Current Calculated MRR / ARR / ARPU` كـ*as-of now*، واتجاه MRR من أول snapshot فقط. أي فترة أقدم: `Historical Revenue = NOT AVAILABLE` (لا صفر ولا تقدير).
3. **الهامش**: لا `Estimated Gross Profit` لفترة لا يمكن إعادة بناء إيرادها. وجود unpriced أو currency mismatch أو coverage ناقصة ⇒ `MARGIN UNKNOWN`؛ أرقام الجزء المعروف تُعرض ثانوية ولا تُسمّى هامشًا كاملًا.
4. **Cost coverage** مفهوم صريح في النتيجة: provider (unpriced ⇒ incomplete)، communication (لا منتِج؛ وجود استخدام بقناة WhatsApp أو قناة مجهولة ⇒ `COMMUNICATION COST COVERAGE INCOMPLETE`)، external (`NO PRODUCER` دائمًا). الـKnown Cost ≠ تكلفة الخدمة الكاملة ما لم تكتمل الثلاثة.
5. **WhatsApp rate = 0 ⇒ `cost_source = none`** للصفوف الجديدة فقط؛ لا إعادة حساب لأي صف تاريخي.
6. **`past_due`** خارج MRR/الإيراد المحسوب؛ يُعرض منفصلًا ولا يُسمى collected.
7. **العملات**: لا FX؛ MRR/ARR/ARPU لكل عملة؛ لا جمع ILS+USD؛ لا هامش بين عملتي إيراد وتكلفة مختلفتين.
8. **الحساب**: لا `(float)`؛ scaled-integer داخل SQL + `DecimalMath`؛ اختبار parity على SQLite وPostgreSQL بنفس الـfixture.
9. **تدقيق الباقات**: لا plan-price history في D (لـE)، لكن أي تعديل إداري على `price / currency / billing_period` يمرّ بـAudit atomic (بلا PII).
10. **الفهارس**: `usage_events(occurred_at)`، `(plan_id, occurred_at)`، `(provider, model, occurred_at)`.
11. **RBAC**: `finance.view` و`finance.export` لـ`super_admin` و`finance` فقط؛ operations/support = 403.
12. **الوقت**: UTC لكل bucket يومي/شهري، مع توضيح ذلك في كل صفحة وCSV.
13. **PRان**: D1 Financial Foundation (هذا) ثم D2 Finance Dashboard & Export بعد اعتماد D1.

## 3) D1 — Financial Foundation (منفَّذ)

### الحساب
- `App\Services\Finance\FinanceSql`: مقاطع SQL لكل محرّك (pgsql/sqlite فقط، غيرهما يُرفض): جمع المال كأعداد صحيحة مقياس 6 داخل قاعدة البيانات (`ROUND(x*1e6)::bigint` / `CAST(ROUND(x*1e6) AS INTEGER)`), buckets تاريخ UTC (`to_char` / `strftime`)، مسنديّ priced/unpriced المطابقين لـ`UsageEvent::priced()/unpriced()`.
- `App\Services\Finance\FinanceQuery`: المكان الوحيد لتجميعات المالية فوق `UsageQuery` (نفس النافذة والفلاتر + `plan_id` (id|none) + `channel` + `attribution` (subscriber|system)). يعيد:
  - `totals()` → `CostTotals`: known provider/communication/external/total (نصوص decimal من الصفوف المسعَّرة فقط)، عدّ unpriced حسب السبب، توكنز المسعَّر/غير المسعَّر، صفوف النظام، صفوف WhatsApp، صفوف بقناة مجهولة.
  - `coverage()` → `CostCoverage` بحالات `CoverageStatus` (complete / incomplete / no_producer / not_applicable) وتحذيرات ثابتة النص: `PROVIDER COST COVERAGE INCOMPLETE (n unpriced rows)`, `COMMUNICATION COST COVERAGE INCOMPLETE (...)` أو `COMMUNICATION COST: NO PRODUCER`, `EXTERNAL COST: NO PRODUCER`. `knownCostIsFullServiceCost()` لا يصبح true ما دام `CostProducers::EXTERNAL = false`.
  - `byPlan()` (النظام في bucket مستقل `attribution=system`، بلا باقة `plan_id=null`)، `byProviderModel()`، `byOperationChannel()`، `topSubscribers(limit ≤ 50)` (بلا صفوف النظام، معرّف المشترك فقط)، `trend('day'|'month')` (UTC؛ النافذة حتى 92 يومًا للتفصيل و366 للاتجاه الشهري).
- `App\Services\Finance\CostProducers`: حقيقة كود — `PROVIDER=true`, `COMMUNICATION=false`, `EXTERNAL=false`؛ لا تُقلب إلا مع الكود الذي يكتب تلك الصفوف.
- `DecimalMath`: `sum()`, `mulDiv()` (half-up مع كشف overflow قبل الضرب), `intFromDb()` (يرفض أي شيء غير عدد صحيح؛ لا float).
- `RevenueNormalizer::monthly(price, period)`: monthly = price، yearly = ÷12، weekly = ×52÷12، daily = ×365÷12، none = 0 — دقّة عمل 8 ثم تقريب واحد إلى 6.
- `MrrCalculator::current()` (calculation_version **1**): `active_count` = حالة `active` و`current_period_end` NULL أو مستقبلية (المنتهي لا يكسب MRR)؛ `trialing_count` و`past_due_count` عدّ فقط؛ MRR = السعر الشهري المكافئ × active لكل (عملة، باقة)؛ الاشتراكات بلا باقة تحت `plan_key=none` بعملة `XXX` (ISO 4217 "no currency") بلا إيراد. `MrrSnapshotSet::byCurrency()` يعطي MRR/ARR/ARPU لكل عملة على حدة.
- `CostCalculator`: مسار WhatsApp بمعدّل 0 ⇒ `CostSource::None` (صفوف جديدة فقط).

### الأمر `sanad:finance:snapshot`
- يلتقط صفوف اليوم الحالي **UTC** فقط؛ لا وسيط تاريخ (أي `--date` مرفوض بواسطة console).
- `--dry-run` يعرض دون كتابة.
- إن وُجدت صفوف اليوم: `already captured (n row(s)) — nothing written` وخروج 0، بلا إعادة كتابة حتى لو تغيّر السعر أو المشتركون بعد الالتقاط.
- الكتابة atomic: كل الصفوف + سجل تدقيق `finance.mrr_snapshot_captured` (actor console، أعداد فقط) في معاملة واحدة؛ التعارض على المفتاح الفريد `(snapshot_date, currency, plan_key)` ⇒ rollback كامل ورسالة `captured concurrently … nothing written` وخروج 0.
- يوم بلا اشتراكات يُلتقط بصف صفر واحد (`XXX/none`) حتى لا يُخترع لاحقًا في نفس اليوم.
- **يدوي**: لا scheduler في D1.

### تدقيق الباقات (atomic)
- `Plans::save()`: الحفظ + `AuditLogger::record()` في `DB::transaction` واحدة. جديد ⇒ `plan.created` بقيم `price/currency/billing_period`؛ تعديل ⇒ `plan.financials_updated` بـfrom→to للحقول المالية المتغيّرة فقط؛ تغيير الاسم/الحدود وحده لا يُنتج سجلًا ماليًا. فشل التدقيق ⇒ لا يتغيّر السعر. السعر يُقبل كـdecimal عادي بمنزلتين كحدّ أقصى (`^\d{1,8}(\.\d{1,2})?$`) ويُخزَّن بصيغة قانونية (`25.50`). لا PII.

### Migrations (إضافية)
| Migration | المحتوى |
|---|---|
| `2026_09_06_000701_add_finance_indexes_to_usage_events_table` | فهارس `usage_events_occurred_idx`, `usage_events_plan_occurred_idx`, `usage_events_provider_model_occurred_idx` (فهارس فقط). |
| `2026_09_06_000702_create_finance_mrr_snapshots_table` | الجدول أعلاه، فريد `(snapshot_date, currency, plan_key)`، `plan_id` بلا FK. |

حدّ rollback في `UsageLedgerMigrationTest` أصبح **13** (11 + 2) بعد فحص قائمة الهجرات الفعلية.

### RBAC
| Permission | super_admin | operations | finance | support |
|---|:-:|:-:|:-:|:-:|
| `finance.view` (D1) | ✓ | | ✓ | |
| `finance.export` (D1) | ✓ | | ✓ | |

بقية المصفوفة كما في [PHASE_C_PLAN.md §4](PHASE_C_PLAN.md). لا مسار/صفحة تستخدمهما بعد (D2).

### الاختبارات
- `tests/Feature/Finance/FinanceQueryTest`: fixture حتمي (priced model_price/config_rate، none، currency_mismatch، legacy NULL، صف نظام، بلا باقة، خارج النافذة): المجاميع نصوص decimal دقيقة من المسعَّر فقط، عدّ unpriced بالسبب، coverage، byPlan/byProviderModel/byOperationChannel، ترتيب أعلى المشتركين واستبعاد النظام، buckets يومية/شهرية UTC (23:59:59 ينتمي ليومه)، الفلاتر، حدود النافذة.
- `DecimalParityTest`: 10 × 0.100000 = `1.000000`، المنزلة السادسة، المبالغ الكبيرة، مقاطع SQL حسب المحرّك ورفض غيره، `intFromDb`، `mulDiv`، تطبيع الفترات الخمس، الضرب بالعدد. **يعمل كما هو على SQLite وPostgreSQL** (parity بنفس القيم المتوقعة).
- `MrrSnapshotTest`: MRR/ARR/ARPU لكل عملة، الاشتراكات المنتهية/الملغاة/التجريبية/المتأخرة، الالتقاط + التدقيق، no-op على إعادة التشغيل مع تغيّر السعر والمشتركين، رفض `--date`، dry-run، يوم فارغ، بقاء الصف بعد حذف الباقة.
- `PlanFinancialsAuditTest`: إنشاء/تعديل/لا تغيير مالي/atomic rollback/رفض صيغ السعر.
- `tests/Feature/Billing/PostgresSnapshotConcurrencyTest`: 6 عمليات OS متزامنة على PostgreSQL ⇒ التقاط واحد، 5 no-op، مجموعة واحدة كاملة، سجل تدقيق واحد.
- تحديثات: `CostCalculatorTest` (WhatsApp rate 0 ⇒ none)، `RbacBootstrapTest`/`AccessMatrixTest` (finance.*)، `UsageLedgerMigrationTest` (13 + الفهارس + الجدول).

### ترتيب النشر لـD1 (بعد الدمج وبموافقة صريحة)
1. `php artisan migrate --force` (فهارس + `finance_mrr_snapshots`).
2. `php artisan sanad:rbac:bootstrap` (dry-run) → مراجعة → `--apply` لمزامنة `finance.view` / `finance.export`.
3. لا تشغيل تلقائي لـ`sanad:finance:snapshot`؛ أول تشغيل يدوي هو بداية تاريخ MRR.

## 4) D2 — Finance Dashboard & Export (مخطَّط، لا يبدأ قبل اعتماد D1)

`/dashboard/finance` (`permission:finance.view`) وتصدير CSV (`finance.export`) فوق `FinanceQuery` نفسه: بطاقات Known Cost بمكوّناتها، unpriced (عدّ + توكنز بلا مبلغ)، تحذيرات coverage، Current MRR/ARR/ARPU لكل عملة (as-of now) مع `past_due` منفصل، اتجاه MRR من snapshots (ما قبلها `NOT AVAILABLE`)، `MARGIN UNKNOWN` عند أي نقص، breakdowns باقة/مزوّد/نموذج/عملية/قناة، أعلى مشتركين تكلفة، اتجاه يومي/شهري UTC (موضَّح في الصفحة وCSV)، بلا PII، بلا مكتبة رسوم.

## 5) مؤجَّل صراحة إلى Phase E — Reconciliation & Payments

Collected Revenue، payments، refunds، gateway fees، actual provider invoices، reconciled costs، historical plan prices، subscription state history، FX، adjustments على الصفوف التاريخية، تسجيل أحداث WhatsApp نفسها في الدفتر.

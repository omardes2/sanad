# Phase E — Reconciliation & Payments

> **المفردات**: E هي أول مرحلة تُستخدم فيها `Collected / Actual / Reconciled`. كل رقم فيها مصدره **مستند حقيقي** (دفعة، استرداد، فاتورة مزوّد، تسوية موقَّعة) أو **حدث حالة مسجَّل وقت وقوعه**. لا يُعاد بناء الماضي؛ ما قبل E يبقى `NOT AVAILABLE`. الدفتر المحسوب (`usage_events`) لا يُلمس أبدًا.

## 1) Audit قبل E (main `1663916`)

| البند | الواقع |
|---|---|
| بنية الدفع | لا شيء: لا payments/invoices/refunds/fx/adjustments، لا عقد بوابة، لا webhook دفع. `subscriptions.provider*` محجوزة. |
| حالات الاشتراك | تُغيَّر يدويًا فقط (`SubscriberDetail` → `SubscriptionService`) و`assignDefaultIfEnabled` عند onboarding؛ بلا تدقيق وبلا تاريخ؛ صف واحد لكل مشترك يُعدَّل في مكانه. |
| أسعار الباقات | قابلة للتعديل؛ منذ D1 مدقَّقة atomic لكن بلا تاريخ مهيكل. |
| التكلفة المحسوبة | `usage_events` immutable بمكوّناتها الثلاثة و`cost_source`/`pricing_snapshot`؛ تواصل/خارجي بلا منتِج. |
| العملات | باقات ILS/USD، تكلفة USD، لا FX. |
| الأدوات الجاهزة | `DecimalMath`, `FinanceSql`, `AuditLogger` (atomic), `PriceBook` (قفل الصف الأب + رفض التداخل — النمط المرجعي)، RBAC `finance.*`، نمط probes لتزامن PostgreSQL. |

## 2) القرارات المعتمدة (تحكم E كلها)

1. **التقسيم**: E0 History · E1 Payments/Refunds/Allocation · E2 Provider Invoices + Cost Reconciliation · E3 FX · E4 Financial Close + Reconciled Metrics · E5 Admin UI/Exports/RBAC. PR مستقل لكل مرحلة.
2. **لا backfill** من الـaudit. **Baseline رسمي** عبر `sanad:finance:history-baseline` (dry-run افتراضي، `--apply` صريح، idempotent، concurrency-safe، بلا خيار تاريخ، بلا cron، لا يعمل عند deploy) يسجّل الحالة الحالية بـ`effective_at/effective_from = وقت الالتقاط` و`source = baseline`.
3. **`subscription_events`** append-only بأنواع ثابتة: `baseline, activated, suspended, cancelled, extended, plan_changed, status_changed`. كل mutation في `SubscriptionService`: قفل الصف → حفظ → event → audit في معاملة واحدة؛ فشل ⇒ لا event. الـbaseline من `NULL` إلى الحالة الحالية (لا اختراع للماضي).
4. **`plan_price_versions`** بصرامة `model_prices`: append-only، `[effective_from, effective_until)`، قفل صف الباقة الأب، لا تداخل، التغيير المالي يغلق النسخة الحالية ويفتح جديدة في نفس المعاملة، تغيير الاسم/الوصف لا ينشئ نسخة، أول نسخة لباقة قديمة تبدأ من وقت الـbaseline فقط. اختبار تزامن PostgreSQL لتغييرين ماليين متزامنين.
5. **Cash Collected ≠ Payment Allocation**: Cash Collected من حدث الدفع (`received_at`) ناقص الاستردادات (`refunded_at`) بنفس العملة؛ `payment_allocations` = إسناد النقد المحصَّل إلى اشتراك/فترة خدمة (`Allocated Collected Amount`)، لا تعريف للتحصيل ولا "Revenue".
6. **Refund allocation** عبر `refund_allocations` append-only؛ التخصيصات القديمة لا تُعاد كتابتها؛ قيود: refunds ≤ الدفعة الناجحة، allocated refund ≤ الاسترداد؛ قفل صفوف PostgreSQL.
7. **Payment lifecycle**: `customer_payments` = هوية/حقائق ثابتة؛ `customer_payment_events` append-only (`created, succeeded, failed, disputed`)؛ الحالة الحالية projection مشتق. Manual أولًا (يبدأ succeeded)؛ لا CyberSource حيّ في E1.
8. **Provider invoices**: `provider_invoice_events` (draft → confirmed) ولا overwrite بعد التأكيد؛ التصحيح credit/adjustment/replacement.
9. **Actual Provider Cost ≠ الفاتورة المؤكَّدة**: الفاتورة evidence؛ التسوية تحدّد الجزء الخاص بالمزوّد/الفترة/العملة/المكوّن؛ بعد اكتمالها يصبح `Actual / Reconciled Cost`. لا استخدام أعمى لإجمالي فاتورة متعددة الفترات/الضرائب.
10. **Reconciliation لكل مكوّن** (provider / communication / external) بمصدر (provider invoice / external invoice / manual evidenced)؛ الصفر الفعلي = attestation صريحة `actual_amount = 0` بسبب ومرجع وفاعل ووقت (confirmed zero ≠ unknown).
11. **`usage_events` immutable** بعد E: لا تعديل لأي عمود تكلفة؛ الفروق في reconciliation/adjustments بجانبه.
12. **المصطلحات**: `Reconciled Cash Contribution = Cash Collected net of refunds − Reconciled Service Cost` (cash-basis داخلي، موسوم). `Reconciled Gross Profit / Margin` يبقى `NOT AVAILABLE` حتى تُقرَّر سياسة Revenue Recognition (قرار محاسبي مستقل).
13. **Gateway fees** منفصلة: Cash Collected · Refunds · Gateway Fees · Net Cash After Gateway Fees · Reconciled Service Cost · Reconciled Cash Contribution؛ `gateway_fee_amount = NULL` = UNKNOWN لا صفر.
14. **FX**: إعداد `finance.reporting_currency` (افتراضيه `billing.cost_currency`)؛ الأصول بعملتها؛ أي تحويل يحمل `fx_rate_id` في التسوية/الإقفال؛ لا تحويل ضمني؛ أسعار يدوية فقط في E3.
15. **Period close** `finance.close_period` لـsuper_admin فقط؛ شروط الإقفال (كل المكوّنات reconciled أو confirmed zero، refunds مكتملة، fees معروفة أو موسومة incomplete، FX موجود، لا disputes)؛ append-only؛ إعادة الفتح = سجل جديد بـ`previous_close_id` وسبب وفاعل.
16. **Immutability semantics** بدقة: ledgers/events/finalized documents لا update؛ projection/current-state rows تُحدَّث فقط عبر service + lock + audit.
17. **RBAC**: `finance.payments.manage`, `finance.reconcile`, `finance.fx.manage` = super_admin + finance؛ `finance.close_period` = super_admin فقط؛ operations/support = 403؛ كل write service يعيد فحص الصلاحية server-side.
18. **ترتيب التنفيذ**: E0 فقط الآن؛ لا payments/refunds/invoices/FX/reconciliation/close في E0.

## 3) E0 — Subscription + Plan Financial History (منفَّذ)

### الجداول
| جدول | المحتوى |
|---|---|
| `subscription_events` (append-only، بلا `updated_at`) | `subscription_id`, `subscriber_id` (مرجعان تاريخيان بلا FK)، `event_type` (enum ثابت؛ على PostgreSQL قيد CHECK)، `from_status?`, `to_status`, `from_plan_id?`, `to_plan_id?`, **`from_period_start?`, `from_period_end?`, `to_period_start?`, `to_period_end?`** (snapshot حدود فترة الخدمة قبل/بعد الحدث؛ from = NULL للإنشاء والـbaseline، لا تخمين)، `effective_at` (UTC)، `source` (baseline/admin/onboarding/system/gateway)، `actor_ref`, `reason?`, `correlation_id?`, `metadata?`, `baseline_key?` (فريد `sub:<id>`)، `created_at`. فهارس (subscription, effective_at)، (subscriber, effective_at)، type، source. |
| `plan_price_versions` | `plan_id` FK **restrictOnDelete**، `price`, `currency`, `billing_period`, `effective_from`, `effective_until?`, `source` (baseline/admin)، `created_by?`. فهرس جزئي فريد "نسخة مفتوحة واحدة لكل باقة"؛ على PostgreSQL قيود `effective_until > effective_from` و`price >= 0`. |

### الخدمات
- `SubscriptionHistory` (الكاتب الوحيد لـ`subscription_events`): يُستدعى داخل معاملة التغيير بعد القفل والحفظ؛ يكتب الحدث ثم `AuditLogger::record('subscription.transitioned')` — فشل التدقيق يرجع الحالة والحدث معًا. `actor_ref` = `user:<id>` / `console` / `system`.
- `SubscriptionService`: كل mutation (`assignDefaultIfEnabled` بمصدر onboarding، `activateFor` (ينشئ إن لزم؛ from NULL)، `activate`, `suspend`, `cancel`, `extend`) داخل `DB::transaction` مع `lockForUpdate` على صف الاشتراك ثم حفظ + event + audit. `activate` بباقة مختلفة يُنتج `activated` + `plan_changed`. `SubscriberDetail::assignPlan` يمرّ عبر `activateFor` (لا صف عارٍ بعد اليوم).
- **Stale protection للعمليات الإدارية** (مبدأ C3/C4): كل mutation إدارية تحمل `SubscriptionStateToken` (بصمة الحالة/الباقة/الفترة/التجربة/الإلغاء + آخر event id — تتغيّر مع أي انتقال مُلتزَم حتى لو بقيت الإسقاطة كما هي). بعد `FOR UPDATE` تُعاد قراءة الحالة وتُقارن؛ عدم التطابق ⇒ `StaleSubscriptionStateException` قبل أي كتابة (لا حالة، لا event، لا audit). `activateFor` بـ`NONE` يُرفض إن وُجد اشتراك. Onboarding يبقى idempotent بمساره الخاص. الصفحة تلتقط الـtoken عند العرض وتعرض التعارض بدل تنفيذه.
- `PlanPriceBook` (نمط `PriceBook`): `versionFor(plan, at)` (NULL قبل أول نسخة — لا اختراع)، `openVersionFor`، `recordVersion(plan, from, source)`: قفل صف الباقة الأب، رفض أي تداخل أو بداية ≤ بداية النسخة المفتوحة (`PlanPriceOverlapException`)، إغلاق المفتوحة عند `from`، فتح الجديدة بشروط الباقة الحالية، audit `plan.price_versioned` — كله في معاملة المستدعي.
- `Plans::save()`: في نفس معاملة D1 (حفظ + audit) يستدعي `recordVersion(now, admin)` عند الإنشاء أو عند تغيّر price/currency/billing_period فقط؛ فشل النسخة يرجع الحفظ.
- **Stale protection للتعديل المالي**: النموذج يحمل `expected_current_price_version_id` (النسخة المفتوحة عند فتحه؛ NULL إن لم يكن للباقة تاريخ). عند تغيير مالي: قفل صف الباقة → `assertOpenVersionIs()` → عدم التطابق ⇒ `StalePlanPriceVersionException` قبل تعديل الباقة أو إغلاق/فتح أي نسخة أو audit؛ الصفحة تعرض التعارض وتحدّث الـexpected ليعيد المستخدم المحاولة من النسخة الجديدة. نموذج قديم يحمل سعرًا قديمًا يُرفض حتى لو غيّر الاسم فقط (لأنه سيُعيد السعر بصمت). **دقة الحدود microsecond** (`timestamp(6)` على PostgreSQL؛ الموديل يكتب `Y-m-d H:i:s.u` على المحرّكين و`PlanPriceBook` يربط القيم صراحة بهذه الصيغة كي لا يقصّها grammar الاستعلام): الخاسر يعيد المحاولة **فورًا** بعد refresh بلا أي sleep أو تباعد مصطنع، وكل فترة مغلقة `[from, until)` موجبة الطول (قيد PostgreSQL `effective_until > effective_from`).
- `sanad:finance:history-baseline`: dry-run افتراضي يطبع ما سيُلتقط؛ `--apply` يقفل الاشتراكات ثم الباقات بترتيب id في معاملة واحدة، يعيد الفحص تحت القفل، يكتب baseline event (`NULL → الحالة الحالية`, `effective_at = now`, `source = baseline`, `baseline_key`) ونسخة سعر (`effective_from = now`, `source = baseline`) لما ينقصه فقط، وaudit `finance.history_baseline_applied` بالأعداد فقط عند وجود كتابة. تعارض المفتاح الفريد ⇒ rollback ورسالة "captured concurrently". لا `--date`/backdate، لا scheduler.
- Probes للاختبار فقط: `sanad:plan-price-probe`, `sanad:subscription-transition-probe`.

### RBAC
لا صلاحيات جديدة في E0 (الأمر console؛ الصفحات تحت `plans.manage` / `subscribers.manage` القائمتين).

### الاختبارات
- `SubscriptionHistoryTest`: from NULL عند الإسناد الأول، onboarding بمصدر onboarding ولا حدث ثانٍ، سلسلة suspend/activate(+plan_changed)/extend/cancel بـfrom→to متسقة، atomic (فشل التدقيق ⇒ لا تغيير ولا حدث)، append-only (لا `updated_at`).
- `PlanPriceVersionTest`: النسخة الأولى عند الإنشاء وإغلاقها عند التغيير المالي (متجاورة بلا فجوة/تداخل، التاريخ لا يُمسّ)، لا نسخة للتغيير غير المالي، `versionFor` زمني وNULL قبل التاريخ، رفض البداية عند/قبل النسخة المفتوحة أو داخل فترة مغلقة، atomic مع حفظ الباقة، منع حذف باقة لها تاريخ.
- `HistoryBaselineTest`: dry-run لا يكتب، `--apply` يلتقط بوقت الالتقاط (لا `started_at` القديم) من NULL بمصدر baseline، يترك الباقات ذات التاريخ، idempotent بلا audit ثانٍ، الاشتراكات اللاحقة تُلتقط وحدها، رفض `--date`/`--allow-backdate`.
- `PostgresHistoryConcurrencyTest`: 6 تعديلات مالية متزامنة من نفس النسخة المفتوحة ⇒ واحد `versioned` و5 `stale`، النسخة القديمة تُغلق مرة واحدة، نسخة جديدة واحدة، audit واحد، بلا تداخل، والخاسر ينجح لاحقًا من النسخة الجديدة؛ 5 تشغيلات baseline متزامنة ⇒ baseline واحد لكل اشتراك، نسخة واحدة لكل باقة، audit واحد؛ 6 انتقالات إدارية متزامنة من نفس الـtoken ⇒ واحد `ok` و5 `stale`، حدث واحد، audit واحد، الإسقاطة = الفائز، والخاسر ينجح بعد refresh.
- `SubscriptionHistoryTest` أيضًا: رفض token قديم (لا حالة/حدث/audit)، تغيّر الـtoken بعد كل انتقال حتى مع إسقاطة متطابقة، snapshot الفترة في `extend` (old/new period end) مع rollback بلا حدث، `event_type` صريح ومقيّد (enum + قيد CHECK على PostgreSQL).
- `UsageLedgerMigrationTest`: حدّ rollback **15** (13 + 2) — أصبح **20** بعد E1.

### ترتيب النشر لـE0 (بعد الدمج وبموافقة صريحة)
1. `php artisan migrate --force` (الجدولان).
2. `php artisan sanad:finance:history-baseline` (dry-run) → مراجعة → `--apply` **بموافقة منفصلة**؛ هذا التشغيل هو بداية التاريخ المالي الرسمي.

## 4) E1 — Customer Payments, Refunds & Allocation (منفَّذ)

### القرارات المعتمدة (19)
UI إداري أدنى فقط (E5 للكامل) · `current_status`/`latest_event_id` projection يُحدَّث عبر الخدمة + `FOR UPDATE` والتاريخ الرسمي `customer_payment_events` · Cash Collected مبني على الأحداث (`received_at` لدفعة لها `succeeded`؛ الاستردادات بـ`refunded_at`؛ تغيّر الحالة لاحقًا لا يمحو التحصيل) · لا فترة يدوية: كل تخصيص يحمل `subscription_event_id` وsnapshot `period_start/end` من `to_period_*` · الاسترداد/التخصيص لدفعة نجحت فعليًا فقط · `refund_allocations` append-only ولا تعديل لـ`payment_allocations` · `Unallocated = Gross − allocations` و`Net Allocated = allocations − refund_allocations` بلا خلط · لا FX؛ عملة الرسوم = عملة الدفعة؛ `NULL` = `FEES UNKNOWN` · `idempotency_key` إلزامي فريد؛ `(gateway, gateway_payment_ref)` فريد عند وجوده ولا يُخترع · لا `PaymentGateway::recordManual()` — الخدمة `CustomerPaymentService::recordManual()` · لا نص حر (`reference/reason_code/evidence_ref` محدودة) + توسيع `SecretRedactor` (`pan, card_number, cvv, cvc, iban, account_number`؛ `card_brand` يبقى مقروءًا) · قواعد زمنية/عملة · معالجة السباقات 25P02-safe (savepoint) · لا overspend ولا clipping ضمني · جداول E1 immutable · RBAC `finance.payments.manage` = super_admin + finance · 5 migrations (الحدّ 20) · لا CyberSource/webhooks/فواتير/تسوية/FX/إقفال/Revenue Recognition/Gross Profit.

### الجداول
`customer_payments` · `customer_payment_events` (فهرس جزئي فريد: حدث `succeeded` **واحد** لكل دفعة على المحرّكين — لا double-counting) · `customer_refunds` · `payment_allocations` · `refund_allocations` — التفاصيل في [DATABASE.md](DATABASE.md). لا أعمدة نص حر (`external_note`…)، لا `method_hint`، لا PAN/CVV/IBAN/account number. خمسة migrations (`2026_09_06_000901…000905`)؛ حدّ rollback في `UsageLedgerMigrationTest` = **20**.

### الخدمات (`App\Services\Payments`)
- `CustomerPaymentService::recordManual(ManualPaymentInput)`: فحص الصلاحية server-side (`FinanceAuthorization`) → تطبيع القواعد (`MoneyRules`: amount > 0، ISO 4217، `received_at ≤ now` **بصرامة** (backdating لدفعة قديمة حقيقية مسموح؛ المستقبل لا)، عملة الرسوم = عملة الدفعة، رسوم بلا عملة أو العكس مرفوضة، مراجع = **رموز محدودة** بلا مسافات/`@`/سلسلة ≥13 رقمًا كي لا تحمل بريدًا أو PAN أو IBAN) → معاملة خارجية + **savepoint** للإدراج (الدفعة `created` → حدثا `created` و`succeeded` → projection `succeeded` + `latest_event_id` → audit `payment.recorded`). تعارض المفتاح الفريد يرجع الـsavepoint فقط ثم يُقرأ الصاحب ويُقارَن: نفس الحقائق ⇒ الصف نفسه؛ حقائق مختلفة أو نفس المرجع الخارجي بمفتاح آخر ⇒ `PaymentConflictException`. `transition(payment, to, expectedToken, source)`: قفل → `hash_equals(stateToken)` وإلا `StalePaymentStateException` → انتقال مسموح فقط (`created→succeeded|failed`, `succeeded→disputed`, `disputed→dispute_resolved`) → حدث → projection → audit `payment.transitioned` (لا UI له في E1).
- `RefundService::record(RefundInput)`: قفل صف الدفعة → `assertSucceeded` = **شرطان**: تاريخي (حدث `succeeded` موجود — أساس Cash Collected الذي لا يمحوه نزاع لاحق) + تشغيلي (`current_status = succeeded` **الآن**؛ `disputed`/`dispute_resolved`/`failed` تحتفظ بتاريخها لكنها لا تقبل استردادًا أو تخصيصًا جديدًا حتى يعيدها lifecycle واضح) → `refunded_at ≥ received_at` → `Σ الاستردادات + المبلغ ≤ مبلغ الدفعة` بجمع صحيح بالسنتات → savepoint إدراج + audit `payment.refunded`؛ idempotent بنفس القاعدة.
- `AllocationService::allocatePayment(paymentId, subscriptionEventId, amount)`: قفل الدفعة → `assertSucceeded` → الحدث موجود، لنفس المشترك، بفترة صالحة (`to_period_end > to_period_start`) → `Σ ≤ الدفعة` → إدراج مع snapshot الفترة + audit `payment.allocated`. `allocateRefund(refundId, allocationId, amount)`: قفل الدفعة → التخصيص يخص دفعة الاسترداد → نفس العملة → `Σ ≤ الاسترداد` و`Σ refund_allocations على التخصيص عبر **كل** الاستردادات ≤ التخصيص` → audit `refund.allocated`. طوابع `allocated_at/created_at` يولّدها الخادم (لا مدخل زمني للمستدعي).
- `CashCollectedQuery::summarise(from, to)` (≤ 366 يومًا، لكل عملة، جمع صحيح بالسنتات داخل SQL على المحرّكين): `Gross Cash Collected`، `Refunds`، `Net Cash`، `Gateway Fees` المعروفة + عدد المجهولة (أي مجهول ⇒ `Net Cash After Gateway Fees = NULL`)، `Allocated Collected Amount` / `Refund Allocated Amount` / `Net Allocated Amount` (بحسب `period_start`)، `Unallocated Gross Collected Amount` محسوبة لكل دفعة.
- الموديلات: `CustomerPayment` (يرفض تعديل الحقائق والحذف؛ `stateToken() = e:<latest_event_id>`)، `CustomerPaymentEvent` / `CustomerRefund` / `PaymentAllocation` / `RefundAllocation` بـ`ImmutableFinancialRecord` (أي update/delete يرمي `ImmutableFinancialRecordException`).
- Probe للاختبار فقط: `sanad:payment-probe {record|refund|allocate|allocate-refund} …`.

### RBAC والصفحة
`finance.payments.manage` (super_admin + finance؛ operations/support = 403؛ legacy `is_admin` = 403). الصفحة `/dashboard/finance/payments` (`Livewire\Dashboard\Finance\Payments`): الأربع عمليات + ملخّص النقد للنافذة؛ mount وكل action يعيدان الفحص، والخدمات تفحص مرة ثالثة. لا PII (معرّفات داخلية فقط).

### الاختبارات
- `CustomerPaymentTest`: created→succeeded بحدثين وaudit واحد، idempotency (نفس الحقائق ⇒ نفس الصف بلا كتابة؛ مختلفة ⇒ conflict)، فرادة المرجع الخارجي، قواعد المال/الزمن/الرسوم/المراجع، `FEES UNKNOWN`، immutability، atomic audit rollback، transition بـtoken (stale مرفوض، انتقال غير مسموح مرفوض)، قيد CHECK على PostgreSQL.
- `RefundTest`: جزئي حتى الحدّ ثم رفض كامل (لا clipping)، idempotency/conflict، رفض دفعة بلا `succeeded` أو `failed`، قواعد زمنية وسبب إلزامي، append-only + atomic.
- `AllocationTest`: فترة من حدث حقيقي (`extend`) منسوخة، رفض حدث بلا فترة/مقلوب/صفر/لمشترك آخر/غير موجود/دفعة لم تنجح، توزيع على عدة أحداث حتى الحدّ، refund allocation بحدّي الاسترداد والتخصيص ونفس الدفعة، append-only + atomic.
- `CashCollectedQueryTest`: الأرقام الكاملة لنافذة أغسطس (150/40/110، `FEES UNKNOWN`، 60/30/30، unallocated 70)، النزاع لا يمحو التحصيل، net-after-fees عند معرفة كل الرسوم بدقة السنت، نافذة فارغة/غير محدودة، parity على المحرّك الجاري.
- `AttributionMetricsTest`: fixture 100 / 70 / 40 / 20 ⇒ Gross 100، Refunds 40، Net 60، Allocated 70، Refund Allocated 20، Net Allocated 50، Unallocated 30 (الاسترداد لا يغيّر Unallocated)؛ لا كلمة Revenue لأي قيمة.
- `TemporalRulesTest` (ساعة مجمَّدة): `received_at ≤ now` بلا سماحية، backdating مسموح، `refunded_at ∈ [received_at, now]`، المبالغ الأربعة > 0، طوابع التخصيص من الخادم فقط.
- `SensitiveFieldsTest`: لا أعمدة نص حر/بطاقات/بنوك/method_hint على الجداول الخمسة؛ `gateway_payment_ref` nullable وفريد مع `gateway`؛ `idempotency_key` إلزامي؛ المراجع ترفض البريد/PAN/IBAN/الجُمل وتحترم الحدود 64/32/191.
- `PaymentsPageTest`: RBAC (route/nav/mount/action مع سحب الدور أثناء الجلسة)، تسجيل من النموذج + double submit idempotent + `FEES UNKNOWN` + لا PII + `NOT AVAILABLE`، أخطاء المجال كأخطاء نموذج بلا كتابة، refund/allocate/allocate-refund من الصفحة مع الملخّص.
- `SecretRedactorTest`: مفاتيح البطاقات/البنوك تُقنَّع و`card_brand`/`reference`/`company` تبقى.
- `PostgresPaymentConcurrencyTest` (عمليات حقيقية): 6 تسجيلات بنفس المفتاح ⇒ `created` واحد و5 `existing` لنفس الصف، حدثان، audit واحد، حقائق مختلفة ⇒ `conflict`؛ 6 استردادات × 30 على 100 ⇒ 3 `ok` و3 `rejected:refund_limit`، Σ = 90، كل صف 30.00؛ 6 تخصيصات × 30 ⇒ 3/3، Σ = 90؛ refund allocations مقيّدة بالاسترداد (2 من 6) وبالتخصيص (1 من 6) والتخصيص الأصلي لا يتغيّر. CI يشغّله مع حارس "لا skip".

### ترتيب النشر لـE1 (بعد الدمج وبموافقة صريحة)
`php artisan migrate --force` (الجداول الخمسة). لا أوامر أخرى؛ لا backfill.

## 5) E2–E5 (مخطَّطة، لا تبدأ قبل الاعتماد)

- **E2**: `provider_invoices` + `provider_invoice_events` + `provider_invoice_lines?`؛ `cost_reconciliations` لكل مكوّن (provider/communication/external) بمصدر وattestation للصفر؛ `cost_adjustments` append-only؛ RBAC `finance.reconcile`.
- **E3**: `fx_rates` يدوية + إعداد `finance.reporting_currency`؛ `fx_rate_id` في كل تحويل؛ RBAC `finance.fx.manage`.
- **E4**: `finance_period_closes` append-only بشروط الإقفال وإعادة الفتح بسجل جديد؛ مقاييس Cash Collected / Refunds / Gateway Fees / Net Cash After Gateway Fees / Reconciled Service Cost / Reconciled Cash Contribution؛ Gross Profit/Margin `NOT AVAILABLE`؛ RBAC `finance.close_period` (super_admin).
- **E5**: صفحات Payments / Invoices & Reconciliation / FX / Period Close، CSV بعقد `section` + أعلام reconciled، RBAC النهائي.

## 6) مؤجَّل لما بعد E
بوابة دفع حية وشحن فعلي، فوترة العملاء الصادرة، الضرائب، dunning، churn/cohorts/LTV، rollups مادية، تسجيل أحداث WhatsApp في الدفتر، جلب فواتير المزوّدين آليًا، سياسة Revenue Recognition.

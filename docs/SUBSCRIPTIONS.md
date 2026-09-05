# الاشتراكات والباقات ومحرّك الاستخدام | Subscriptions, Plans & Usage — SANAD

أساس الاشتراك والاستخدام في سَنَد: باقات يديرها المشغّل، اشتراك مستقل لكل مشترك،
محرّك استخدام مركزي آمن ضد التزامن، وأساس لحساب التكلفة — كلّه قابل للتوسّع ومفصول
عن نقل واتساب وطبقة الذكاء الاصطناعي.

## الحدود المعمارية (channel-agnostic)
```
WhatsApp transport → MessageProcessor → AgentOrchestrator
                                            │
                          MeteredAgentOrchestrator (decorator)
                                 │            │
                          UsageEngine   AiAgentOrchestrator → AiProvider
                                 │
                    SubscriptionService + UsageCounters + UsageEvents (ledger)
```
- الاشتراك يرتبط بالمشترك (**User**) وليس بحساب القناة، فيعمل نفس المحرّك عبر أي قناة
  مستقبلية (تطبيق، ويب، مكالمات). اليوم: رقم واتساب واحد = مشترك واحد.
- منطق الاشتراك/الاستخدام في خدمات خاصّة به؛ **لا يعتمد منسّق الذكاء على واتساب**.
  الإنفاذ يُضاف عبر **مُزخرِف** (`MeteredAgentOrchestrator`) يلفّ منسّق الذكاء.

## الباقات (Plans) — بيانات لا كود
جدول `plans`: الاسم، `slug`، الوصف، السعر، العملة، دورة الفوترة، أيام التجربة،
`limits` (JSON)، `features` (JSON)، `is_active`، `is_default`، `sort_order`.

### الحدود والميزات مستقلّة (Limits ≠ Features)
الاشتراك **لا يعتمد على عدد الردود فقط**. لكل باقة محورَان مستقلّان:
- **`limits[<dimension>] = { daily, monthly, weight }`** — كمّي/معدود. `null` = غير محدود،
  وغياب البُعد كليًا = غير مُتاح. الأبعاد مُعرَّفة في `App\Enums\UsageDimension`
  (`ai_reply`, `voice_message`, `voice_minute`, `image`, `reminder`, `task`,
  `call_minute`, `tool_action`, …).
- **`features[<feature>] = bool | tier`** — قدرة/تفعيل. الميزات مُعرَّفة في
  `App\Enums\PlanFeature` (`expense_tracking`, `memory`, `advanced_memory`, `tools`,
  `voice`, `images`, `reminders`, `tasks`, `calls`, و`priority` كمستوى مُتدرّج).
  كل ميزة تعرف نوعها (`type()`) وقيمتها الافتراضية (`default()`)، فالميزة الغائبة عن
  الباقة تعود لقيمتها الافتراضية **دون أي backfill للبيانات**.

**لا أسعار ولا حدود ولا ميزات مثبّتة في الكود** — كلها من قاعدة البيانات وتُدار من صفحة
الباقات. الوصول للحدّ عبر `Plan::limitFor(UsageDimension)`؛ للميزة عبر
`Plan::hasFeature(PlanFeature)` / `Plan::featureValue(PlanFeature)`؛ وعلى مستوى المشترك
عبر `SubscriptionService::entitlement()` / `hasFeature()` (يحترمان الباقة الحالية والإنفاذ).

### التوسّع بلا تعديل معماري
- **إضافة بُعد حدّ جديد** = حالة في `UsageDimension` (+ ذكرها في `limits` من لوحة التحكم).
- **إضافة ميزة جديدة** = حالة في `PlanFeature`.
- لا هجرة، ولا تعديل على النماذج/الخدمات، **ولا تعديل على محرّر لوحة التحكم**: فهو
  **مبني على `::cases()`** فيعرض أي بُعد/ميزة جديدة تلقائيًا.

## الاشتراك (Subscription)
جدول `subscriptions` (واحد لكل مشترك — `unique(subscriber_id)`): الباقة، الحالة،
`started_at`، `trial_ends_at`، `current_period_start/end`، `renews_at`، `cancelled_at`.
الحالات: `trialing / active / past_due / expired / cancelled / suspended`. أعمدة
`provider` / `provider_subscription_id` محجوزة لربط بوّابة دفع لاحقًا **دون تغيير المجال**.

### Onboarding / التجربة المجانية
عند أول رسالة من مشترك جديد، يستدعي محوّل واتساب `SubscriptionService::assignDefaultIfEnabled`
(channel-agnostic) فيُسند الباقة الافتراضية (`billing.default_plan_slug`) مرة واحدة:
- قابل للتعطيل عبر `BILLING_AUTO_TRIAL=false`.
- `unique(subscriber_id)` + الإسناد فقط عند غياب اشتراك ⇒ **لا تجربة مجانية مكررة أبدًا**.
- `trial_days > 0` ⇒ `trialing` مع `trial_ends_at`؛ وإلا `active`.

## التسجيل مقابل الإنفاذ (Phase B1) — Recording ≠ Enforcement
```
عملية ناجحة ⇒ UsageRecorder.record()  ──▶ usage_events  (ledger — دائمًا، مالك وحيد)
             ⇒ UsageEngine.charge()    ──▶ usage_charges + usage_counters (حصص — خلف BILLING_ENFORCE فقط)
```
- **`UsageRecorder`** هو **المالك الوحيد** للكتابة إلى `usage_events`: يسجّل ما استهلكه المزوّد وكم كلّفنا،
  **دائمًا** (لا يقرأ `billing.enforce`)، لا يلمس العدّادات، ولا يقرّر السماح/المنع. الإدراج
  `INSERT … ON CONFLICT (idempotency_key) DO NOTHING` ⇒ idempotent وآمن ضد التزامن. التكلفة تُحسب
  **وقت الحدث وتُخزَّن على الصف** (immutable؛ `cost` مرآة لـ`total_cost`). يلتقط **snapshots** بلا FK:
  `subscriber_id` (نسبة التكلفة لصاحبها — يبقى بعد حذف المستخدم بينما `user_id` يُصفَّر؛ معرّف داخلي
  بلا بيانات شخصية)، و`subscription_id`/`plan_id`/`plan_slug` (الباقة وقت الحدث) فيبقى التاريخ صحيحًا
  بعد الترقية أو الحذف. `outcome` يُكتب **صريحًا** لكل صف جديد؛ الصفوف التاريخية تبقى `NULL` (مجهولة).
- **`UsageEngine`** = الإنفاذ فقط: entitlement، حدود، `usage_counters`، وسجلّ استهلاك حصص خاصّ به
  `usage_charges` (idempotent بمفتاح فريد داخل نفس معاملة الزيادة الذرّية). **لا يكتب الـledger أبدًا.**
  عند `BILLING_ENFORCE=false` يعود `NotEnforced` ولا يلمس شيئًا.
- الخطوتان مستقلّتان وكلٌّ منهما idempotent ⇒ إعادة المحاولة تعيد كلتيهما بأمان، و**فشل العدّاد لا
  يمحو تكلفة تكبّدناها فعلًا**.

### المفاتيح: correlation_id ≠ idempotency_key
- `correlation_id` = الطلب المنطقي (`message:{id}` اليوم؛ job/workflow لاحقًا).
- `idempotency_key` = **استدعاء قابل للفوترة واحد** داخله: `{dimension}:{correlation}#{n}` (`UsageKeys`).
  إعادة محاولة نفس الاستدعاء = نفس المفتاح (يُسجَّل مرة)؛ استدعاء جديد فعلي لنفس الرسالة (fallback،
  جولة ثانية بعد أداة، تفريغ صوتي) = `#n` مختلف ⇒ القيد الفريد لا يمنع عدة عمليات شرعية لرسالة واحدة.

### Billable ≠ نجاح العملية للمستخدم
الـledger يسجّل **ما استهلكه المزوّد** (تكلفة حقيقية) مع `outcome` (`succeeded` / `downstream_failed`؛ `NULL` = مجهول للصفوف القديمة):
إن استهلك المزوّد شيئًا ثم فشل الإرسال لاحقًا، تبقى التكلفة مسجّلة. إن لم يستهلك شيئًا (فشل المزوّد)
⇒ لا صف أصلًا. استهلاك الحصّة قد يتبع منطقًا مختلفًا لكل عملية.

### آلية الحدود (UsageEngine) — ذرّية وآمنة ضد التزامن
- `check()` قبل استدعاء المزوّد (سريع، بلا قفل): يمنع استدعاء الذكاء عند تجاوز الحدّ.
- `charge()` بعد النجاح: **upsert ذرّي شرطي** على `usage_counters`
  (`INSERT ... ON CONFLICT (...) DO UPDATE SET used = used + w WHERE used + w <= cap`)،
  فالفحص+الزيادة عبارة واحدة لا يتجاوز بها عامل متزامن الحدّ الصارم — **حتى عند عدم وجود صف
  العدّاد بعد** (سباق أول رسالتين). **idempotent** عبر `usage_charges.idempotency_key` الفريد داخل نفس
  المعاملة ⇒ تكرار الويبهوك أو إعادة المهمة لا يُحاسب مرتين. (لماذا ليس `FOR UPDATE`؟ لأنه لا يقفل
  صفًّا غير موجود؛ انظر ADR-0035.) اختبار تزامن حقيقي على PostgreSQL يثبت الثبات.
- الأبعاد غير المحدودة (`null`) لا تُحجب؛ الأبعاد الغائبة عن الباقة = غير متاحة (disabled).

### قواعد الإنفاذ (نقطة 5)
- قبل استدعاء الذكاء: إن كان المشترك متجاوزًا/غير مؤهّل ⇒ لا يُستدعى الذكاء، وتُرسَل رسالة
  عربية واضحة (طبقة `UsageLimitResponder` المنفصلة، مع بديل رابط ترقية `{upgrade}`).
- بعد النجاح: شحن ذرّي + idempotent؛ إن خُسر السباق عند الحدّ ⇒ تُرسَل رسالة الحدّ.

### الأرصدة الموزونة (نقطة 6 — جاهز لا مفروض)
`weight` لكل بُعد في الباقة (افتراضي 1). لا نفرض نظام أرصدة الآن، لكن كل بُعد قد يستهلك
وزنًا مختلفًا لاحقًا (صورة/دقيقة مكالمة/أداة) دون إعادة تصميم.

## أساس حساب التكلفة (نقطة 7)
يُعاد استخدام جدول `usage_events` كسجلّ (ledger): لكل حدث `quantity`، `input/output_units`،
`cost`، `currency`. الأسعار من `config/billing.cost_rates` (افتراضي **صفر** — لا أسعار
Groq/Meta مثبّتة)، قابلة للضبط عبر البيئة لاحقًا. الأساس لِـ: الإيراد − التكلفة = الهامش.
ليس نظام محاسبة كاملًا في هذه المرحلة.

## لوحة التحكم (Admin)
- **الباقات** (`/dashboard/plans`): إنشاء/تعديل/تفعيل-إيقاف، ضبط الحدود، باقة افتراضية واحدة.
- **المشتركون** (`/dashboard/subscribers`): القائمة مع الباقة والحالة (الهاتف مُقنّع لآخر 4).
- **تفاصيل المشترك** (`/dashboard/subscribers/{id}`): الباقة، الحالة، التجربة، الاستخدام
  (المستهلك/الحدّ/المتبقّي يوميًا وشهريًا)، وإجراءات يدوية: تعيين باقة، تفعيل، إيقاف، تمديد.
- لا تُعرض أي أسرار مزوّدين على أي سطح.

## المتغيّرات البيئية
```env
BILLING_ENFORCE=false        # فعّلها في الإنتاج بعد زرع الباقات
BILLING_AUTO_TRIAL=true
BILLING_DEFAULT_PLAN=free
BILLING_CURRENCY=USD         # عملة أسعار الباقات الافتراضية (الأسعار تُدار من اللوحة)
# BILLING_UPGRADE_URL=       # رابط ترقية (لاحقًا)
BILLING_COST_CURRENCY=USD
COST_AI_REPLY=0  COST_AI_INPUT_PER_1K=0  COST_AI_OUTPUT_PER_1K=0  COST_WA_INBOUND=0  COST_WA_OUTBOUND=0
```

## خطوات التشغيل في الإنتاج
1. `php artisan migrate` (يضيف plans/subscriptions/usage_counters + حقول ledger).
2. ازرع الباقات: `php artisan db:seed --class=Database\\Seeders\\PlanSeeder`
   (⚠️ الأسعار في البذرة تجريبية — عدّلها من صفحة الباقات).
3. راجِع/عدّل الباقات والباقة الافتراضية من لوحة التحكم.
4. فعّل الإنفاذ: `BILLING_ENFORCE=true` (و`BILLING_AUTO_TRIAL` حسب الرغبة)، ثم
   `php artisan config:cache` وأعد تشغيل Horizon (`php artisan horizon:terminate`).
5. (اختياري) اضبط أسعار التكلفة (`COST_*`) عندما تُعرف.

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
- `limits[<dimension>] = { daily, monthly, weight }` — `null` = غير محدود، وغياب البُعد
  = غير مُتاح. **لا أسعار ولا حدود مثبّتة في الكود** — كلها من قاعدة البيانات وتُدار من
  صفحة الباقات في لوحة التحكم.
- إضافة بُعد استخدام جديد = إضافته في `UsageDimension` وذكره في `limits`، دون أي هجرة.

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

## محرّك الاستخدام (UsageEngine) — مركزي وآمن ضد التزامن
- `check()` قبل استدعاء المزوّد (سريع، بلا قفل): يمنع استدعاء الذكاء عند تجاوز الحدّ.
- `charge()` بعد النجاح: **معاملة + `SELECT ... FOR UPDATE`** على صفوف العدّادات
  (`usage_counters`) قبل الفحص والزيادة، فلا يتجاوز عامل متزامن الحدّ الصارم. **idempotent**
  عبر `usage_events.idempotency_key` الفريد (مفتاح = معرّف الرسالة الواردة) ⇒ تكرار الويبهوك
  أو إعادة محاولة المهمة لا يُحاسب مرتين.
- الأبعاد غير المحدودة (`null`) لا تُحجب؛ الأبعاد الغائبة عن الباقة = غير متاحة (disabled).
- المكالمات الفاشلة للذكاء **لا تُحاسب** (المُزخرِف يتحقق من فشل الذكاء قبل الشحن).

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

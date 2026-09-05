# Phase B2 — Provider / Model / Pricing Foundation

> الحالة: **منفَّذة في PR B2** (فرع `feat/b2-provider-model-pricing`). هذه الوثيقة هي الخطة المعتمدة
> مع القرارات التي ثبّتها المالك قبل التنفيذ، ثم ما نُفِّذ فعليًا وأي انحراف عنها ولماذا.
> المرجع المعماري العام: [ARCHITECTURE.md](ARCHITECTURE.md).

## 1) الهدف

نقل **المزوّدين والنماذج والأسعار** من إعدادات ثابتة إلى بيانات تشغيلية في قاعدة البيانات، مع
**تسعير تاريخي** تُحسب منه تكلفة كل حدث استخدام مرة واحدة وقت وقوعه ولا تتغيّر أبدًا، وبدون أي
تغيير في سلوك الإنتاج الحالي (Groq يبقى المزوّد المستخدم، `AI_PROVIDER` يبقى الحاكم).

## 2) القرارات المعتمدة (قبل التنفيذ)

| # | القرار |
|---|---|
| 1 | مرجع السعر على الحدث اسمه **`model_price_id`** (لا `price_id`). |
| 2 | **`AI_PROVIDER` يبقى التفضيل التشغيلي الحاكم** طوال B2. `is_primary` في DB يُخزَّن ويُجهَّز معماريًا ولا يقرؤه الـRouter حتى Phase C (cutover صريح إلى DB-controlled routing). B2 لا تغيّر مزوّد الإنتاج. |
| 3 | الحدث بلا سعر ساري أو بعملة مختلفة = **UNPRICED / UNKNOWN COST** (`cost_source` = `none` أو `currency_mismatch`). أعمدة التكلفة تبقى 0 للتوافق، لكن ذلك **ليس** تكلفة صفرية فعلية، ويجب عدّ هذه الأحداث وعرضها. |
| 4 | نشر السعر يقفل **صف `ai_models` الأب** داخل transaction قبل فحص الفترات وإغلاق القديم وإدراج الجديد (النموذج موجود دائمًا حتى بلا سعر). اختبار تزامن حقيقي على PostgreSQL لنشر أسعار متزامنة لنموذج بلا سعر سابق. |
| 5 | `--allow-backdate` لا يعيد كتابة أو تقسيم تاريخ قائم بصمت: **أي تداخل مع فترة موجودة يُرفض** برسالة واضحة. لا تعديل لأحداث تاريخية؛ التصحيحات المحاسبية في Phase E عبر adjustments. |
| 6 | Bootstrap لا يفرض `gpt-4.1-mini` اختيارًا تجاريًا: يستمد النموذج من config الحالي أو يقبله صراحة، ولا يغيّر provider/model الإنتاج. لا أسعار OpenAI إلا بقيم صريحة تُراجَع بشريًا. |
| 7 | هذه الوثيقة في `docs/PHASE_B2_PLAN.md`، وتحديث `ARCHITECTURE.md` عند الحاجة. |

## 3) المخطّط النهائي (Schema)

### `ai_providers`
| عمود | النوع | ملاحظات |
|---|---|---|
| `key` | string, **unique** | يطابق مفتاح `AiManager` (`openai`, `groq`) |
| `name`, `driver` | string | `driver` = `key` حاليًا |
| `base_url` | string, nullable | مخزّن لـPhase C، **غير مطبَّق** في B2 |
| `credentials_ref` | string, nullable | **اسم متغيّر env فقط** (مثل `OPENAI_API_KEY`) — لا سرّ في DB |
| `capabilities` | json | قائمة `AiOperation` |
| `is_enabled`, `is_primary` | bool | `is_primary` مخزّن ولا يُقرأ في B2 |
| `priority` | int | ترتيب المزوّد |
| `metadata` | json, nullable | |

قيود: `unique(key)`، **partial unique index** على `is_primary WHERE is_primary = true` (أساسي واحد على الأكثر)، `index(is_enabled, priority)`.

### `ai_models`
| عمود | النوع | ملاحظات |
|---|---|---|
| `provider_id` | FK → ai_providers (restrict) | |
| `external_id` | string | المعرّف المُرسَل للمزوّد |
| `name` | string | |
| `aliases` | json | معرّفات يبلّغها المزوّد للنموذج نفسه (مثل `gpt-4.1-mini-2025-04-14`) |
| `capabilities` | json | قائمة `AiOperation` |
| `supports_tools` | bool | metadata |
| `context_window`, `max_output_tokens` | int, nullable | metadata |
| `is_enabled`, `priority` | | |
| `fallback_model_id` | FK self, nullable (nullOnDelete) | قد يعبر المزوّدين |
| `metadata` | json, nullable | |

قيود: `unique(provider_id, external_id)`، `index(is_enabled, priority)`، PostgreSQL: `CHECK (fallback_model_id <> id)`.

### `model_prices` (تاريخي، append-only)
| عمود | النوع | ملاحظات |
|---|---|---|
| `model_id` | FK → ai_models (restrict) | |
| `currency` | char(3) | |
| `unit` | string | `token` (المُسعَّر في B2) / `request` / `minute` / `image` |
| `input_per_million`, `output_per_million` | decimal(14,8) | لكل مليون توكن |
| `cached_input_per_million` | decimal(14,8), nullable | NULL = بسعر المدخلات |
| `per_request` | decimal(14,8) | مبلغ ثابت لكل طلب |
| `effective_from` | timestamp, NOT NULL | **inclusive** |
| `effective_until` | timestamp, nullable | **exclusive**؛ NULL = مفتوح |
| `source` | string | `manual` / `import` / `seed` |
| `note`, `created_by` | | |

قيود: `index(model_id, effective_from)`، **partial unique index** `(model_id) WHERE effective_until IS NULL` (فترة مفتوحة واحدة لكل نموذج)، PostgreSQL: `CHECK (effective_until > effective_from)` و`CHECK` لعدم سلبية المبالغ.

### `usage_events` (إضافات، كلها nullable، الصفوف القديمة تبقى NULL)
| عمود | ملاحظات |
|---|---|
| `ai_model_id` | snapshot بلا FK |
| `model_price_id` | snapshot بلا FK — السعر الذي حُسبت به التكلفة |
| `pricing_snapshot` | json: المعدلات الفعلية المستخدمة + `price_id` + `effective_from/until` |
| `cost_source` | `model_price` / `config_rate` (معروف) — `none` / `currency_mismatch` (**غير معروف**) — NULL للصفوف السابقة لـB2 (**غير معروف**) |

فهارس: `model_price_id`، `(ai_model_id, occurred_at)`، `cost_source`.

## 4) كيف يُختار السعر الساري تاريخيًا

`PriceBook::priceFor(modelId, occurredAt)` يختار الصف الذي `effective_from <= occurredAt` و
(`effective_until IS NULL` أو `effective_until > occurredAt`)، الأحدث بداية أولًا. البداية inclusive
والنهاية exclusive، فلا تقع لحظة في فترتين.

- **زمن المطابقة هو `occurred_at` للحدث** (كما يمرّره المستدعي، وإلا لحظة التسجيل)، لا `now()` ولا `created_at`.
- الـRecorder يخزّن على الصف `model_price_id` + `pricing_snapshot` + المكوّنات المحسوبة. **لا يوجد أي مسار يعيد الحساب.**
- تغيير السعر لاحقًا = إغلاق الفترة المفتوحة عند بداية الجديدة + إدراج فترة جديدة تخصّ الأحداث اللاحقة فقط.
- الضمان الثاني من B1: `insertOrIgnore` على `idempotency_key` يجعل أول تسجيل نهائيًا؛ إعادة المحاولة بعد تغيير السعر لا تكتب شيئًا.
- مثبَت بالاختبارات: `PriceBookTest`، `PricingImmutabilityTest` (تغيير السعر لا يغيّر حدثًا قائمًا، إعادة المحاولة لا تعيد الكتابة، الحدث غير المسعّر يبقى غير مسعّر حتى بعد نشر سعر بأثر رجعي).

## 5) الحساب (`CostCalculator`)

```
(input − cached) × input_rate + cached × cached_rate + output × output_rate
────────────────────────────────────────────────────────────────────────── + per_request
                                1,000,000
```
- الـcached جزء من `prompt_tokens` (دلالة OpenAI) فيُطرح من المدخلات؛ بلا سعر cached يُسعَّر بسعر المدخلات.
- حساب **عددي صحيح ثابت النقطة** (`DecimalMath`) بلا floats وبلا bcmath، وتقريب half-up مرة واحدة إلى 6 منازل (مقياس الـledger).
- أبعاد WhatsApp تبقى على مسار B1 (`communication_cost` من معدلات config، `cost_source = config_rate`).
- لأبعاد AI: سعر DB أولًا؛ وإلا معدل config القديم إن كان > 0 (`config_rate`)؛ وإلا **UNPRICED**.

## 6) Aliases

`ModelResolver::resolve(providerKey, reportedModel, routedModel)`:
1. مطابقة `external_id` تمامًا؛
2. المعرّف المبلَّغ ضمن `aliases` للنموذج؛
3. أخيرًا المعرّف الذي **طلبه** الـRouter (`routed_model` في metadata الردّ → `UsageRecord::$routedModel`).

عمود `model` النصي يبقى كما أبلغه المزوّد؛ `ai_model_id` من الحلّ. النماذج المعطّلة تُحلّ أيضًا (التكلفة تُنسب لمن خدم فعليًا). لا مطابقة = NULL = حدث غير مسعّر، لا تخمين.

## 7) الأحداث غير المسعّرة (Unpriced)

- `cost_source ∈ {none, currency_mismatch}` أو `NULL` (ما قبل B2) = **تكلفة غير معروفة**.
- `UsageEvent::unpriced()` / `priced()` scopes، و`hasKnownCost()`.
- `sanad:ai:catalog` يعرض العدد الإجمالي وتفصيلًا حسب provider/model.
- لا تقرير في المستقبل يجمع أعمدة التكلفة لهذه الصفوف كتكلفة فعلية.

## 8) الكتالوج من DB والانتقال بلا كسر Groq

- `DatabaseCatalogSource`: مزوّدات مفعّلة × نماذج مفعّلة → `ModelSpec` (مع `modelId`/`providerId`/`fallbackProvider` الاختيارية).
- `CatalogSourceResolver` هو المربوط بعقد `CatalogSource`، بحسب `AI_CATALOG_SOURCE`:
  `auto` (افتراضي: DB إن كان فيها نموذج مفعّل وإلا config — سلوك اليوم بالضبط مع جداول فارغة)، `database`، `config` (مفتاح الرجوع الفوري).
- الـRouter يرتّب **بالمفضّل `AI_PROVIDER` أولًا** ثم الأولوية، ويتخطّى المعطّل/المجهول/غير المهيّأ/غير الداعم، ويضع fallback المختار أول البدائل. `is_primary` لا يُقرأ.
- `CatalogCache`: cache قصير (60ث) بمفتاح مُرقَّم يُبطَل عند أي حفظ. **وإن تعذّر مخزن الـcache يُحسب مباشرة** — B2 لا تضيف نمط فشل جديد للـpipeline.

## 9) ما يبقى في config وما انتقل إلى DB

| config/env | DB |
|---|---|
| `AI_ENABLED`، `AI_PROVIDER` (التفضيل الحاكم)، المفاتيح وbase_url الافتراضية، المهل، معاملات التوليد، persona، `AI_FAILURE_BEHAVIOR`، `AI_CATALOG_SOURCE`، `AI_MAX_COST_PER_REQUEST`، معدلات WhatsApp | المزوّدون وتفعيلهم وأولويتهم و`is_primary` (مخزّن)، النماذج وقدراتها وaliases وfallback، الأسعار التاريخية |

`ai.catalog` يبقى بذرة config فقط عند فراغ DB.

## 10) Bootstrap آمن (بلا Seeder أعمى)

- الهجرات لا تُدرج أي صف.
- `sanad:ai:bootstrap`: **dry-run افتراضيًا**؛ `--apply` للكتابة (مع تأكيد في الإنتاج أو `--force`)؛ idempotent؛ لا يعدّل صفوفًا قائمة إلا مع `--update-metadata` (ولا يعيد كتابة الأولوية أبدًا)؛ يستمد النماذج من config الحالي أو من `--model=provider:id`؛ `is_primary=false` دائمًا؛ **لا يكتب أسعارًا أبدًا**.
- `sanad:ai:price`: قيم صريحة فقط، معاينة مع مثال (1000 مدخل + 300 مخرج) لكشف خطأ الوحدة، تأكيد، ثم `PriceBook::publish`. بأثر رجعي يتطلّب `--allow-backdate` ويرفض أي تداخل.
- `sanad:ai:catalog`: تشخيص للقراءة فقط.
- `AiCatalogSeeder` لـlocal/testing فقط (بلا أسعار).

## 11) Cost guardrail foundation

`CostEstimator::estimate(ModelSpec)` من السعر الحالي وحجم طلب نموذجي (`ai.guardrails.estimate_*_tokens`)؛
`RoutingContext::$maxUnitCost` يجعل الـRouter يتخطّى مرشّحًا **تقديره معروف** ويتجاوزه. تقدير غير معروف لا
يُسقط مرشّحًا. الافتراضي معطّل (`AI_MAX_COST_PER_REQUEST` غير مضبوط).

## 12) ترتيب النشر (بلا لمس `.env` الإنتاج ولا `AI_PROVIDER` ولا `BILLING_ENFORCE`)

1. دمج PR B2 بموافقة صريحة؛ الافتراضي `auto` مع جداول فارغة = لا تغيير سلوكي.
2. `php artisan migrate --force` (إضافية).
3. `php artisan sanad:ai:catalog` → المصدر `config`، Groq أولًا، ثم رسالة اختبار.
4. `php artisan sanad:ai:bootstrap` (dry-run) → مراجعة → `--apply` (مزوّدان ونموذجان، بلا أسعار).
5. `sanad:ai:catalog` مجددًا → المصدر `database`، Groq ما زال أولًا، رسالة اختبار ثانية.
6. إدخال الأسعار بقيم تُراجَع عبر `sanad:ai:price` لكل نموذج.
7. التحقق أن حدثًا جديدًا يحمل `model_price_id` وsnapshot، والقديم NULL.
- الرجوع: `AI_CATALOG_SOURCE=config` فورًا؛ rollback الهجرات فقط إن أردت إزالة الجداول.

## 13) الانحرافات عن الخطة الأصلية ولماذا

| البند | الخطة | المنفَّذ | السبب |
|---|---|---|---|
| bcmath | الحساب بـbcmath | `DecimalMath` بأعداد صحيحة | bcmath غير مثبّت في بيئة التطوير وغير مضمون على الإنتاج؛ الأعداد الصحيحة دقيقة وحتمية بلا اعتماد على extension. |
| `base_url` من DB | يُدمج فوق config | مخزّن فقط، غير مطبَّق | التزامًا بالقرار 2: لا شيء في DB يغيّر سلوك المزوّدين في B2؛ يُفعَّل مع cutover في Phase C. |
| مرونة الـcache | غير مذكورة | تعذّر مخزن الـcache ⇒ حساب مباشر | ظهر أثناء التنفيذ: الكتالوج القديم لم يعتمد على أي مخزن، فلا يجوز أن تضيف B2 اعتمادًا على Redis لمسار الرسائل. |
| اختبار الهجرات القائم | — | `UsageLedgerMigrationTest` يرجع 6 خطوات بدل 2 | إضافة 4 هجرات بعد B1 تغيّر معنى `--step 2`؛ الاختبار يؤكد الآن أعمدة B2 أيضًا. |

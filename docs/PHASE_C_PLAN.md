# Phase C — Sanad Admin Control Center

> المرجع المعماري العام: [ARCHITECTURE.md](ARCHITECTURE.md). Phase B2: [PHASE_B2_PLAN.md](PHASE_B2_PLAN.md).
> الحالة: **C0 مدمجة**، **C1 مدمجة**، **C2 منفَّذة** (PR C2). C3–C4 مخطَّطتان ولا تبدآن إلا بموافقة صريحة لكل واحدة.

## 1) Audit للوضع قبل Phase C (main `7c3fd63`)

- الصلاحيات: علم واحد `users.is_admin` + middleware `admin`؛ لا roles ولا permissions.
- اللوحة: Livewire 3 تحت `/dashboard`؛ Plans = نمط CRUD؛ WhatsAppStatus = نمط صحة قرائي (booleans فقط).
- Audit Log: جدول وموديل موجودان بلا أي كاتب في الكود.
- الأسرار: env فقط عبر config؛ لا `app_settings`؛ لا تخزين مشفّر.
- المزوّدون: `AiManager` يقرأ config؛ B2 خزّنت `base_url`/`credentials_ref`/`is_primary` دون تطبيق.
- Persona ومعاملات التوليد تُقرأ من config لحظة بناء الـprompt (قابلة للاستبدال بمصدر DB بلا إعادة تشغيل).
- الصحة: `/api/health` وWhatsAppStatus فقط. لا Usage page.

## 2) القرارات المعتمدة قبل التنفيذ

| # | القرار |
|---|---|
| الأدوار | `super_admin`، `operations`، `finance`، `support` (المصفوفة في §4). |
| الخزنة | `CREDENTIALS_KEY` مستقل عن `APP_KEY` (C3). لا يُضاف للإنتاج قبل C3. |
| التقسيم | C0 RBAC+Audit → C1 Settings+Persona → C2 Providers/Models/Pricing/Routing/Usage → C3 Credentials/Health/Test Connection → C4 Routing cutover. PR مستقل لكل مرحلة. |
| **A. Settings precedence** | لا قاعدة عامة. الـSettings Registry يحدد precedence لكل مفتاح: الإعدادات التشغيلية (persona, prompts, temperature, history limits, provider priorities, model settings) = **DB > config default** — وجود قيمة قديمة في `.env` لا يمنع الـAdmin من التعديل. مفاتيح الطوارئ فقط (`AI_ENABLED`, `AI_ROUTING_MODE`, `AI_CATALOG_SOURCE`, `BILLING_ENFORCE`) = **ENV emergency override > DB > config**، وتُعرض في اللوحة كـ"مُجبَر من البيئة" عند وجود override. |
| **B. base_url / SSRF** | عند الحفظ وعند كل استخدام/اختبار: HTTPS افتراضيًا، host صالح، منع loopback وprivate ranges وlink-local/metadata، منع أي scheme غير http/https، وإعادة التحقق بعد حلّ DNS عند كل outbound (DNS rebinding). مزوّد داخلي على private network = override صريح لـSuper Admin فقط. اختبارات في C2/C3. |
| **C. Provider Health** | Health Check abstraction لكل Adapter يحدد أفضل فحص غير مُفوتَر؛ بلا endpoint آمن لا يُنفَّذ inference دوري، بل فحص configuration/connectivity؛ أي اختبار قد يكلّف = يدوي وواضح في الواجهة. |
| **D. Spatie gate** | تحقق رسمي قبل التثبيت (§3). عدم التوافق ⇒ تقرير خيارات، لا بديل تلقائي. |
| **E. Audit redaction** | Registry صريح للحقول الحساسة (Settings/Credentials/Models) + redaction دفاعي للأسماء والقيم المعروفة. لا سرّ في Audit أو Exceptions أو Logs أو Validation errors أو Livewire payloads. |

## 3) C0 — ما نُفِّذ

**Spatie compatibility gate:** المشروع Laravel 13.30.1 / PHP 8.4.19. `spatie/laravel-permission` **8.3.0** يعلن `illuminate/* ^12.0|^13.0` و`php ^8.3` ⇒ متوافق رسميًا؛ ثُبِّت بقيد `^8.3`.

**RBAC foundation**
- `App\Support\Rbac\Permission` (enum، السجل) و`Role` (enum) و`RoleMatrix` (المصفوفة، مصدر الحقيقة).
- `HasRoles` على `User`؛ `User::canAccessDashboard()` = `is_admin` **أو** صلاحية `dashboard.access`.
- `Gate::before`: **role `super_admin` فقط** يمرّ كل ability (بما فيها Policies المستقبلية). `is_admin=true` وحده لا يتجاوز أي صلاحية صارمة؛ يبقى للتوافق مع صفحات اللوحة القديمة حتى تشغيل bootstrap.
- Middleware: `admin` (الدخول)، `permission.legacy:{perm}` (صفحات ما قبل RBAC: `is_admin` **أو** الصلاحية)، و`permission:` / `role:` / `role_or_permission:` من Spatie (صارمة، بلا bypass).
- المسارات: Plans ← `permission.legacy:plans.manage`، Subscribers ← `permission.legacy:subscribers.view`، Audit ← `permission:audit.view` (**fail-closed**: حساب `is_admin` بلا دور لا يفتحها حتى تُمنح له).

**ترقية الأدمنز الحاليين بلا كتابة بيانات في migration**
- الهجرات تنشئ الجداول فقط. `sanad:rbac:bootstrap` (dry-run افتراضيًا) يعرض الفرق: permissions/roles تُنشأ، صلاحيات تُمنح/تُسحب لمطابقة المصفوفة، permissions مجهولة تُترك، ومستخدمو `is_admin` بلا دور.
- `--apply` يكتب (تأكيد في الإنتاج أو `--force`)؛ `--promote-admins` يمنح `super_admin` **فقط** لمن `is_admin=true` وبلا أي دور؛ لا يمسّ من له دور. Idempotent ومسجَّل في Audit.

**Audit**
- `AuditLogger` (الكاتب الوحيد): `record(action, subject?, changes, context)` و`saveWithAudit(action, model)` الذي يحفظ الموديل ويكتب الـAudit **في transaction واحدة** (savepoint إن كانت مفتوحة): فشل الحفظ أو rollback ⇒ لا صف Audit. من يكتب تغييره بنفسه يستدعي `record()` داخل transaction تغييره (كما يفعل `RbacSynchronizer`).
- Schema إضافي: `actor` (`user`/`console`/`system`)، `actor_ref` (snapshot داخلي غير شخصي: `user:{id}`/`console`/`system` يبقى بعد حذف الحساب بينما `user_id` يصبح NULL)، `ip_address`، `user_agent`، فهارس `(action, created_at)` و`actor_ref`. الجدول append-only (`created_at` فقط).
- `metadata` = `{changes: {field: {from, to}}, context: {...}}` مُقنَّع عند الكتابة.
- صفحة `/dashboard/audit` للقراءة فقط (مرشّحات: action, actor, from, to)، وتمرير ثانٍ للـredactor عند العرض.

**Redaction foundation**
- `SensitiveFieldRegistry` (صريح): مفاتيح معروفة (`password`, `api_key`, `access_token`, `app_secret`, `verify_token`, ...) + سمات لكل Model عبر `HasSensitiveAttributes` (`User`: `password`, `remember_token`) + `registerKeys/registerModel` للمراحل القادمة.
- `SecretRedactor`: الطبقة الصريحة أولًا، ثم دفاعيًا أنماط الأسماء (`*_secret`, `signing_key`, ...) وأشكال القيم (`sk-`, `gsk_`, `EAA…`, `Bearer …`, `AIza…`, PEM). المفتاح الحساس يلوّث كل ما تحته. القناع `[REDACTED:<8 hex sha256>]` يُبقي التغيير قابلًا للاكتشاف بلا كشف القيمة.
- Log tap `App\Logging\RedactSecrets` على قنوات `single`/`daily`/`stderr`: يقنّع context/extra قبل الكتابة.

**ما لا تفعله C0 عمدًا:** لا صفحات Providers/Settings/Credentials، لا `app_settings`، لا خزنة، لا تغيير في `.env` أو `AI_PROVIDER` أو `BILLING_ENFORCE`.

## 4) مصفوفة الأدوار والصلاحيات (النهائية لـC0)

| Permission | super_admin | operations | finance | support |
|---|:-:|:-:|:-:|:-:|
| `dashboard.access` | ✓ | ✓ | ✓ | ✓ |
| `ai.providers.view` | ✓ | ✓ | ✓ | |
| `ai.providers.manage` | ✓ | ✓ | | |
| `ai.models.manage` | ✓ | ✓ | | |
| `ai.pricing.view` | ✓ | | ✓ | |
| `ai.pricing.manage` | ✓ | | ✓ | |
| `ai.routing.manage` | ✓ | ✓ | | |
| `ai.credentials.manage` | ✓ | | | |
| `ai.credentials.test` | ✓ | ✓ | | |
| `settings.manage` | ✓ | ✓ | | |
| `settings.manage_billing` (C1) | ✓ | | | |
| `settings.manage_emergency` (C1) | ✓ | | | |
| `persona.manage` | ✓ | ✓ | | |
| `usage.view` | ✓ | ✓ | ✓ | ✓ |
| `usage.view_costs` | ✓ | | ✓ | |
| `usage.export` (C2) | ✓ | | ✓ | |
| `audit.view` | ✓ | | ✓ | |
| `plans.manage` | ✓ | ✓ | | |
| `subscribers.view` | ✓ | ✓ | ✓ | ✓ |
| `subscribers.manage` | ✓ | | | ✓ |
| `rbac.manage` | ✓ | | | |

`super_admin` يمرّ إضافةً أي ability غير مسجّل عبر `Gate::before`.

## 5) C1 — App Settings + Persona/Prompts (منفَّذة)

**القرارات المثبَّتة قبل التنفيذ:** Cache TTL = 30 ثانية؛ `billing.enforce` للعرض فقط (قيمة فعّالة + مصدر) ولا يُعدَّل من DB/UI؛ `prompts.temporal_context` نُقل من الكود إلى الـRegistry بنفس النص والسلوك؛ **لا `env()` وقت التشغيل** (config فقط، مع تمثيل صريح لوجود override البيئة)؛ صلاحية لكل مفتاح تُفرض server-side؛ إعدادات الفوترة/الاشتراكات والحواجز المالية لـ`super_admin` فقط؛ القوالب بـallowlist صريح بلا Blade/PHP؛ `set()`/`reset()` مع Audit في transaction واحدة؛ القيمة المخزَّنة غير الصالحة ⇒ الافتراضي + تحذير بلا قيم + علامة في اللوحة.

**المخطّط:** جدول `app_settings` (`key` unique، `value` json، `updated_by` FK nullOnDelete، `updated_by_ref`). لا بذور. النوع/الـvalidation/الصلاحية/الـprecedence من الكود فقط (`App\Support\Settings\SettingsRegistry`).

**Config cache وعدم استخدام `env()`:** ملفات config تقرأ `env()` وقت تحميل الإعدادات كالمعتاد. لمفاتيح الطوارئ أُضيف تمثيل صريح للقيمة الخام من البيئة: `config('ai.overrides.enabled')`, `config('ai.overrides.catalog_source')`, `config('billing.overrides.enforce')` — NULL = لا override في البيئة، أي قيمة أخرى = override صريح يتقدّم على DB. القيم المحسوبة (`ai.enabled`...) بقيت كما هي فلا يتغيّر أي سلوك. كل القراءات وقت التشغيل عبر `config()` أو DB، واختبار يفحص أن لا `env(` في `app/`.

**Precedence لكل مفتاح:**

| المفتاح | النوع | المجموعة | Precedence | الصلاحية |
|---|---|---|---|---|
| `ai.persona` | text | persona | DB > config | `persona.manage` |
| `prompts.temporal_context` | template ({timezone}, {now}؛ {now} مطلوب) | persona | DB > config | `persona.manage` |
| `ai.temperature` [0,2]، `ai.max_output_tokens` [1,4096]، `ai.history_limit` [1,50]، `ai.timeout` [5,45] | float/int | generation | DB > config | `settings.manage` |
| `ai.failure_behavior` {retry, reply}، `ai.fallback_message` | enum/text | failure | DB > config | `settings.manage` |
| `billing.limit_reached_message` (template {upgrade})، `billing.feature_disabled_message`، `billing.upgrade_url` (https, nullable) | template/text/string | billing_messages | DB > config | `settings.manage` |
| `billing.auto_trial`، `billing.default_plan_slug` | bool/string | subscriptions | DB > config | `settings.manage_billing` (super_admin) |
| `ai.guardrails.max_cost_per_request` (nullable)، `estimate_input_tokens`، `estimate_output_tokens` | float/int | guardrails | DB > config | `settings.manage_billing` (super_admin) |
| `ai.enabled` | bool | emergency | ENV `AI_ENABLED` > DB > config | `settings.manage_emergency` (super_admin) |
| `ai.catalog_source` | enum {auto, database, config} | emergency | ENV `AI_CATALOG_SOURCE` > DB > config | `settings.manage_emergency` |
| `billing.enforce` | bool | emergency | ENV `BILLING_ENFORCE` > config (**read-only**) | — (عرض فقط) |

**السلوك قبل/بعد:** Persona = النص نفسه من `config('ai.persona')` افتراضيًا؛ سياق الوقت = القالب نفسه بالعناصر `{timezone}` و`{now}` بدل `%s`، بنفس التنسيق `l، j F Y - H:i`؛ معاملات التوليد ورسائل الفوترة والاشتراكات بنفس الافتراضيات. لا تغيير ما لم يُخزَّن شيء في DB. `UsageEngine` ما زال يقرأ `config('billing.enforce')`.

**Cache/الالتقاط بلا restart:** خريطة القيم المخزَّنة في cache مشترك (30 ثانية) بمفتاح مُرقَّم يُرفع عند كل كتابة فيراه كل العمّال فورًا؛ تعذّر الـcache ⇒ قراءة DB؛ غياب الجدول أو تعذّر DB ⇒ الافتراضيات. القراءة عند كل استخدام (PromptBuilder, PersonaContributor, ConversationHistoryContributor, AiAgentOrchestrator, UsageLimitResponder, SubscriptionService, CostEstimator, CatalogSourceResolver, binding الـOrchestrator).

**Audit:** `settings.updated` (subject = صف الإعداد، `changes[key] = {from: القيمة الفعّالة السابقة, to}`, `source_before/after`, `reason`) و`settings.reset` (`from` = المخزَّن، `to` = الافتراضي، `source_after` = default أو env). الصف والـAudit في transaction واحدة؛ فشل الـAudit ⇒ rollback.

**الصفحات:** `/dashboard/settings` (`permission:settings.manage`) و`/dashboard/persona` (`permission:persona.manage`)، صارمتان؛ المحرر يظهر فقط لمن يملك صلاحية المفتاح ويكون قابلًا للتعديل، والـRepository يفرض ذلك مهما فعلت الواجهة.

**النشر:** `migrate --force` ثم `sanad:rbac:bootstrap --apply` (لإنشاء الصلاحيتين الجديدتين `settings.manage_billing` و`settings.manage_emergency` لـsuper_admin). لا تغيير في `.env` ولا `AI_PROVIDER` ولا `BILLING_ENFORCE`.

**ملاحظة مهمة عن مفاتيح الطوارئ في `.env`:** أي مفتاح طوارئ موجود في `.env` (مثل `AI_ENABLED=true` في الإنتاج) هو override يتقدّم على قيمة اللوحة بحكم التصميم، وتعرضه اللوحة "مُجبَر من البيئة". لذلك `.env.example` يترك `AI_CATALOG_SOURCE` معلّقًا، و`phpunit.xml` يفرّغ `AI_ENABLED`/`AI_CATALOG_SOURCE`/`BILLING_ENFORCE` كي تبقى الاختبارات hermetic مهما احتوى `.env` المنسوخ من المثال (سبب فشل CI الأول في PR C1).

## 6) C2 — Providers / Models / Pricing / Routing / Usage (منفَّذة)

**القرارات المثبَّتة قبل التنفيذ (1–9):**

| # | القرار | التنفيذ |
|---|---|---|
| 1 | صفر migrations؛ لا فهرس usage استباقي | لا ملفات migrations في PR C2. |
| 2 | Last viable route | `CatalogAdmin` يشغّل `RoutingSimulator::proposed()` قبل أي تغيير في `is_enabled`/`priority` لمزوّد أو نموذج؛ 0 مرشّح صالح لـ`chat` ⇒ `LastViableRouteException` ولا كتابة. ينطبق على التعطيل **وعلى التفعيل** (في وضع `auto`، تفعيل أول نموذج DB لمزوّد بلا مفتاح يبدّل المصدر إلى كتالوج بلا مسار ⇒ مرفوض). |
| 3 | صلاحية مستقلة `usage.export` | `Permission::UsageExport` تُمنح لـ`super_admin` و`finance` فقط (المصفوفة §4). |
| 4 | Alias uniqueness concurrency | كل كتابة في transaction تقفل صف `ai_providers` الأب بـ`SELECT … FOR UPDATE` ثم تفحص تفرّد `external_id` والـaliases داخل المزوّد (في الاتجاهين: alias جديد ≠ external_id قائم والعكس) ثم تكتب. اختبار PostgreSQL حقيقي بعمليات نظام متزامنة (`sanad:ai-alias-probe`): نفس alias لـexternal_ids مختلفة ⇒ نموذج واحد فقط؛ نفس external_id ⇒ رفض نظيف من الخدمة لا انهيار constraint. |
| 5 | Cache invalidation | الكتابة + Audit في transaction واحدة؛ `CatalogCache::flushAfterCommit()` يؤجّل الـflush عبر `DB::afterCommit` إلى commit المعاملة **الخارجية** فعليًا (لا savepoint داخلي) ويُلغى عند rollback الخارجي — ينطبق على `CatalogAdmin` و`PriceBook` و`sanad:ai:bootstrap` وعلى hooks الموديلات (`saved`/`deleted`)؛ rollback ⇒ لا تغيير ولا Audit ولا invalidation يراه أي worker؛ فشل الـflush بعد commit لا يفشل الكتابة (تحذير `sanad.ai.catalog_cache_unavailable` + TTL 60 ثانية). |
| 6 | Enable/disable عالي الأثر | محاكاة قبل الحفظ بنفس `SanadAiRouter::evaluate()` الذي يستخدمه الإنتاج؛ تغيّر المسار المختار ⇒ `RoutingChangeConfirmationRequired` ويجب إعادة الإرسال مع تأكيد مكتوب = handle المسار **الجديد**؛ الـAudit يحمل before/after + نتيجة المحاكاة (`simulation: {before, after, confirmed, candidates_after}`). لا تغيير على أي Provider/Model في الإنتاج أثناء النشر. |
| 7 | Pricing atomicity | النشر عبر `PriceBook` فقط (UI وCLI)؛ الـAudit `ai.price.published` داخل transaction النشر: فشل الـAudit ⇒ السعر لا يُنشر والفترة المفتوحة لا تُغلق. |
| 8 | Usage export | `GET /dashboard/usage/export` خلف `permission:usage.export` + إعادة فحص في الـController؛ نطاق `from/to` إلزامي (الواجهة تملؤه بـ30 يومًا؛ الحد 92 يومًا)؛ streaming بـ`lazyById(1000)`؛ بلا محتوى رسائل ولا metadata ولا أسماء/إيميلات/هواتف؛ المشترك = المعرّف الداخلي فقط؛ أعمدة التكلفة تُضاف فقط مع `usage.view_costs`. |
| 9 | باقي الخطة | كما يلي. |

**الحدود المحترمة:** لا Credentials ولا API keys (الواجهة تعرض "المفتاح في البيئة: موجود/غير موجود" فقط)، لا Test Connection، لا Provider Health، لا Routing cutover (`AI_PROVIDER` يبقى الحاكم)، `is_primary` للقراءة فقط، `base_url` يُخزَّن بعد فحص `UrlPolicy` ولا يُطبَّق (الـAudit يسجّل `base_url_applied: false`)، `key`/`driver`/`credentials_ref` غير قابلة للتعديل من الواجهة.

**المكوّنات:**
- `SanadAiRouter::evaluate()` — نفس سياسة `route()` مع سبب لكل مرشّح (`disabled`, `unsupported_operation`, `unknown_provider`, `provider_unsupported_operation`, `unconfigured`, `cost_guardrail`) في `RouteEvaluation`؛ `route()` مبني عليه فلا يمكن أن تختلف الواجهة عن الإنتاج.
- `RoutingSimulator` — `current()` (ماذا لو: مزوّد مفضّل/حد تكلفة) و`proposed()` (overrides في الذاكرة لـ`is_enabled`/`priority`) يتبع `ai.catalog_source` بدقة (`config` يتجاهل الصفوف؛ `auto` يعود إلى config حين لا يبقى صف مفعّل).
- `CatalogAdmin` — الكاتب الوحيد لـ`ai_providers`/`ai_models` من الواجهة: صلاحية server-side (`ai.providers.manage`/`ai.models.manage`)، validation، قفل صف المزوّد، تفرّد، `FallbackGraph` (لا self-reference، لا حلقات، عمق ≤ 5)، محاكاة، Audit (`ai.provider.updated`, `ai.model.created/updated/deleted`)، flush بعد commit. حذف النموذج فقط إن كان معطّلًا وبلا أسعار وليس بديلًا لغيره وبلا أحداث استخدام.
- `UrlPolicy` (القرار B): https فقط، host حقيقي، لا userinfo، منع loopback/private/link-local/CGNAT/documentation/multicast/metadata، منع `.local/.internal/.localdomain/.localhost`، IPv4-mapped، وresolver اختياري لإعادة التحقق بعد DNS (يُستخدم في C3 عند كل outbound).
- Usage: `UsageQuery` (نافذة إلزامية محدودة، فلاتر مشتركة بين الصفحة والتصدير، مجاميع: المسعّر يُجمع وغير المسعّر يُعدّ حسب السبب `none`/`currency_mismatch`/`legacy`)، `UsageExporter`، صفحة `/dashboard/usage` (`usage.view`؛ الأعمدة المالية والمجموع فقط مع `usage.view_costs` — يُقرَّر server-side).
- الصفحات: `/dashboard/ai/providers` (`ai.providers.view` للعرض، `ai.providers.manage` للتعديل)، `/dashboard/ai/models` (`ai.models.manage`)، `/dashboard/ai/pricing` (`ai.pricing.view`؛ النشر بمعاينة + تأكيد مع `ai.pricing.manage`)، `/dashboard/ai/routing` (`ai.routing.manage`؛ للقراءة فقط، التقديرات فقط لمن يرى التكاليف).
- `sanad:ai:bootstrap --apply` أصبح مُدقَّقًا (`ai.catalog.bootstrap_applied`) داخل transaction الكتابة.

**النشر لـC2:** لا migrations. `php artisan sanad:rbac:bootstrap` (dry-run) ثم `--apply` لإنشاء `usage.export` ومنحها لـ`super_admin`/`finance`. لا تغيير في `.env` ولا `AI_PROVIDER` ولا `BILLING_ENFORCE`، ولا تغيير على أي Provider/Model أثناء النشر.

## 7) C3–C4 (مخطَّطة)

- **C3:** `provider_credentials` (مشفّر بـ`CredentialVault` على `CREDENTIALS_KEY` + مفاتيح سابقة للتدوير، fail-closed بدون المفتاح)، `CredentialResolver` (الخزنة ثم env)، إدخال write-only عبر POST عادي، masking (fingerprint/last4)، Rotate/Revoke مُدقَّق؛ `ProviderHealthCheck` abstraction لكل Adapter (القرار C) + `provider_health_checks` + Test Connection يدوي مع SSRF re-validation.
- **C4:** `ai.routing.mode` (`env` افتراضي / `db`) مع `AI_ROUTING_MODE` كـemergency override، محاكاة قبل التبديل، تبديل مُدقَّق لـSuper Admin، `is_primary=groq` أولًا ثم التبديل بلا فرق ثم تغيير الأساسي بقرار مقصود.

## 8) ترتيب النشر لـC0

1. دمج PR C0 بموافقة صريحة.
2. `php artisan migrate --force` (جداول Spatie + أعمدة `audit_logs`).
3. `php artisan sanad:rbac:bootstrap` (dry-run) → مراجعة → `--apply` → ثم `--apply --promote-admins` لمنح `super_admin` للحسابات الحالية.
4. `php artisan permission:cache-reset` عند أي شك في cache الصلاحيات بعد النشر.
- حتى الخطوة 3 تبقى كل الصفحات الحالية تعمل بـ`is_admin` كما هي؛ صفحة Audit وحدها تنتظر منح الصلاحية.

# Phase C — Sanad Admin Control Center

> المرجع المعماري العام: [ARCHITECTURE.md](ARCHITECTURE.md). Phase B2: [PHASE_B2_PLAN.md](PHASE_B2_PLAN.md).
> الحالة: **C0 منفَّذة** (هذا الـPR). C1–C4 مخطَّطة ولا تبدأ إلا بموافقة صريحة لكل واحدة.

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
| `persona.manage` | ✓ | ✓ | | |
| `usage.view` | ✓ | ✓ | ✓ | ✓ |
| `usage.view_costs` | ✓ | | ✓ | |
| `audit.view` | ✓ | | ✓ | |
| `plans.manage` | ✓ | ✓ | | |
| `subscribers.view` | ✓ | ✓ | ✓ | ✓ |
| `subscribers.manage` | ✓ | | | ✓ |
| `rbac.manage` | ✓ | | | |

`super_admin` يمرّ إضافةً أي ability غير مسجّل عبر `Gate::before`.

## 5) C1–C4 (مخطَّطة)

- **C1:** `app_settings` + Settings Registry (نوع، افتراضي، validation، مجموعة، صلاحية، **precedence لكل مفتاح** حسب القرار A) + `SettingsRepository` بcache مُرقَّم متسامح مع تعذّر الـcache؛ Persona/Prompts ومعاملات التوليد ورسائل الفوترة من DB.
- **C2:** صفحات Providers/Models/Pricing (نشر فقط عبر `PriceBook`)/Routing (محاكاة)/Usage (المعروف يُجمع، غير المسعّر يُعدّ). SSRF validator لـ`base_url` (القرار B) مع اختبارات.
- **C3:** `provider_credentials` (مشفّر بـ`CredentialVault` على `CREDENTIALS_KEY` + مفاتيح سابقة للتدوير، fail-closed بدون المفتاح)، `CredentialResolver` (الخزنة ثم env)، إدخال write-only عبر POST عادي، masking (fingerprint/last4)، Rotate/Revoke مُدقَّق؛ `ProviderHealthCheck` abstraction لكل Adapter (القرار C) + `provider_health_checks` + Test Connection يدوي مع SSRF re-validation.
- **C4:** `ai.routing.mode` (`env` افتراضي / `db`) مع `AI_ROUTING_MODE` كـemergency override، محاكاة قبل التبديل، تبديل مُدقَّق لـSuper Admin، `is_primary=groq` أولًا ثم التبديل بلا فرق ثم تغيير الأساسي بقرار مقصود.

## 6) ترتيب النشر لـC0

1. دمج PR C0 بموافقة صريحة.
2. `php artisan migrate --force` (جداول Spatie + أعمدة `audit_logs`).
3. `php artisan sanad:rbac:bootstrap` (dry-run) → مراجعة → `--apply` → ثم `--apply --promote-admins` لمنح `super_admin` للحسابات الحالية.
4. `php artisan permission:cache-reset` عند أي شك في cache الصلاحيات بعد النشر.
- حتى الخطوة 3 تبقى كل الصفحات الحالية تعمل بـ`is_admin` كما هي؛ صفحة Audit وحدها تنتظر منح الصلاحية.

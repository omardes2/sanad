# المعمارية | Architecture — SANAD

> **المرجع الرسمي للمشروع.** أي قرار معماري كبير يُحدَّث هنا مع تنفيذه.
> سَنَد هو **Personal AI Agent Platform** — وليس WhatsApp Bot. واتساب هو القناة الأولى فقط.

## 1) المبادئ الحاكمة

1. **كل شيء يرتكز على Subscriber (`user_id`)** — لا على هوية القناة. واتساب/التطبيق/الويب
   Adapters فوق نفس المستخدم والذاكرة والاشتراك.
2. **Data Ownership:** قاعدة بيانات سَنَد + Storage سَنَد هما **Source of Truth**. المزوّد
   الخارجي (OpenAI/Groq/اتصالات/صور…) **Processor فقط**؛ تبديله لا يفقد أي بيانات.
3. **Recording ≠ Enforcement:** تسجيل الاستخدام والتكلفة **يعمل دائمًا** لكل عملية ناجحة.
   الحجب عند تجاوز الحدود فقط خلف `BILLING_ENFORCE` (وهو `false` أثناء التطوير).
4. **الإعداد التشغيلي في قاعدة البيانات:** Providers/Models/Pricing/Routing/Persona/Prompts
   تُدار من Sanad Admin. `config/*.php` = bootstrap/defaults/seed + مراجع أسرار فقط.
   **لا Deploy لتبديل نموذج أو تعديل سعر أو شخصية.**
5. **تجريدات موحّدة:** Provider · Channel · Tool · Notification · Artifact — عقود + Adapters،
   فإضافة أي مزوّد/قناة = Adapter، بلا إعادة تصميم للوحة الإدارة أو القلب.
6. **API-first:** منطق الأعمال في Services لا في Livewire/Controllers/Adapters، فيعيد
   استخدامه تطبيق الموبايل/الويب لاحقًا بلا إعادة بناء.
7. **Sanad Admin = Control Center / Operating System** للمشروع: تشغيل، تكلفة، ربحية، تحكّم.

## 2) المخطّط الطبقي المستهدف

```
Channel Adapter (WhatsApp | Sanad App | Web | Voice)
   → Conversation Layer → Sanad AI Router → AI Providers (OpenAI primary · Groq fallback · …)
                                 ├─ Memory · Tools · Jobs/Workflows · Calls
                                 → Response → Channel Adapter / Notification Layer
   كل عملية ناجحة ⇒ Usage/Cost Event (Subscriber + Subscription + Operation [+ Job/Step/Tool])
   Billing · Usage · Cost Accounting · Finance · Admin ← تقرأ من نفس الأحداث
```

## 3) الحالة الحالية (ما هو مبني)

| المجال | الحالة |
|---|---|
| إطار العمل | Laravel 13 (PHP 8.4)، Livewire 3، Tailwind 4 RTL، PostgreSQL 16، Redis، Horizon، Pest |
| القنوات | `ChannelType` + `ChannelAdapter` + `ChannelRegistry`؛ `WhatsAppChannelAdapter` (webhook موقّع + Graph API)، محاكي ويب محلي. **تجريد نظيف قائم.** |
| المحادثات | `Conversation`/`Message`، `MessageProcessor`، `ProcessInboundMessage` (idempotent، ردّ واحد لكل وارد). |
| الهوية | `User` (subscriber) + `ChannelAccount` (هوية لكل قناة) — مقعد Unified Identity قائم. |
| طبقة الذكاء (Phase A) | عقود قدرات + `OpenAIProvider` + `GroqChatProvider` + `SanadAiRouter` + `CatalogSource` + DTOs محايدة للمزوّد (انظر §4). |
| الاشتراكات | `Plan` (limits/features JSON قابلة للتوسّع عبر `UsageDimension`/`PlanFeature`)، `Subscription`، `SubscriptionService`، محرّر إدارة enum-driven. |
| الاستخدام | `usage_counters` (حدود) + `usage_events` (ledger) — حاليًا **مقترنان بالإنفاذ** (تُفصل في Phase B). |
| المجال | `Task`/`Reminder`/`Expense`/`Memory` نماذج وجداول كاملة (بلا خدمات/أدوات بعد). |
| الإدارة | لوحة Livewire: Overview، Plans، Subscribers، SubscriberDetail، Messages، Conversations، Tasks/Reminders/Expenses، WhatsAppStatus. |

مبادئ ثابتة منذ Sprint 0: UTC داخليًا (`APP_TIMEZONE=UTC`) والعرض بتوقيت المستخدم
(الافتراضي `Asia/Hebron`)، `decimal` للأموال، PHP Backed Enums، cascade للبيانات الشخصية
و`nullOnDelete` للسجلّات المحفوظة. تفاصيل: [DATABASE.md](DATABASE.md)،
[MESSAGE_PIPELINE.md](MESSAGE_PIPELINE.md)، [WHATSAPP_INTEGRATION.md](WHATSAPP_INTEGRATION.md)،
[SUBSCRIPTIONS.md](SUBSCRIPTIONS.md).

## 4) طبقة الذكاء الاصطناعي — Provider abstraction + Router (Phase A)

```
AiAgentOrchestrator ─▶ PromptBuilder (Persona · History · [Memory · Tools لاحقًا])
        │
        ▼
SanadAiRouter.route(AiOperation, RoutingContext) ─▶ CatalogSource.candidates()
        │            (تفضيل → أولوية → مُفعّل → معروف → مهيّأ → يدعم العملية)
        ▼
ResolvedRoute{provider, model, alternatives} ─▶ SupportsChat::chat(AiRequest.withModel())
                                                  OpenAIProvider | GroqChatProvider | …
```

- **`AiProvider`** (الجذر): `name()` · `supports(AiOperation)` · `isConfigured()`.
  **عقود القدرات:** `SupportsChat`، `SupportsTools` (والقادمة: Vision/Transcription/…).
  كل مزوّد ينفّذ ما يقدر عليه فقط؛ الـRouter لا يخمّن.
- **`OpenAICompatibleChatProvider`** قاعدة مشتركة (payload، ترجمة الأدوات، تحويل الأخطاء،
  تحليل الردّ + usage/cached/duration/tool_calls). `OpenAIProvider` (أساسي؛
  `max_completion_tokens` + رؤوس org/project) و`GroqChatProvider` (اختياري/fallback) يرثانها.
- **`SanadAiRouter`**: يختار (provider, model) لكل `AiOperation`. **تبديل النموذج الأساسي =
  بيانات لا كود.** السياسة تتوسّع لاحقًا (plan/cost/profitability/guardrails) عبر
  `RoutingContext` دون تغيير المستدعين.
- **`CatalogSource`**: `ConfigCatalogSource` الآن (يستنتج الكتالوج من `ai.providers` إن كان
  `ai.catalog` فارغًا — فيبقى سلوك النشر الحالي كما هو). مصدر DB يُدار من Admin يحلّ محلّه
  لاحقًا **بنفس العقد**.
- **DTOs داخلية:** `AiOperation`، `AiRequest` (+model/operation/tools)، `AiResponse`
  (+cached/duration/toolCalls/provider)، `AiMessage`/`AiRole` (+tool)، وتجريد الأدوات
  المحايد للمزوّد **`AiToolDefinition` / `AiToolCall` / `ToolResult`** — المزوّد يترجم فقط؛
  التنفيذ للمنصّة (`ToolRunner` لاحقًا).
- **Groq يبقى** مزوّدًا كاملًا؛ الإنتاج يتابع عليه حتى يُضبط `OPENAI_API_KEY` ويُبدَّل
  `AI_PROVIDER` — بلا حذف ولا تبديل قسري.

## 5) DB مقابل Config

| Config فقط (bootstrap/defaults/seed/مراجع أسرار) | Database-backed (تشغيلي، يُدار من Admin) |
|---|---|
| مفتاح التشفير، المزوّد المفضّل للإقلاع (`AI_PROVIDER`)، أعلام تتطلّب Deploy، **بذور** الكتالوج/الأسعار/الشخصية، مهل الشبكة | Providers، Credentials (مشفّرة at-rest، مقنّعة، Rotate/Replace، Audit)، Models، Capabilities، **Pricing تاريخي** (`effective_from/until`)، Routing rules، Primary/Fallback، Enabled/Disabled، Plan routing، **Cost guardrails**، Persona/Prompts/إعدادات AI التشغيلية، Plan limits/features، `app_settings` |

## 6) الطبقات الأساسية — الهدف المعماري

| الطبقة | الملخّص |
|---|---|
| **Identity** | Subscriber واحد لعدة هويات قنوات (`channel_accounts`)؛ مستقبلًا auth للتطبيق + devices. لا حساب منفصل لكل قناة. |
| **Channels** | Adapters فقط. كل صادر عبر `ChannelRegistry`/طبقة الإشعارات — لا نداءات واتساب مباشرة من المنطق. |
| **Conversations** | مرتبطة بـ`user_id` + `channel_account_id`؛ summaries عبر Memory لاحقًا. |
| **AI Providers / Router / Models / Pricing** | §4 + §5. الأسعار DB تاريخية؛ **التكلفة تُحسب وتُخزَّن على الحدث وقت وقوعه (immutable)** مع `price_id`. |
| **Memory** | SoT في سَنَد (`memories`)؛ `UserMemoryContributor` مقعد جاهز؛ embeddings لاحقًا. |
| **Tools** | Simple Tool Actions (create_reminder/create_task/log_expense) عبر Registry/Runner، validation، gating بالميزة، idempotency (`tool_invocations`)، تسجيل التكلفة على النجاح فقط، **تأكيد حتمي** من البيانات المُنشأة. |
| **Jobs / Workflows** | مهام طويلة (`SanadJob/JobRun/JobStep/JobTarget/JobResult/JobArtifact`)، حالات queued→…→cancelled، تقدّم/تكلفة/تقدير، queue-based مع concurrency/throttle/retry/resume/cancel/timeout/duplicate-prevention، تحكّم من Admin وللمستخدم. |
| **Calls / Batch Calls** | Telephony كـProvider ضمن نفس التجريد؛ `calls` بنتائج **منظّمة** (status/duration/outcome/sentiment/summary/next action)؛ **Safety** (consent/lawful-use، DNC، allowed hours، rate limits، country rules، abuse detection، emergency stop). |
| **Artifacts** | `artifacts` مرتبطة بالمستخدم والـJob (DOCX/PDF/XLSX/CSV) عبر filesystem disks (S3-ready)؛ الوصول ليس واتساب-only. |
| **Notifications** | `NotificationService`/`NotificationChannel` محايد؛ أول Adapter واتساب، ثم push/in-app/email توسيعًا لا إعادة بناء. |
| **Billing / Usage / Cost Accounting** | Recording دائم لكل استدعاء قابل للفوترة على `usage_events` (`UsageRecorder` المالك الوحيد: subscriber + snapshot اشتراك/باقة، operation/provider/model، units/cached/duration، مكوّنات التكلفة مخزّنة وقت الحدث، `occurred_at`، `correlation_id` + `idempotency_key`، `outcome`، مراجع job/step/tool/channel **بلا FK مصطنعة**)؛ Enforcement (`UsageEngine`: entitlement/حدود/`usage_counters` + سجلّ حصص `usage_charges`) خلف `BILLING_ENFORCE` ولا يكتب الـledger؛ Cost Guardrails داخلية لكل Plan. |
| **Finance** | Calculated (D) ← ثم Reconciled (E): Collected Revenue، Refunds، Gateway fees، Provider invoices، Adjustments؛ Gross Profit/Margin، MRR/ARR/ARPU/Churn، ربحية لكل مشترك، **drill-down حتى الحدث/الدفعة/الفاتورة**. |
| **Admin** | Providers/Models/Pricing/Routing/Credentials/Usage/Finance/Jobs/Calls/Artifacts/Channel Analytics/Settings/Audit/Roles — تُرسم من الجداول الموحّدة. |

### مصطلحات المال (واضحة في الكود واللوحة)
- **Phase D — تقديري/محاسبي داخلي:** `Calculated Revenue`، `Calculated Cost`، `Estimated Gross Profit`،
  `Estimated Margin` — **ليست ربحية نهائية**.
- **بعد Phase E — فعلي بعد التسوية:** `Collected Revenue`، `Actual Provider Cost`، `Reconciled Cost`،
  `Reconciled Gross Profit`، `Reconciled Margin`.

## 7) ما يُبنى الآن بشكل عام (لمنع إعادة الكتابة)
1. Usage/Cost Event عام بحقول مرجعية محجوزة (`job/step/tool/channel/operation/subscription`).
2. Recording دائم مفصول عن Enforcement.
3. كل نداء AI عبر Provider abstraction + Router + `CatalogSource`.
4. Tool-call DTOs محايدة للمزوّد.
5. Pricing في DB تاريخي + تكلفة مخزّنة على الحدث.
6. كل صادر عبر Channel/Notification abstraction.
7. كل بيانات ترتبط بـ`user_id`؛ Storage عبر filesystem disks.
8. المنطق في Services (API-first).

## 8) خريطة الطريق (PR مستقل لكل مرحلة، دمج بموافقة صريحة)

| Phase | المحتوى | migrations |
|---|---|---|
| **A — AI Platform Foundation** ✅ | Provider abstraction، capability contracts، `OpenAIProvider`، `SanadAiRouter`، `CatalogSource` (config الآن)، DTOs + tool-call abstraction، Groq يبقى | لا |
| **B1 — Metering Foundation** ✅ | **Recording ≠ Enforcement**: `UsageRecorder` مالك وحيد لـ`usage_events` (دائمًا، idempotent، snapshots للاشتراك/الباقة بلا FK، مراجع job/tool بلا FK، `correlation_id` + `idempotency_key`، `outcome`، مكوّنات التكلفة مخزّنة وقت الحدث)؛ `UsageEngine` = إنفاذ فقط بسجلّ `usage_charges` | نعم (إضافية، backward-compatible) |
| **B2 — Provider / Model / Pricing Foundation** | `ai_providers`/`ai_models`/`model_prices` (تاريخي)، `CatalogSource` من DB، `CostCalculator` بأسعار DB، مرجع السعر على الحدث، أساس Cost guardrails | نعم (إضافية) |
| **C — Admin Control Center** | Providers/Models/Pricing/Routing/Credentials(مشفّرة)/Health + Test Connection + Sync jobs + `app_settings` + Persona/Prompts من DB. **يسبقه/يبدأ معه RBAC أساسي** يحمي الأسطح الحسّاسة؛ Credentials لـSuper Admin أو صلاحية صريحة فقط | نعم |
| **D — Financials & Analytics (Calculated)** | Finance Overview، MRR (`subscription_events`)، ربحية المشترك، drill-down، الرئيسية | نعم |
| **E — Reconciliation & Payments (Reconciled)** | `provider_invoices`، `cost_adjustments`، `customer_payments`، مطابقة Calculated vs Actual | نعم |
| **F — RBAC الكامل + Config History** | `spatie/laravel-permission` (Super/Operations/Finance/Support) + Audit لكل تغيير حسّاس | نعم |
| **G — Simple Tools + Reminder Delivery** | Registry/Runner + 3 أدوات + `tool_invocations` + تسليم تذكيرات كامل (scheduler/job/outbound/retries/sent_at/failed/idempotency) عبر Notification abstraction + تأكيد حتمي | نعم |
| **H → N (مستقبلي مُلزِم)** | H Jobs/Workflow Engine · I Calls/Telephony + Safety · J Artifacts/Reports · K Notifications متعدّد · L API-first + Mobile · M Unified Identity + auth · N Payment Gateway | — |

التبعيات: A → B → (C ∥ D) → E → F → G → (H → I → J) ∥ K ∥ (L, M, N).

## 9) القرارات المعتمدة (سجلّ)
- سَنَد = Personal AI Agent Platform؛ واتساب Channel Adapter فقط.
- كل البيانات الأساسية ترتبط بـUser/Subscriber؛ Sanad DB + Storage = Source of Truth؛ Providers = processors.
- Recording دائم لكل عملية ناجحة؛ Enforcement منفصل خلف `BILLING_ENFORCE` (يبقى `false` أثناء التطوير).
- AI Provider abstraction، `OpenAIProvider` أساسي، Groq اختياري/fallback لا يُحذف، `SanadAiRouter`، Tool-calling DTOs محايدة.
- Pricing تاريخي DB-backed؛ تكلفة immutable وقت الحدث؛ Cost accounting لكل Subscriber/Subscription/Operation؛ Cost Guardrails؛ Calculated vs Actual/Reconciled.
- `usage_events` يُهيّأ من Phase B1 بمراجع job/step/tool/channel/operation **بدون FK إلى جداول غير موجودة** (تُضاف العلاقات بهجرة مستقلة لاحقًا)، وبـsnapshots للاشتراك/الباقة بلا FK (التاريخ المالي لا يضيع بحذف Subscription أو تغيير Plan).
- Phase B مقسومة إلى **B1** (Metering Foundation — Recorder مالك وحيد للـledger، Engine إنفاذ فقط بسجلّ `usage_charges` خاصّ) و**B2** (Providers/Models/Pricing). `correlation_id` ≠ `idempotency_key`؛ Billable ≠ نجاح العملية للمستخدم (`outcome`).
- Notification abstraction تبدأ مع أول احتياج حقيقي (Reminder Delivery في G)؛ Phase K توسّعها.
- RBAC: `spatie/laravel-permission`؛ أساس يحمي الأسطح الحسّاسة قبل/مع C؛ التنفيذ الكامل في F.
- Admin Control Center؛ API-first services؛ Channel abstraction؛ Jobs/Calls/Artifacts/Unified Identity معماريات مستقبلية مُلزِمة.
- PR مستقل لكل Phase؛ لا دمج بلا موافقة صريحة؛ لا تعديل `.env` الإنتاج ولا Seeders على الإنتاج ولا اشتراكات المستخدمين من الكود.

سجلّ القرارات التفصيلي (ADR): [DECISIONS.md](DECISIONS.md).

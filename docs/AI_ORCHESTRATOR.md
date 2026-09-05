# مُنسّق الذكاء الاصطناعي | AI Orchestrator — SANAD

طبقة الذكاء الاصطناعي في سَنَد: مزوّد قابل للتبديل خلف عقد موحّد، منسّق يبني السياق
ويتعامل مع الأعطال بأمان، وخطّ تجميع سياق قابل للتوسّع (ذاكرة/أدوات مستقبلًا) — دون
اقتران التطبيق بأي مزوّد بعينه.

## الطبقات (فصل نظيف)

```
WhatsApp transport (Webhook + ProcessWhatsAppWebhook + onboarding)
        │  (لا يتغيّر)
        ▼
MessageProcessor → ProcessInboundMessage → AgentOrchestrator (عقد قائم)
        │
        ▼
AiAgentOrchestrator  ── يبني السياق ──▶ PromptBuilder ──▶ ContextContributor[]
        │                                   (PersonaContributor, ConversationHistoryContributor)
        ▼
SanadAiRouter.route(AiOperation::Chat) ──▶ CatalogSource (config الآن، DB لاحقًا)
        │
        ▼
ResolvedRoute{provider, model} ──▶ SupportsChat::chat() ──▶ OpenAIProvider | GroqChatProvider (HTTP)
```

- **transport ↔ orchestration ↔ routing ↔ providers** مفصولة تمامًا. النقل لا يعرف المزوّد،
  والمنسّق لا يسمّي أي مزوّد (يطلب عملية من الـRouter)، والمزوّد لا يعرف WhatsApp.
- **تبديل النموذج/المزوّد الأساسي = بيانات** (`AI_PROVIDER`/`ai.catalog` الآن، وSanad Admin
  لاحقًا) — لا كود. Groq يبقى مزوّدًا اختياريًا/fallback.
- المنسّق **بديل مباشر** لـ`PlaceholderAgentOrchestrator` عبر نفس عقد
  `App\Contracts\AgentOrchestrator` — لم يتغيّر مسار الرسائل.

## المكوّنات

| المكوّن | المسار |
|---------|--------|
| الإعداد | `config/ai.php` (bootstrap/defaults فقط — الكتالوج يصبح DB لاحقًا) |
| عقود المزوّد | `App\Contracts\Ai\AiProvider` (الجذر: `name/supports/isConfigured`) + قدرات `SupportsChat`، `SupportsTools` |
| القاعدة المشتركة | `App\Providers\Ai\OpenAICompatibleChatProvider` (payload، ترجمة الأدوات، أخطاء، usage) |
| المزوّدون | `App\Providers\Ai\OpenAIProvider` (أساسي) · `App\Providers\Ai\GroqChatProvider` (اختياري/fallback) |
| سجلّ المزوّدين | `App\Services\Ai\AiManager` (`provider()` + `has()` + `extend()`) |
| الـRouter | `App\Services\Ai\SanadAiRouter` (`route(AiOperation, RoutingContext): ResolvedRoute`) |
| مصدر الكتالوج | `App\Contracts\Ai\CatalogSource` ← `App\Services\Ai\Catalog\ConfigCatalogSource` |
| المنسّق | `App\Agents\AiAgentOrchestrator` |
| بناء السياق | `App\Services\Ai\PromptBuilder` + `App\Support\Ai\PromptContext` |
| المساهمون | `App\Support\Ai\Contributors\{Persona,ConversationHistory}Contributor` |
| DTOs | `App\Data\Ai\{AiRole,AiMessage,AiRequest,AiResponse}` · `App\Enums\AiOperation` · كتالوج `App\Data\Ai\Catalog\{ModelSpec,RoutingContext,ResolvedRoute}` |
| أدوات (محايدة للمزوّد) | `App\Data\Ai\{AiToolDefinition,AiToolCall,ToolResult}` — المزوّد يترجم فقط؛ التنفيذ للمنصّة لاحقًا |
| الاستثناءات | `App\Exceptions\Ai\*` (Timeout/RateLimit/Server/Request/Configuration — تشمل `noRoute`) |

## الشخصية واللغة
`PersonaContributor` يضيف شخصية سَنَد (config `ai.persona`): عربية فصحى مبسّطة
افتراضيًا، وتُطابِق لهجة/لغة المستخدم تلقائيًا عندما يكتب بغيرها.

## السياق والخصوصية
`ConversationHistoryContributor` يضيف آخر `AI_HISTORY_LIMIT` رسالة نصّية **من هذه
المحادثة فقط** (مرتّبة من الأقدم للأحدث). الاستعلام مقيّد بمحادثة مشترك واحد، فلا
تتسرّب رسائل مستخدم إلى سياق مستخدم آخر (اختبار انحدار يؤكّد ذلك).

## جاهزية الذاكرة والأدوات (قرار معماري)
سَنَد **ليس** مبنيًّا على تاريخ المحادثة فقط. تجميع السياق يمرّ عبر قائمة
`ai.context_contributors`. إضافة **طبقة ذاكرة المستخدم** طويلة المدى (تفضيلات، لهجة،
روتين، حقائق) أو **أدوات/إجراءات** (تذكيرات، تقويم، حجوزات، إجراءات ويب) مستقبلًا =
إضافة `Contributor` جديد إلى هذه القائمة، **دون إعادة كتابة المنسّق**. أما tool/function-calling
فتجريده جاهز: `AiRequest::$tools` (قائمة `AiToolDefinition`) و`AiResponse::$toolCalls`
(قائمة `AiToolCall`) و`ToolResult::toMessage()` — كلها بشكل سَنَد الداخلي، وكل مزوّد
`SupportsTools` يترجم من/إلى صيغته (OpenAI وGroq معًا عبر القاعدة المشتركة). تنفيذ الأدوات
(`ToolRegistry`/`ToolRunner`) مرحلة لاحقة ولا يعتمد على مزوّد بعينه.

## معالجة الأعطال (لا تعطّل معالجة واتساب، ولا ردود عبثية)
| الحالة | التصنيف | السلوك (failure_behavior=retry) |
|--------|---------|-------------------------------|
| Timeout / اتصال | retryable | يُعاد رميه ⇒ الطابور يعيد المحاولة (backoff) |
| 429 | retryable | يُعاد رميه ⇒ إعادة محاولة |
| 5xx | retryable | يُعاد رميه ⇒ إعادة محاولة |
| 4xx / رد فارغ / إعداد ناقص / لا مسار مهيّأ للعملية | non-retryable | رسالة عربية مؤقتة واضحة (`ai.fallback_message`) |

عند `failure_behavior=reply` تُرسَل الرسالة المؤقتة في كل الأعطال دون إعادة محاولة.
لا تُرسَل أبدًا ردود placeholder عبثية عندما يكون الذكاء مفعّلًا.

## الأمان في السجلّات
لا يُسجَّل أي مفتاح أو نص رسالة. أخطاء المزوّد تُلخَّص عبر `SafeError` (رمز الحالة/سبب
مختصر فقط)، والاستثناءات تحمل رسائل آمنة (بلا body أو مفتاح). السجلّات تحمل معرّفات
وأرقام توكنز فقط.

## المتغيّرات البيئية (بدون قيم)
```env
AI_ENABLED=false
AI_PROVIDER=groq               # المزوّد المفضّل؛ الـRouter يمرّ إلى أي مزوّد مهيّأ آخر
AI_FAILURE_BEHAVIOR=retry      # retry | reply
AI_HISTORY_LIMIT=10
AI_TIMEOUT=20
AI_MAX_OUTPUT_TOKENS=600
AI_TEMPERATURE=0.5
# AI_FALLBACK_MESSAGE=  AI_PERSONA=

OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_API_KEY=                # سرّ — من بيئة الخادم فقط
OPENAI_MODEL=gpt-4.1-mini
# OPENAI_ORGANIZATION=  OPENAI_PROJECT=   (اختياريان)

GROQ_BASE_URL=https://api.groq.com/openai/v1
GROQ_API_KEY=                  # سرّ — من بيئة الخادم فقط
GROQ_MODEL=llama-3.3-70b-versatile
```
> `AI_ENABLED=false` ⇒ يبقى `PlaceholderAgentOrchestrator` (بلا اتصال خارجي) — وهو
> وضع الاختبار/التطوير الافتراضي.

## كيف يختار الـRouter؟
`SanadAiRouter` يطلب مرشّحي العملية من `CatalogSource` — وهو منذ Phase B2 `CatalogSourceResolver`:
بحسب `AI_CATALOG_SOURCE` (`auto` افتراضيًا) يقرأ **كتالوج قاعدة البيانات** (`ai_providers`/`ai_models`)
إن كان فيه نموذج مفعّل، وإلا **كتالوج الإعدادات** (إن كان `ai.catalog` فارغًا يُستنتج من `ai.providers`:
إدخال واحد لكل مزوّد له نموذج). ثم يرتّب: **المزوّد المفضّل `AI_PROVIDER` أولًا** ← الأولوية ← ويتخطّى
أي مرشّح **معطّلًا** أو مزوّده **غير معروف** أو **غير مهيّأ** (بلا مفتاح) أو **لا يدعم العملية** أو
(guardrail اختياري) **تقديره المعروف يتجاوز `maxUnitCost`**. الـfallback المعلن للنموذج المختار يوضع
أول البدائل. لا مرشّح ⇒ `AiConfigurationException::noRoute` ⇒ الرسالة المؤقتة (بلا اتصال).
مثال: `AI_PROVIDER=openai` بلا `OPENAI_API_KEY` وبمفتاح Groq ⇒ يمرّ إلى Groq تلقائيًا.
`ai_providers.is_primary` مخزّن ولا يُقرأ قبل Phase C. أوامر التشخيص والإدارة: `sanad:ai:catalog`،
`sanad:ai:bootstrap` (dry-run افتراضيًا)، `sanad:ai:price` — انظر [PHASE_B2_PLAN.md](PHASE_B2_PLAN.md).

## إضافة مزوّد جديد (Gemini / Ollama / …)
1. أنشئ صنفًا ينفّذ عقود القدرات المناسبة (`SupportsChat` …) — إن كان OpenAI-compatible
   فيكفي أن يرث `OpenAICompatibleChatProvider`.
2. أضف `case` في `AiManager::provider()` وكتلة إعداد في `config/ai.php`.
3. اضبط المفاتيح في البيئة؛ يظهر في كتالوج الإعدادات تلقائيًا (أو أدرجه في `ai.catalog`)،
   وفي كتالوج قاعدة البيانات عبر `sanad:ai:bootstrap --model=provider:model --apply`.
   لاحقًا: يُدار كله من Sanad Admin (Phase C).

> Gemini: ثبّت **نموذجًا حاليًا غير مهمَل** عبر `GEMINI_MODEL` (لا تستخدم نموذجًا قديمًا).
> Ollama: محلي/خاص، بلا مفتاح — الأنسب للخصوصية الكاملة.

## التشغيل في الإنتاج
1. اضبط في البيئة: `AI_ENABLED=true`، ومفتاح المزوّد المفضّل (`OPENAI_API_KEY` أو
   `GROQ_API_KEY`) مع `AI_PROVIDER` المطابق ونموذج حالي. النشر الحالي على Groq يستمر كما هو
   حتى تضبط OpenAI وتبدّل `AI_PROVIDER=openai`.
2. أعد تشغيل Horizon (`php artisan horizon:terminate`) والعمّال ليقرؤوا الإعداد.
3. `AI_TIMEOUT` (20ث) أقل من مهلة العامل (60ث) وأقل من `retry_after` (90ث).

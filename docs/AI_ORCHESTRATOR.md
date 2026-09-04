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
AiManager.provider()  ──▶  AiProvider (عقد)  ──▶  GroqChatProvider (HTTP)
```

- **transport ↔ orchestration ↔ providers** مفصولة تمامًا. النقل لا يعرف المزوّد،
  والمنسّق لا يعرف Groq، والمزوّد لا يعرف WhatsApp.
- المنسّق **بديل مباشر** لـ`PlaceholderAgentOrchestrator` عبر نفس عقد
  `App\Contracts\AgentOrchestrator` — لم يتغيّر مسار الرسائل.

## المكوّنات

| المكوّن | المسار |
|---------|--------|
| الإعداد | `config/ai.php` |
| عقد المزوّد | `App\Contracts\Ai\AiProvider` |
| مزوّد Groq | `App\Providers\Ai\GroqChatProvider` (OpenAI-compatible) |
| مدير المزوّدين | `App\Services\Ai\AiManager` (`provider()` + `extend()`) |
| المنسّق | `App\Agents\AiAgentOrchestrator` |
| بناء السياق | `App\Services\Ai\PromptBuilder` + `App\Support\Ai\PromptContext` |
| المساهمون | `App\Support\Ai\Contributors\{Persona,ConversationHistory}Contributor` |
| DTOs | `App\Data\Ai\{AiRole,AiMessage,AiRequest,AiResponse}` |
| الاستثناءات | `App\Exceptions\Ai\*` (Timeout/RateLimit/Server/Request/Configuration) |

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
إضافة `Contributor` جديد إلى هذه القائمة، **دون إعادة كتابة المنسّق**. كذلك `AiRequest`
هو نقطة التوسّع لدعم tool/function-calling لاحقًا (يُضاف حقل `tools` والمزوّدون
يتجاهلون ما لا يستخدمونه).

## معالجة الأعطال (لا تعطّل معالجة واتساب، ولا ردود عبثية)
| الحالة | التصنيف | السلوك (failure_behavior=retry) |
|--------|---------|-------------------------------|
| Timeout / اتصال | retryable | يُعاد رميه ⇒ الطابور يعيد المحاولة (backoff) |
| 429 | retryable | يُعاد رميه ⇒ إعادة محاولة |
| 5xx | retryable | يُعاد رميه ⇒ إعادة محاولة |
| 4xx / رد فارغ / إعداد ناقص | non-retryable | رسالة عربية مؤقتة واضحة (`ai.fallback_message`) |

عند `failure_behavior=reply` تُرسَل الرسالة المؤقتة في كل الأعطال دون إعادة محاولة.
لا تُرسَل أبدًا ردود placeholder عبثية عندما يكون الذكاء مفعّلًا.

## الأمان في السجلّات
لا يُسجَّل أي مفتاح أو نص رسالة. أخطاء المزوّد تُلخَّص عبر `SafeError` (رمز الحالة/سبب
مختصر فقط)، والاستثناءات تحمل رسائل آمنة (بلا body أو مفتاح). السجلّات تحمل معرّفات
وأرقام توكنز فقط.

## المتغيّرات البيئية (بدون قيم)
```env
AI_ENABLED=false
AI_PROVIDER=groq
AI_FAILURE_BEHAVIOR=retry      # retry | reply
AI_HISTORY_LIMIT=10
AI_TIMEOUT=20
AI_MAX_OUTPUT_TOKENS=600
AI_TEMPERATURE=0.5
# AI_FALLBACK_MESSAGE=  AI_PERSONA=

GROQ_BASE_URL=https://api.groq.com/openai/v1
GROQ_API_KEY=                  # سرّ — من بيئة الخادم فقط
GROQ_MODEL=llama-3.3-70b-versatile
```
> `AI_ENABLED=false` ⇒ يبقى `PlaceholderAgentOrchestrator` (بلا اتصال خارجي) — وهو
> وضع الاختبار/التطوير الافتراضي.

## إضافة مزوّد جديد (Gemini / Ollama)
1. أنشئ صنفًا ينفّذ `AiProvider` (مثل Groq — HTTP OpenAI-compatible).
2. أضف `case` في `AiManager::provider()` وكتلة إعداد في `config/ai.php`.
3. اضبط `AI_PROVIDER` والمفاتيح في البيئة.

> Gemini: ثبّت **نموذجًا حاليًا غير مهمَل** عبر `GEMINI_MODEL` (لا تستخدم نموذجًا قديمًا).
> Ollama: محلي/خاص، بلا مفتاح — الأنسب للخصوصية الكاملة.

## التشغيل في الإنتاج
1. اضبط في البيئة: `AI_ENABLED=true`, `AI_PROVIDER=groq`, `GROQ_API_KEY=<سرّي>`,
   `GROQ_MODEL=<نموذج حالي>`.
2. أعد تشغيل Horizon (`php artisan horizon:terminate`) والعمّال ليقرؤوا الإعداد.
3. `AI_TIMEOUT` (20ث) أقل من مهلة العامل (60ث) وأقل من `retry_after` (90ث).

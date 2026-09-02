# مسار الرسائل | Message Pipeline — SANAD

> ما أُنجز في **Sprint 0C — Message Pipeline & Local Chat Simulator**.
> نظام استقبال ومعالجة الرسائل، مستقل تمامًا عن WhatsApp و OpenAI.

## التدفق

```
Inbound Channel (Web/WhatsApp)
   → Adapter::toInbound()  ── ينتج InboundMessageData
   → MessageProcessor
        • إيجاد المستخدم من channel account
        • إيجاد/إنشاء المحادثة
        • فحص idempotency (external_message_id)
        • حفظ الرسالة الواردة (status = queued)
        • dispatch للـ Job بعد نجاح الـ transaction
   → Queue "messages"
   → ProcessInboundMessage (Job)
        • queued → processing
        • AgentOrchestrator ينتج الرد
        • حفظ الرسالة الصادرة (status = processed)
        • Adapter::send() لإرسال الرد
        • inbound → processed
   → Outbound Channel
```

## المكوّنات

### DTOs (`app/Data`)
- **`InboundMessageData`** — تمثيل موحّد لرسالة واردة: `channel`, `externalMessageId`,
  `externalUserId`, `type`, `text?`, `media?`, `metadata`, `receivedAt`.
- **`OutboundMessageData`** — رد يُرسَل للمستخدم: `channel`, `externalUserId`, `type`, `text?`, `metadata`.
- **`AgentResponseData`** — ناتج الوكيل: `text`, `type`, `metadata`.
- **`ProcessResult`** — نتيجة المعالجة: `outcome` (enum) + `message?`.

كلها `final readonly` بخصائص مُصرَّحة الأنواع (typed) وتستخدم Enums الحالية.

### Contracts (`app/Contracts`)
- **`ChannelAdapter`**: `channel()`, `toInbound(array): InboundMessageData`, `send(OutboundMessageData)`.
- **`AgentOrchestrator`**: `handle(User, Conversation, Message): AgentResponseData`.

### القنوات (`app/Channels`)
- **`ChannelRegistry`** — يختار الـ Adapter المناسب حسب `ChannelType` عبر **Service Container**
  و**DI**. لا توجد شروط `if (whatsapp/web)` متناثرة في `MessageProcessor`.
- **`WebSimulatorChannelAdapter`** — قناة محلية بلا شبكة؛ `send()` لا تفعل شيئًا (الصفحة تقرأ
  الردود من قاعدة البيانات مباشرةً).
- **`WhatsAppChannelAdapter`** — **هيكل فقط**؛ لا يتصل بـ Meta، و`send()`/`toInbound()` يرميان
  `IntegrationDisabledException` واضحة.

### الوكيل (`app/Agents`)
- **`PlaceholderAgentOrchestrator`** — رد حتمي دون OpenAI:
  - `مرحبا` → «أهلًا! أنا سَنَد، مساعدك الشخصي الذكي. كيف بقدر أساعدك؟»
  - غير ذلك → «تم استلام رسالتك: {message}».
  - مربوط كتنفيذ افتراضي لـ `AgentOrchestrator` في `AppServiceProvider`.

### المعالج (`app/Services/MessageProcessor`)
يُرجع `ProcessResult` بواحدة من ثلاث نتائج:
- **accepted** — حُفظت الرسالة وأُرسل الـ Job.
- **duplicate** — `external_message_id` مكرّر؛ لا رسالة/Job جديد.
- **rejected** — مرسِل غير معروف (لا channel account) أو `external_message_id` فارغ.

يعمل داخل transaction، ويُرسل الـ Job **بعد** الـ commit (`afterCommit`)، ولا يسجّل محتوى
الرسائل في السجلّات (معرّفات فقط).

### الـ Job (`app/Jobs/ProcessInboundMessage`)
- `implements ShouldQueue, ShouldBeUnique` — على الطابور **`messages`**.
- `tries = 3`, `backoff = [5, 15, 30]`, `uniqueFor = 300`, `uniqueId = process-inbound-message:{id}`.
- انتقال الحالة: `queued → processing → processed | failed`.
- **إنشاء الرد مرة واحدة فقط** (مضمون بقاعدة البيانات، انظر أدناه)؛ الوكيل يُستدعى عند الإنشاء
  فقط، ويُعاد استخدام الرد نفسه في كل retry.
- **الإرسال خارج أي DB transaction** (اتصال خارجي لا يُحبس داخل معاملة طويلة).
- **inbound يصبح `processed` فقط بعد نجاح الإرسال**؛ عند فشل الإرسال يُعاد رمي الاستثناء
  فتعيد الطوابير المحاولة، مع إعادة استخدام سجل الرد وإعادة محاولة الإرسال ما دام لم يُسلَّم بعد.
- `failed()` (بعد استنفاد المحاولات) يضع الحالة النهائية `failed` مع رسالة خطأ **آمنة**
  (اسم الاستثناء + مقتطف مقصوص) دون تسجيل محتوى الرسالة.

## Idempotency ومنع التكرار

### حاجزان على مستوى قاعدة البيانات
1. **الرسالة الواردة:** قيد **unique** على `messages.external_message_id` يمنع ابتلاع نفس
   الرسالة مرتين؛ المعالج يعالج الـ race بالتقاط `UniqueConstraintViolationException` وإرجاع **duplicate**.
2. **الرد الصادر:** عمود صريح **`messages.in_reply_to_message_id`** (FK ذاتي إلى `messages.id`)
   مع قيد **unique** يضمن **ردًّا صادرًا واحدًا لكل رسالة واردة** — هذا هو الحاجز الأساسي لمنع
   الرد المكرر، **وليس** حقل JSON. علاقتا Eloquent: `Message::inReplyTo()` و`Message::reply()`.

الـ Job ينشئ الرد مرة واحدة؛ وإن سبقه عامل آخر (تزامن) فالـ INSERT الثاني يفشل بقيد الـ unique
فيُلتقط ويُعاد استخدام الرد الموجود. `ShouldBeUnique` طبقة دفاع إضافية لا الأساس.

**النتيجة:** إرسال نفس `external_message_id` مرتين ⇒ رسالة واردة واحدة + Job واحد + **رد واحد**
(مضمون بقيد DB) + نتيجة **duplicate** للمحاولة الثانية — حتى مع عاملين متزامنين أو إعادة محاولة.

### ضمان التسليم الخارجي (مستقبلًا)
منع تكرار **سجل** الرد داخليًا مضمون بقاعدة البيانات. أما **الإرسال الخارجي** فسيكون
**at-least-once**: عند إعادة محاولة الـ Job بعد فشل جزئي قد يُعاد استدعاء `send()` للمزود،
فقد يصل الرد أكثر من مرة **ما لم يدعم المزود idempotency حقيقية** (مثل مفتاح idempotency لكل
رسالة). WhatsApp لا يزال **معطّلًا** في هذا الـ Sprint، وسنعالج idempotency الإرسال عند تفعيل
التكامل الفعلي.

## الأخطاء
- المرسِل غير المعروف/المعرّف الفارغ ⇒ **rejected** (يُسجَّل تحذير بلا محتوى).
- فشل الـ Job بعد كل المحاولات ⇒ `failed()` يضع الحالة `failed` ورسالة خطأ آمنة.
- محاولة إرسال WhatsApp حقيقي ⇒ `IntegrationDisabledException`.

## صفحة `/dev/chat` (محاكي محلي)
- Livewire عربية RTL، متاحة في **local/testing فقط**؛ تعيد **404** في production
  (عبر `EnsureDevEnvironment` middleware + حارس في `mount()`).
- تتيح: اختيار مستخدم تجريبي، إنشاء/استخدام محادثة Web، إرسال نص (مع validation وحد 2000 حرفًا)،
  عرض الرسائل الداخلة/الخارجة، حالة المعالجة، الوقت، وتحديث تلقائي (`wire:poll`) لظهور رد الـ Queue.
- المعالجة الثقيلة **ليست** داخل Livewire — الصفحة تستدعي `MessageProcessor` فقط، والباقي على الـ Queue.

### تشغيلها محليًا
```bash
docker compose up -d                 # PostgreSQL + Redis
php artisan migrate --seed           # بيانات تجريبية
php artisan horizon                  # أو: php artisan queue:work redis --queue=messages
php artisan serve                    # ثم افتح http://localhost:8000/dev/chat
```
اكتب «مرحبا» لرؤية رد الترحيب، أو أي نص لرؤية «تم استلام رسالتك: …» بعد معالجة الـ Queue.

## غير مشمول في Sprint 0C
WhatsApp/Meta أو OpenAI حقيقيًا، Voice، الصور والملفات، أدوات المهام/التذكيرات بالذكاء،
المصادقة، Dashboard، Billing، Deployment.

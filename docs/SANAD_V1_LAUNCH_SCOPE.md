# SANAD V1 LAUNCH SCOPE — BINDING PRODUCT SCOPE

> وثيقة نطاق مُلزِمة. لا تُغيَّر دون موافقة صريحة من صاحب المنتج.

الهدف من الآن ليس إكمال كل رؤية Sanad قبل الإطلاق.

الهدف هو إطلاق نسخة أولية مميزة، قابلة للتشغيل والدفع والبيع بأسرع وقت، بدون التضحية بالأساس الأمني والمعماري الذي بنيناه.

أي Feature خارج هذا النطاق تعتبر **POST-V1** ولا تدخل التطوير قبل الإطلاق إلا بموافقة صريحة.

## Product Positioning

Sanad V1 ليس Chatbot عام.

الرسالة الأساسية:

> **Sanad knows you, remembers, follows up, and helps you act.**
>
> **سند بعرفك، بتذكرك، بتابع معك، وبساعدك تعمل.**

لا نحاول منافسة ChatGPT/Gemini فقط على جودة الإجابة. التميز في V1 يأتي من:

- الذاكرة الشخصية
- المتابعة
- التذكيرات
- المهام
- الصوت
- التخصيص
- المبادرة

## V1 MUST-HAVE FEATURES

### 1. AI Chat

المستخدم يتحدث مع Sanad عبر WhatsApp. يدعم:

- text
- Arabic
- English
- multilingual replies حسب المستخدم

Sanad يبقى channel-independent معماريًا؛ WhatsApp هو قناة الإطلاق فقط.

### 2. Voice Notes

المستخدم يرسل voice note. Sanad:

- يستقبل الصوت
- يحوله إلى نص
- يفهم المقصود
- يرد
- ويمكن لاحقًا ضمن نفس الرسالة إنشاء reminder/task إذا كان الطلب واضحًا

Voice transcription يجب أن تبقى provider-abstracted. لا نربط منطق المنتج بمزوّد واحد.

### 3. Long-Term Personal Memory

Sanad يجب أن يتذكر معلومات ذات فائدة مستقبلية للمستخدم. أمثلة:

- تفضيلات
- عادات
- أشياء طلب المستخدم حفظها
- أسماء/علاقات عند الحاجة ضمن سياسة الخصوصية
- أسلوب الكلام
- أهداف أو روتين متكرر

الذاكرة ليست transcript كامل. يجب التمييز بين:

- conversation history
- durable memory

ولا يتم تحويل كل رسالة إلى memory تلقائيًا. يجب أن يكون هناك:

- relevance
- bounded storage
- ownership
- deletion/update path لاحقًا
- no cross-user memory

### 4. Smart Reminders

المستخدم يكتب أو يقول:

`ذكرني بكرا الساعة 9 أتصل على البنك`

أو:

`كل أول شهر ذكرني أدفع الإيجار`

Sanad ينشئ reminder فعلية. يجب دعم:

- one-time
- recurring
- exact time
- day/date
- timezone-aware scheduling

reminders يجب أن ترجع للمستخدم عبر نفس channel الذي بدأ منها.

### 5. Tasks

المستخدم يستطيع أن يقول:

`حط عندي مهمة أجهز العرض قبل الخميس`

ويقدر يسأل:

- شو علي اليوم؟
- شو المهام المتأخرة؟
- خلصت المهمة X

V1 لا تحتاج project-management system معقد. المطلوب:

- create
- list
- mark done
- due date
- status

فقط.

### 6. Morning Brief

Sanad يرسل ملخص صباحي شخصي للمستخدم. المحتوى قد يشمل:

- مهام اليوم
- reminders
- overdue items
- follow-ups

لا news feed عام في V1. لا محتوى غير متعلق بالمستخدم فقط لملء الرسالة.

يجب أن يكون:

- optional
- user-configurable
- timezone-aware

### 7. Follow-Up Until Done

بعض reminders/tasks يمكن أن يكون لها `follow_up = true`.

مثال — Sanad: `دفعت الفاتورة؟`

إذا لم يؤكد المستخدم الإتمام: يمكن إعادة المتابعة ضمن سياسة bounded.

Requirements:

- no spam loop
- max follow-ups
- minimum interval
- user can stop
- completed item stops follow-up

هذه ميزة V1 مهمة لأنها تجعل Sanad أكثر من reminder app.

### 8. Language / Dialect Adaptation

Sanad يتكيف تلقائيًا مع:

- لغة المستخدم
- أسلوب الكلام
- اللهجة عندما تكون الثقة عالية

لا نعمل onboarding يسأل: `ما هي لهجتك؟`

inference يمكن أن يعتمد على:

- current messages
- historical language preference
- country code كإشارة ضعيفة فقط

country code لا يكون authority مطلقًا. المستخدم يستطيع لاحقًا تصحيح اللغة/اللهجة.

### 9. Subscription + Bank Payment

V1 يجب أن تكون قابلة للبيع. المطلوب:

- plans
- trial/free allowance
- subscription
- payment
- renewal
- usage tracking
- entitlement
- billing enforcement

Payment gateway target: **CyberSource / bank integration**

يجب عدم إعادة بناء الـfinance foundation. استخدم E0–E5 الموجود.

Payment flow:

`Checkout` → `CyberSource` → server-side verification / webhook → CustomerPayment succeeded → subscription activation / renewal

لا تعتمد على redirect browser كدليل نجاح الدفع. webhook/server verification هو authority. لا card data داخل Sanad.

### 10. "Sanad Knows You" Experience

هذا Product Experience وليس table واحدة.

Sanad يجب أن يبدأ بسيطًا ويتعرف على المستخدم تدريجيًا. لا onboarding طويل. لا 20 سؤال عند التسجيل.

أمثلة تجربة مرغوبة:

- يتذكر معلومة مفيدة ذكرها المستخدم
- يستخدمها لاحقًا في سياق مناسب
- يذكر المستخدم بشيء مرتبط بروتينه
- يقترح reminder عندما يكون ذلك منطقيًا

لكن: لا يكون intrusive. لا يتظاهر بمعرفة معلومة غير محفوظة. لا يخترع memories.

## V1 DIFFERENTIATORS

عند اتخاذ قرار هل Feature تدخل V1 أم لا، أعطِ الأولوية لما يدعم هذه الأربعة:

1. **Remember**
2. **Remind**
3. **Follow up**
4. **Act**

إذا Feature لا تدعم واحدًا منها بشكل مباشر ولا يحتاجها الدفع/الأمان/التشغيل: غالبًا POST-V1.

## POST-V1 — DO NOT BUILD BEFORE LAUNCH

لا تدخل قبل الإطلاق:

- phone calls to restaurants/hotels
- voice calling
- hotel/restaurant booking
- purchases on behalf of user
- card spending by Sanad
- bank transfers
- travel booking
- browser automation
- dozens of third-party integrations
- full mobile app
- desktop app
- complex workflow builder
- multi-agent orchestration
- autonomous agents running for hours
- complex document generation
- enterprise collaboration
- marketplace
- global multi-region scaling unless required by actual load

حافظ على architecture قابلة لهذه الأشياء مستقبلًا، لكن لا تنفذها الآن.

## DEVELOPMENT PRIORITY FROM CURRENT STATE

الترتيب بعد F2:

**A. Finish F2** — Invocation lifecycle + read-only tool foundation.

**B. Minimal Write Tool Layer** — بدل بناء F3 ضخمة، نفّذ فقط ما تحتاجه V1:

- `task.create`
- `task.complete`
- `reminder.create`
- `reminder.cancel`
- memory-safe write/update operations الضرورية

لا external_write أو irreversible actions في V1 إلا إذا كانت ضرورية للـpayment flow، والدفع يبقى في Payment subsystem وليس Tool executor العام.

**C. Provider Tool Calling** — اربط AI provider بالـToolRegistry بعد أن execution foundation تصبح مستقرة. Provider يستطيع اقتراح tool call، لكنه لا يختار:

- user identity
- permission
- consent
- idempotency key
- execution authority

Sanad server هو authority.

**D. Voice** — transcription integration + WhatsApp voice handling.

**E. Memory** — durable personal memory + retrieval + safe memory writes.

**F. Reminders / Tasks / Follow-Up** — execution + scheduler + outbound notifications.

**G. Morning Brief** — recurring personalized delivery.

**H. Payment Launch** — CyberSource adapter/webhook + plan activation/renewal + billing enforcement.

**I. Launch Hardening**

- production deployment checklist
- monitoring
- error handling
- rate limits
- privacy
- backup
- first-user support tools

## V1 LAUNCH GATE

لا نعتبر V1 جاهزة إلا إذا:

1. مستخدم جديد يقدر يبدأ على WhatsApp.
2. يرسل text ويحصل على AI response.
3. يرسل voice note ويحصل على فهم/رد.
4. Sanad يتذكر معلومة مناسبة ويرجع يستخدمها لاحقًا.
5. المستخدم ينشئ reminder بالكلام الطبيعي.
6. reminder تصل في الوقت الصحيح.
7. المستخدم ينشئ task ويسأل عن مهامه ويغلقها.
8. follow-up يعمل بدون spam.
9. morning brief يصل حسب timezone للمستخدم المفعّل لها.
10. اللغة/الأسلوب يتكيفان مع المستخدم.
11. trial/plan limits تعمل.
12. المستخدم يدفع فعليًا عبر بوابة البنك.
13. webhook موثوق يفعّل/يجدد الاشتراك.
14. المستخدم الذي انتهت صلاحياته يُطبق عليه billing policy الصحيحة.
15. admin يرى payment/subscription/usage من النظام الحالي.
16. لا يوجد cross-user memory/task/reminder access.
17. retries لا تنشئ duplicate tool actions.
18. كل production-critical tests خضراء على PostgreSQL.

## Delivery Goal

الهدف: **Sanad V1 Beta — sellable and distinctive**

وليس: `Complete Sanad Vision`

بعد الإطلاق، نستخدم:

- real users
- payment conversion
- usage data
- retention
- support requests

لتحديد ترتيب Post-V1.

## V1 Launch-Gate Backlog (بنود مُلزِمة قبل الإطلاق العام)

بنود ظهرت أثناء التنفيذ وتقرَّر تأجيلها إلى مرحلتها المناسبة — **ليست POST-V1**، ولا يجتاز V1 Launch Gate بدونها:

| البند | لماذا | المرحلة |
|---|---|---|
| **Recurring Reminder Model + Scheduling** | نطاق V1 يشترط تذكيرات متكرِّرة، ولا يوجد أي تمثيل للتكرار في المستودع. F3-V1 نفّذت **المرة الواحدة فقط** ولم تخترع migration للتكرار؛ `reminder.create@2` ليس دليلًا على اكتمال هذا المتطلب. | مرحلة التذكيرات/المُجدوِل |
| **`WHATSAPP_REMINDER_TEMPLATE_READY` — قالب واتساب معتمَد للتذكيرات** | التذكير رسالة **استباقية** بطبيعتها وقد تحين بعد إغلاق نافذة خدمة العملاء، وعندها الآلية المسموح بها هي قالب معتمَد. سياسة التسليم تقرِّر ذلك **قبل** الإرسال ولا تراهن برسالة حرّة: بلا قالب مُعتمَد ومضبوط تفشل مغلقة بسبب `template_required`. **لا يجوز أن تدّعي V1 العامة تسليم تذكيرات موثوقًا قبل اعتماد القالب وضبطه فعليًا** — وهي تبعية خارجية كـتفاصيل البنك. | خارجي: اعتماد قالب Meta |
| **Rate limiting / abuse protection** | `ToolDefinition.rateLimitPerHour` بيانات وصفية معلَنة و**غير مفروضة**؛ لا يجوز تقديمها على أنها مفروضة حتى يوجد تطبيق فعلي. | مرحلة Launch Hardening |
| **`MEMORY_KEY` + `MEMORY_FINGERPRINT_KEY` — مفتاحا الذاكرة الدائمة** | الذاكرة **فاشلة مغلقة** بلا مفتاحين مُهيَّأين: لا تُحفظ ذاكرة ولا تُقرأ ولا تصل الـprompt، لأن التخزين بالنص العادي والبصمة غير المفتاحية ليسا بديلين مقبولين فلا مسار لهما في الكود. المفتاحان **مستقلان عن APP_KEY وعن بعضهما** (base64 لـ32 بايت). **لا يجوز أن تدّعي V1 العامة أن سند «يعرف المشترك» قبل ضبطهما فعليًا في بيئة الإنتاج** — وضبطهما التزام تشغيلي دائم: فقدان `MEMORY_KEY` بلا `MEMORY_PREVIOUS_KEYS` يجعل ذاكرات المشتركين غير قابلة للقراءة نهائيًا. | تشغيلي: ضبط أسرار الإنتاج |
| **Implicit memory extraction** | V1 تحفظ **بطلب صريح فقط**. الاستخراج الضمني يحتاج عتبة ثقة، والعتبة بلا مجموعة معايرة حقيقية رقم مخترَع؛ وحفظ صامت لمعلومة شخصية لم يطلبها المشترك خطؤه أغلى بكثير من ذاكرة فائتة. العمود `provenance` و`MemoryProvenance::Inferred` موجودان بلا كاتب حتى تُعتمد مرحلته. | مرحلة لاحقة (بعد تراكم ذاكرات حقيقية) |
| **Semantic memory retrieval (pgvector/embeddings)** | الاسترجاع في V1 ترتيب حتمي (الأهمية ثم الأحدث تأكيدًا) داخل سقف 50 ذاكرة، لا بحث تشابه. ADR-0008 ما زال قائمًا، واختراع مقياس صلة قبل الـembeddings اختراع رقم. | مرحلة محرك الذاكرة |

## Scope Control

من الآن: إذا أثناء أي Phase ظهر feature جديد خارج هذا الملف، لا تنفذه تلقائيًا. أضفه إلى **POST-V1 BACKLOG** وأكمل Launch Scope.

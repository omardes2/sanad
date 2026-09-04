# سجل القرارات المعمارية | Decision Log (ADR) — SANAD

> قرارات مختصرة تُوثّق "لماذا" اخترنا ما اخترنا. الأحدث في الأعلى.

## ADR-0029 — صفحة حالة واتساب تعرض القدرات فقط (بلا أسرار)
- **القرار:** صفحة الحالة تعرض booleans مشتقّة من `WhatsAppConfig` (canSend،
  canValidateSignature، canVerifyWebhook، وجود المعرّفات) وحالة Horizon/Redis
  وأحجام الطوابير فقط؛ لا تقرأ أو تعرض أي قيمة سرّية إطلاقًا.
- **السبب:** المشغّل يحتاج معرفة "هل الإعداد مكتمل؟" دون كشف الرموز؛ اختبار
  انحدار يؤكّد عدم ظهور أي قيمة token/secret/id.

## ADR-0028 — لوحة تحكم المشغّل عبر Livewire (بلا SPA منفصل)
- **القرار:** لوحة التحكم صفحات Livewire كاملة الصفحة بتخطيط RTL متجاوب،
  تعيد استخدام النماذج والجداول القائمة (بلا بنية مكرّرة) وعرض للقراءة فقط.
- **السبب:** اتساقًا مع ADR-0003 (Livewire + Tailwind + RTL) وسرعة التسليم.

## ADR-0027 — مصادقة بلا تسجيل عام + أول مدير عبر Artisan
- **القرار:** لا مسار تسجيل عام؛ الحسابات تُنشأ فقط عبر أمر
  `sanad:make-admin` بإدخال كلمة مرور مخفية. صلاحية اللوحة عبر عمود
  `is_admin` على جدول `users` القائم + middleware `admin` (بعد `auth`).
  تسجيل الدخول عبر Livewire مع throttling وتجديد الجلسة.
- **السبب:** أداة داخلية للمشغّل؛ منع إنشاء الحسابات الذاتي يقلّل سطح الهجوم،
  وكلمة المرور لا تُمرَّر كوسيطة أمر (تسرّب في سجل الصدفة).

## ADR-0019 — عزل القنوات خلف `ChannelAdapter` + `ChannelRegistry`
- **القرار:** كل قناة (Web/WhatsApp) تُنفّذ `ChannelAdapter`، ويُختار الـ Adapter عبر
  `ChannelRegistry` باستخدام الحاوية و DI.
- **السبب:** منع شروط `if (whatsapp/web)` المتناثرة؛ إضافة قناة جديدة = Adapter + تسجيل واحد.

## ADR-0018 — `AgentOrchestrator` كعقد + Placeholder حتميّ
- **القرار:** الوكيل خلف واجهة `AgentOrchestrator`، بتنفيذ `PlaceholderAgentOrchestrator` الآن.
- **السبب:** بناء المسار كاملًا واختباره حتميًّا دون OpenAI؛ يُستبدل التنفيذ لاحقًا دون تغيير المتصلين.

## ADR-0017 — معالجة الرسائل على طابور Redis (`messages`) عبر Job
- **القرار:** المعالجة الثقيلة في `ProcessInboundMessage` (Job) لا في Livewire/Controller؛
  طابور `messages`، `tries=3`, `backoff=[5,15,30]`, `ShouldBeUnique`.
- **السبب:** استجابة سريعة، إعادة محاولة آمنة، وقابلية توسّع أفقي عبر Horizon.

## ADR-0026 — إعداد واتساب مركزي في `config/whatsapp.php` مع fail-closed
- **القرار:** كل إعدادات واتساب في ملف واحد + `WhatsAppConfig` (بدل `config/services.php`).
  عند التفعيل مع إعدادات ناقصة يفشل التكامل بأمان (403/استثناء) بدل العمل بمفتاح فارغ.
- **السبب:** مصدر واحد للحقيقة، وأسرار لا تُسرّب، وسلوك آمن افتراضيًا.

## ADR-0025 — Webhook بلا CSRF عبر التوقيع، على طابور `webhooks`
- **القرار:** مسارات `/webhooks/whatsapp` تُسجَّل بلا middleware group (لا CSRF/session)؛
  الهوية تُثبَت بالـ verify token (GET) وتوقيع HMAC-SHA256 على **raw body** (POST). المعالجة
  الثقيلة تُؤجَّل إلى طابور `webhooks` (`ProcessWhatsAppWebhook`)، والرد HTTP سريع.
- **السبب:** الـWebhooks آلات لا متصفّحات؛ CSRF غير منطبق، والتوقيع هو الحاجز الأمني.

## ADR-0024 — SHA-256 للغلاف كحاجز idempotency ثانٍ
- **القرار:** `webhook_events.external_event_id = SHA-256(raw payload)` + unique(provider, id).
  يبقى `messages.external_message_id` (wamid) الحاجز الأساسي لتكرار رسائل Meta.
- **السبب:** يمنع تكرار معالجة الغلاف كاملًا عند إعادة تسليم Meta، دون كسر idempotency الرسائل.

## ADR-0023 — فصل حالة التسليم عن حالة المعالجة الداخلية
- **القرار:** أعمدة تسليم منفصلة (`provider_message_id` unique، `delivery_status`،
  `sent_at/delivered_at/read_at`، `delivery_error_code`) مع enum `MessageDeliveryStatus`
  وانتقالات monotonic؛ **دون** إعادة استخدام `external_message_id` لمعنيين.
- **السبب:** `processing_status` تصف pipeline سَنَد، بينما التسليم تحكمه status webhooks؛ خلطهما
  يكسر idempotency ومعنى الحالة.

## ADR-0022 — `ChannelAdapter::send()` يعيد `ChannelDeliveryResult`
- **القرار:** تغيير عقد `send()` من `void` إلى `ChannelDeliveryResult` (حالة + provider id).
  عُدِّل Web Simulator والـJob والاختبارات وفقًا لذلك.
- **السبب:** الـJob يحتاج provider message id وحالة التسليم لتخزينها ومتابعة status webhooks.

## ADR-0021 — التسليم الخارجي at-least-once حتى دعم المزوّد للـ idempotency
- **القرار:** الإرسال عبر `ChannelAdapter::send()` يتم **خارج** أي DB transaction، وبعد نجاحه
  فقط يُعتبر الوارد `processed`؛ فشل الإرسال يُعاد رميه لتعمل الطوابير مع إعادة استخدام سجل الرد.
- **الأثر:** سجل الرد **واحد** مضمون داخليًا (قيد DB)، لكن الاستدعاء الخارجي قد يتكرر عند retry
  ⇒ **at-least-once** ما لم يوفّر المزوّد idempotency (مفتاح لكل رسالة). يُعالَج عند تفعيل WhatsApp.

## ADR-0020 — منع الرد المكرر بقيد قاعدة بيانات (لا JSON)
- **القرار:** عمود صريح `messages.in_reply_to_message_id` (FK ذاتي) مع **unique**، يضمن ردًّا
  صادرًا واحدًا لكل رسالة واردة على مستوى القاعدة. الـ Job ينشئ الرد مرة واحدة ويلتقط تعارض
  الـ unique ليعيد استخدام الموجود.
- **السبب:** `metadata->in_reply_to` (JSON) ليس حاجزًا كافيًا ضد عاملين متزامنين؛ القيد الفريد
  في القاعدة هو الضمان الحقيقي. `ShouldBeUnique` طبقة دفاع إضافية لا الأساس.

## ADR-0016 — Idempotency للرسالة الواردة عبر unique + معالجة race
- **القرار:** الاعتماد على قيد `unique(external_message_id)` مع التقاط
  `UniqueConstraintViolationException` وإرجاع **duplicate** عند تكرار تسليم الرسالة الواردة.
- **السبب:** ضمان «رسالة واردة واحدة / Job واحد» حتى عند التسليم المكرر أو التزامن.
  (منع الرد المكرر يُعالَج بـ ADR-0020.)

## ADR-0015 — `/dev/chat` محصورة في local/testing
- **القرار:** المحاكي المحلي متاح في `local`/`testing` فقط ويعيد 404 في production
  (middleware `EnsureDevEnvironment` + حارس في `mount()`).
- **السبب:** أداة تطوير يجب ألا تُعرَّض في الإنتاج.

## ADR-0014 — CI على `push`/`pull_request` إلى main فقط
- **القرار:** تشغيل CI على `pull_request` إلى main و`push` إلى main فقط (بدل `feature/**`).
- **السبب:** منع التشغيل المزدوج عند دفع فرع الميزة ثم فتح PR؛ توفير موارد CI.

## ADR-0013 — PHP Backed Enums بدل `database enum`
- **القرار:** تمثيل كل status/type/direction/priority/channel كـ PHP Backed Enum،
  وتخزينه كنص (`string`) في قاعدة البيانات.
- **السبب:** `database enum` غير محمول بين PostgreSQL و SQLite (اختبارات in-memory)،
  ويجعل إضافة قيمة جديدة هجرة مؤلمة. النص + Enum يوفّر أمان الأنواع في التطبيق ومرونة في القاعدة.

## ADR-0012 — الأموال بنوع `decimal` فقط
- **القرار:** `expenses.amount` = decimal(15,2)، `usage_events.cost` = decimal(12,6)؛ لا `float`.
- **السبب:** `float` يسبب أخطاء تقريب في الأموال. الـ`decimal` يحفظ الدقة تمامًا.

## ADR-0011 — سياسة الحذف: cascade للبيانات الشخصية، nullOnDelete للسجلّات
- **القرار:** حذف المستخدم يُحذف بياناته الشخصية تسلسليًا؛ audit_logs و usage_events
  تبقى مع `user_id = null`؛ والمراجع لرسالة المصدر (`source_message_id`) تصبح `null`.
- **السبب:** احترام خصوصية المستخدم مع الاحتفاظ بسجلّات التدقيق والتكلفة اللازمة تشغيليًا.

## ADR-0010 — الهاتف (E.164) كمُعرِّف أساسي للمستخدم
- **القرار:** `users.phone` nullable + unique بصيغة E.164؛ password nullable.
- **السبب:** WhatsApp أولًا — يُعرَّف المستخدم برقمه لا ببريده/كلمة مروره؛ قيد unique
  يمنع إنشاء مستخدم جديد لكل رسالة من رقم موجود.

## ADR-0009 — Idempotency عبر قيود unique
- **القرار:** unique على `messages.external_message_id` و unique(`webhook_events.provider`,
  `external_event_id`).
- **السبب:** ضمان عدم معالجة نفس الرسالة/الحدث الوارد أكثر من مرة عند إعادة المحاولة.

## ADR-0008 — تأجيل pgvector/embeddings
- **القرار:** جدول `memories` يخزّن نصًا عاديًا الآن؛ لا pgvector في Sprint 0B.
- **السبب:** pgvector امتداد PostgreSQL غير متوفّر في SQLite ويكسر الاختبارات؛
  يُضاف مع محرك الذاكرة لاحقًا بمسار اختبار منفصل.

## ADR-0007 — التخزين الداخلي بتوقيت UTC وعرضه بتوقيت المستخدم
- **السياق:** المستخدمون في مناطق زمنية مختلفة؛ الافتراضي `Asia/Hebron`.
- **القرار:** `APP_TIMEZONE=UTC` دائمًا للتخزين، والتحويل عند العرض عبر
  `config('sanad.default_timezone')` (لاحقًا لكل مستخدم).
- **البديل المرفوض:** تخزين بتوقيت محلي — يسبب أخطاء عند تعدّد المناطق و DST.

## ADR-0006 — إعدادات المشروع في `config/sanad.php`
- **القرار:** تجميع الإعدادات الخاصة بالمنتج (التوقيت/العملة/اللغات الافتراضية)
  في ملف واحد بدل القيم المبعثرة.
- **السبب:** يسهّل التحوّل إلى Multi-user SaaS بإضافة تجاوزات لكل مستخدم لاحقًا.

## ADR-0005 — Redis للكاش والطوابير والجلسات + Horizon
- **القرار:** `CACHE_STORE=redis`، `QUEUE_CONNECTION=redis`، `SESSION_DRIVER=redis`،
  مع Laravel Horizon للإشراف على الطوابير.
- **السبب:** أداء وقابلية توسّع أفقي؛ Horizon يوفّر رؤية ومقاييس للطوابير.
- **ملاحظة:** Horizon v5.48 متوافق مع Laravel 13 (تم التحقق).

## ADR-0004 — PostgreSQL كقاعدة بيانات
- **القرار:** PostgreSQL 16 بدل MySQL/SQLite في الإنتاج.
- **السبب:** أنواع بيانات غنية (JSONB)، موثوقية، ومناسبة لميزات الذاكرة والبحث لاحقًا.
- **الاختبارات:** SQLite in-memory لسرعة CI واستقلالية عن الخدمات الخارجية.

## ADR-0003 — Livewire + Tailwind + RTL بدل SPA منفصل
- **القرار:** واجهة Livewire 3 مع Tailwind CSS 4 ودعم RTL، دون Next.js/Node SPA.
- **السبب:** تبسيط الحزمة التقنية (Laravel فقط)، سرعة التطوير، والواجهة الأساسية بسيطة.

## ADR-0002 — Pest للاختبارات و Pint للتنسيق
- **القرار:** Pest 4 كإطار اختبارات و Laravel Pint لتنسيق PHP، مع GitHub Actions.
- **السبب:** صياغة اختبارات أوضح، وتنسيق موحّد آلي، وبوابة جودة على كل PR.

## ADR-0001 — Laravel 13 (PHP 8.4) في جذر المستودع
- **القرار:** أحدث Laravel مستقر متوافق مع PHP 8.4، مثبّت في جذر المستودع دون مجلد فرعي.
- **السبب:** طلب صريح؛ يبسّط المسارات وأدوات النشر لاحقًا.

## ADR-0000 — WhatsApp كقناة أولى (رؤية)
- **القرار:** القناة الأساسية للتفاعل ستكون WhatsApp Cloud API.
- **الحالة:** رؤية فقط — لم تُنفَّذ في Sprint 0.

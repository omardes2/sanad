<?php

declare(strict_types=1);

/**
 * Subscriber-facing replies about a voice note.
 *
 * These exist so that domain logic never contains a sentence. The transcription
 * pipeline decides WHAT happened as a closed set of codes
 * (App\Enums\TranscriptionFailureReason); this file decides HOW it is said, per
 * locale. Adding a language is a new file here and no code change at all.
 *
 * Every line follows the same three rules:
 *   - say what happened in the subscriber's terms, never in ours;
 *   - say what they can do next, when there is something;
 *   - never leak a provider name, an HTTP status, a model, or a limit we would
 *     rather not publish as a promise.
 */
return [

    'failure' => [

        // Their plan does not include voice. A billing fact, said without blame.
        'voice_not_in_plan' => 'الرسائل الصوتية غير متاحة في باقتك الحالية. أرسل رسالتك نصًّا، أو رقِّ باقتك لتفعيل الصوت.',

        // Audio we cannot handle: the wrong format, or a file rather than a
        // spoken note. Deliberately not a technical description of either.
        'unsupported_audio' => 'لم أتمكّن من قراءة هذا المقطع الصوتي. جرّب تسجيل رسالة صوتية من واتساب مباشرة، أو أرسل رسالتك نصًّا.',

        'audio_too_large' => 'الملف الصوتي أكبر مما أستطيع معالجته. جرّب تسجيلًا أقصر، أو أرسل رسالتك نصًّا.',

        'audio_too_long' => 'الرسالة الصوتية أطول مما أستطيع تفريغه. قسّمها إلى رسائل أقصر، أو أرسل رسالتك نصًّا.',

        // The audio never reached us. Worth retrying, and we say so.
        'media_download_failed' => 'تعذّر عليّ تنزيل الرسالة الصوتية. أعد إرسالها من فضلك، أو اكتب لي رسالتك.',

        // WhatsApp media expires; a resend is genuinely the only path.
        'media_expired' => 'لم تعد الرسالة الصوتية متاحة لدى واتساب. أعد إرسالها من فضلك، أو اكتب لي رسالتك.',

        // Transcription is not switched on here. An operator fact, phrased as
        // an availability fact — the subscriber did nothing wrong.
        'transcription_not_configured' => 'تفريغ الرسائل الصوتية غير متاح حاليًا. أرسل رسالتك نصًّا من فضلك.',

        'transcription_failed' => 'لم أتمكّن من تفريغ رسالتك الصوتية. أعد المحاولة، أو اكتب لي رسالتك.',

        // We could not prove the outcome either way, and the attempt budget is
        // spent. We do not claim it failed, only that we have no text.
        'transcription_unknown' => 'لم يصلني نصّ رسالتك الصوتية. أعد إرسالها من فضلك، أو اكتب لي رسالتك.',

        // Silence, or speech we could not make out.
        'transcript_empty' => 'لم أسمع كلامًا واضحًا في الرسالة الصوتية. جرّب التسجيل في مكان أهدأ، أو اكتب لي رسالتك.',

        'internal' => 'حدث خطأ أثناء معالجة رسالتك الصوتية. أعد المحاولة، أو اكتب لي رسالتك.',

    ],

];

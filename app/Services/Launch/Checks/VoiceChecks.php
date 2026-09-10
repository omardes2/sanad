<?php

declare(strict_types=1);

namespace App\Services\Launch\Checks;

use App\Contracts\Ai\SupportsTranscription;
use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;
use App\Enums\AiOperation;
use App\Services\Ai\SanadAiRouter;
use App\Support\WhatsApp\WhatsAppConfig;
use Throwable;

/**
 * Readiness of the voice-note path: a subscriber speaks, Sanad understands.
 *
 * WHAT THIS GATE ASKS is whether the four things that must ALL be true for a
 * voice note to become text are true right now:
 *
 *   1. the feature is switched on;
 *   2. WhatsApp media can be fetched (the same credential that sends);
 *   3. a transcription-capable model is catalogued and routable;
 *   4. the provider it routes to can actually transcribe.
 *
 * WHAT IT DOES NOT ASK is whether any voice note has succeeded. That is
 * OBSERVATION, and this codebase has one rule about observation on this screen:
 * absence of evidence is not evidence of failure (ADR-0045). A deployment that
 * has simply had no voice notes yet is not unready, and a gate that said
 * otherwise would block a launch on traffic that has not happened.
 *
 * PRIVACY: the provider key and the routed model id are operator configuration
 * and are safe to render. No credential, no key id, no subscriber fact, and no
 * signed media URL is read here — and no provider name is ever GUESSED: what is
 * printed is what the catalog actually resolved, or nothing.
 */
final class VoiceChecks
{
    public static function transcription(): GateOutcome
    {
        $enabled = (bool) config('voice.enabled', true);

        $details = [
            GateDetail::boolean('التفريغ مفعَّل', $enabled, 'مفعَّل', 'معطّل'),
        ];

        if (! $enabled) {
            $details[] = GateDetail::plain(
                'الأثر',
                'الرسالة الصوتية تُستقبَل وتُخزَّن ويُردّ عليها بسبب محدَّد — ولا تُهمَل بصمت',
            );

            return GateOutcome::notReady('تفريغ الرسائل الصوتية معطّل في هذه البيئة.', $details);
        }

        // The media fetch uses the SAME access token as sending, so a WhatsApp
        // integration that cannot send cannot download a voice note either.
        $whatsapp = app(WhatsAppConfig::class);
        $details[] = GateDetail::boolean('تنزيل وسائط واتساب', $whatsapp->canSend(), 'ممكن', 'غير مضبوط');

        if (! $whatsapp->canSend()) {
            return GateOutcome::notReady('تعذّر تنزيل الوسائط: تكامل واتساب غير مكتمل.', $details);
        }

        [$provider, $model, $transcribes, $error] = self::route();

        if ($provider === null) {
            $details[] = GateDetail::bad('التوجيه', $error ?? 'لا يوجد نموذج تفريغ مؤهَّل في الفهرس');
            $details[] = GateDetail::plain(
                'المطلوب',
                'نموذج بقدرة transcription في فهرس النماذج (قاعدة البيانات أو config)، لمزوّد مضبوط',
            );

            return GateOutcome::notReady('لا يوجد مسار تفريغ صالح.', $details);
        }

        $details[] = GateDetail::ok('المزوّد المختار', $provider);
        $details[] = GateDetail::plain('النموذج', $model ?? '—');
        $details[] = GateDetail::boolean('المزوّد ينفّذ عقد التفريغ', $transcribes, 'نعم', 'لا');

        if (! $transcribes) {
            // The catalog says this model transcribes; the adapter says it
            // cannot. Operator data must never be able to crash a worker, so
            // the pipeline treats this as "no route" — and so does this gate.
            $details[] = GateDetail::plain('الأثر', 'يُعامَل كأنه بلا مسار: لا يُستدعى المزوّد إطلاقًا');

            return GateOutcome::notReady('المزوّد المختار لا ينفّذ SupportsTranscription.', $details);
        }

        $details[] = GateDetail::plain(
            'الحدود',
            sprintf(
                'حجم أقصى %d ميغابايت · مدّة قصوى %d ثانية · محاولتان فعليتان كحدّ أقصى',
                (int) round(((int) config('voice.max_bytes', 0)) / 1048576),
                (int) config('voice.max_duration_seconds', 0),
            ),
        );
        $details[] = GateDetail::plain(
            'الضمان',
            'مرّة واحدة على الأقل بحدّ أعلى — ليس exactly-once: نتيجة غير معروفة لا تُعدّ فشلًا ولا تكلفة صفرية',
        );
        $details[] = GateDetail::plain(
            'التكلفة',
            'صفوف الاستخدام تُسجَّل بلا سعر: التفريغ يُسعَّر بالدقيقة ولا يوجد تسعير بالدقيقة بعد',
        );

        return GateOutcome::ready('التفريغ مفعَّل، الوسائط قابلة للتنزيل، ومسار التفريغ صالح.', $details);
    }

    /**
     * The routed transcription provider, or nulls with a safe reason.
     *
     * @return array{0: ?string, 1: ?string, 2: bool, 3: ?string}
     */
    private static function route(): array
    {
        try {
            $route = app(SanadAiRouter::class)->route(AiOperation::Transcription);
        } catch (Throwable $e) {
            // The message is from the typed AI hierarchy, which is safe by
            // construction (status codes and short reasons only).
            return [null, null, false, $e->getMessage()];
        }

        return [
            $route->provider->name(),
            $route->model,
            $route->provider instanceof SupportsTranscription,
            null,
        ];
    }
}

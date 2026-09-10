<?php

declare(strict_types=1);

namespace App\Services\Launch\Checks;

use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;

/**
 * Gates for V1 capabilities that HAVE NO IMPLEMENTATION in this codebase.
 *
 * There is nothing to probe: a feature that does not exist cannot report on
 * itself, so each of these states the absence and says what would have to exist
 * for it to move. The registry is kept honest from the other side — a test
 * greps `app/` for each capability and FAILS if an implementation appears while
 * the gate still claims `not_implemented`. That is what stops this file from
 * quietly becoming a lie once the work lands.
 *
 * No capability is invented anywhere here, and no name is guessed for something
 * that does not exist — a readiness screen that fills in a plausible detail is
 * worse than one that says nothing.
 *
 * Voice transcription and recurring reminders used to live here and no longer do:
 * both are implemented, so their gates moved to VoiceChecks and ReminderChecks
 * and REPORT rather than declare. That migration is the intended lifecycle of
 * every method in this file.
 */
final class FeatureChecks
{
    public static function morningBrief(): GateOutcome
    {
        return GateOutcome::notImplemented(
            'الموجز الصباحي غير منفَّذ.',
            [
                GateDetail::bad('التنفيذ', 'لا يوجد مسار موجز صباحي في app/'),
                GateDetail::plain('المطلوب', 'جدولة حسب توقيت المشترك + تفعيل اختياري + سياسة رسالة استباقية'),
            ],
        );
    }

    /**
     * POST-V1, declared so the screen shows what was deliberately deferred
     * rather than leaving a reader to wonder whether it was forgotten.
     */
    public static function implicitMemoryExtraction(): GateOutcome
    {
        return GateOutcome::notImplemented(
            'مؤجَّل بعد V1 عن قصد: V1 تحفظ بطلب صريح فقط.',
            [
                GateDetail::plain('السبب', 'الاستخراج الضمني يحتاج عتبة ثقة، وبلا مجموعة معايرة حقيقية تكون رقمًا مخترَعًا'),
                // The enum case is deliberately NOT spelled out here: a guard
                // test greps app/ for that identifier to prove nothing writes an
                // inferred memory, and a display string must not blunt it.
                GateDetail::plain('الموجود', 'العمود provenance وحالة «مستنتج» في الـenum بلا أي كاتب'),
            ],
        );
    }

    /** POST-V1. */
    public static function semanticMemoryRetrieval(): GateOutcome
    {
        return GateOutcome::notImplemented(
            'مؤجَّل بعد V1 عن قصد: الاسترجاع في V1 ترتيب حتمي داخل سقف معلَن، لا بحث تشابه.',
            [
                GateDetail::plain('السبب', 'ADR-0008 — اختراع مقياس صلة قبل الـembeddings اختراع رقم'),
            ],
        );
    }
}

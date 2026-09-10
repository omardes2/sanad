<?php

declare(strict_types=1);

namespace App\Services\Launch\Checks;

use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;
use App\Services\Memory\MemoryCipher;
use App\Support\Ai\Contributors\UserMemoryContributor;
use App\Support\Tools\ToolCatalog;
use Throwable;

/**
 * Durable memory readiness.
 *
 * PRIVACY, and it is the strictest on the page: no key is read, rendered or
 * logged here. `MemoryCipher::available()` answers presence; `keyId()` answers
 * with an IDENTIFIER derived for exactly this purpose. `MEMORY_FINGERPRINT_KEY`
 * is reported as present/absent and never otherwise, and no fingerprint value
 * appears anywhere — a fingerprint is a keyed MAC over a memory, so showing one
 * hands an attacker a confirmation oracle for free.
 */
final class MemoryChecks
{
    public static function encryption(): GateOutcome
    {
        $cipher = app(MemoryCipher::class);

        $sealAvailable = $cipher->available();
        $fingerprintKey = trim((string) config('memory.fingerprint_key', '')) !== '';
        $previousKeys = trim((string) config('memory.previous_keys', '')) !== '';

        $details = [
            GateDetail::boolean('مفتاح التشفير (MEMORY_KEY)', $sealAvailable),
            GateDetail::boolean('مفتاح البصمة (MEMORY_FINGERPRINT_KEY)', $fingerprintKey),
            GateDetail::plain('الخوارزمية', (string) config('memory.cipher', 'aes-256-gcm')),
            GateDetail::plain('مفاتيح سابقة للتدوير', $previousKeys ? 'مضبوطة' : 'غير مضبوطة'),
        ];

        if ($sealAvailable) {
            $keyId = null;

            try {
                $keyId = $cipher->keyId();
            } catch (Throwable) {
                $keyId = null;
            }

            if ($keyId !== null) {
                // An identifier, never a key.
                $details[] = GateDetail::plain('معرّف المفتاح الفعّال', $keyId);
            }
        }

        $details[] = GateDetail::plain(
            'التزام دائم',
            'لا توجد مرحلة إعادة تشفير في هذا الإصدار: يجب الاحتفاظ بأي مفتاح سابق ما دام صفٌّ واحد مختومًا به',
        );

        if (! $sealAvailable || ! $fingerprintKey) {
            $details[] = GateDetail::plain('الأثر', 'الذاكرة فاشلة مغلقة: لا تُحفظ ولا تُقرأ ولا تصل الـprompt');

            return GateOutcome::notReady('مفتاحا الذاكرة غير مضبوطين بالكامل.', $details);
        }

        return GateOutcome::ready('مفتاحا الذاكرة مضبوطان ومستقلّان.', $details);
    }

    public static function system(): GateOutcome
    {
        $cipher = app(MemoryCipher::class);
        $keysReady = $cipher->available() && trim((string) config('memory.fingerprint_key', '')) !== '';

        $contributors = (array) config('ai.context_contributors', []);
        $contributorRegistered = in_array(UserMemoryContributor::class, $contributors, true);

        $exposable = array_map(
            static fn ($definition): string => $definition->key->value(),
            app(ToolCatalog::class)->executable(),
        );

        $tools = ['memory.read@2', 'memory.write@1', 'memory.forget@1'];
        $missing = array_values(array_diff($tools, $exposable));

        $details = [
            GateDetail::boolean('التشفير مضبوط', $keysReady, 'مضبوط', 'غير مضبوط'),
            GateDetail::boolean('حاقن الذاكرة في الـprompt', $contributorRegistered, 'مسجَّل', 'غير مسجَّل'),
            GateDetail::boolean('أدوات الذاكرة معروضة', $missing === [], 'الثلاثة معروضة', 'ناقصة: '.implode('، ', $missing)),
            GateDetail::plain('الحدود', config('memory.max_active').' ذاكرة نشطة · '.config('memory.max_content_chars').' حرف · '.config('memory.prompt_limit').' في الـprompt · '.config('memory.prompt_max_chars').' حرف'),
            GateDetail::plain('سياسة V1', 'الحفظ بطلب صريح فقط — لا استخراج ضمني، ولا كاتب لـ inferred'),
        ];

        if (! $keysReady) {
            return GateOutcome::notReady('منظومة الذاكرة معطّلة: التشفير غير مضبوط.', $details);
        }

        if (! $contributorRegistered || $missing !== []) {
            return GateOutcome::notReady('منظومة الذاكرة ناقصة التركيب.', $details);
        }

        return GateOutcome::ready('الذاكرة الدائمة جاهزة: تشفير مضبوط، أدوات معروضة، وحقن محدود في الـprompt.', $details);
    }
}

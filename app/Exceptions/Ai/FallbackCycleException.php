<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use App\Models\AiModel;
use InvalidArgumentException;

final class FallbackCycleException extends InvalidArgumentException
{
    public static function selfReference(AiModel $model): self
    {
        return new self("النموذج [{$model->external_id}] لا يمكن أن يكون بديلًا لنفسه.");
    }

    /**
     * @param  list<int>  $path
     */
    public static function cycle(AiModel $model, array $path): self
    {
        return new self("سلسلة البدائل للنموذج [{$model->external_id}] تشكّل حلقة (".implode(' → ', $path).').');
    }

    public static function tooDeep(AiModel $model, int $max): self
    {
        return new self("سلسلة البدائل للنموذج [{$model->external_id}] أطول من الحد المسموح ({$max}).");
    }
}

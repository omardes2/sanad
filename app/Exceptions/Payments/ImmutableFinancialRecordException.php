<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A financial ledger row was about to be updated or deleted — never allowed. */
final class ImmutableFinancialRecordException extends LogicException
{
    public static function for(Model $model, string $operation): self
    {
        return new self(sprintf('%s #%s is immutable financial history; %s is not allowed. Record a new row instead.', $model::class, $model->getKey() ?? 'new', $operation));
    }
}

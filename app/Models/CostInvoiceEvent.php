<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostInvoiceEventType;
use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;

/** One append-only invoice lifecycle event (Phase E2). */
class CostInvoiceEvent extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['cost_invoice_id', 'event_type', 'occurred_at', 'actor_ref', 'reason_code', 'evidence_ref', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['event_type' => CostInvoiceEventType::class, 'occurred_at' => 'datetime'];
    }

    public function getDateFormat(): string
    {
        return CostInvoice::TIMESTAMP_FORMAT;
    }
}

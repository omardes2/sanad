<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\Exceptions\Fx\FxRuleException;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Models\FxPair;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Payments\FinanceAuthorization;
use App\Support\Rbac\Permission;
use App\Support\Reconciliation\ReconciliationRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Canonical FX pairs (Phase E3): exactly one pair per two currencies
 * (pair_key = min:max, unique), with the official quoting orientation fixed
 * at creation. Creating the reversed pair is refused — it is the SAME pair.
 */
final class FxPairBook
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $baseCurrency, string $quoteCurrency): FxPair
    {
        FinanceAuthorization::assertCan(Permission::FinanceFxManage);
        $base = self::currency($baseCurrency, 'base_currency');
        $quote = self::currency($quoteCurrency, 'quote_currency');

        if ($base === $quote) {
            throw FxRuleException::of('pair', 'زوج الصرف يتطلب عملتين مختلفتين.');
        }

        $key = FxPair::keyFor($base, $quote);

        return DB::transaction(function () use ($base, $quote, $key): FxPair {
            try {
                return DB::transaction(function () use ($base, $quote, $key): FxPair { // savepoint
                    $pair = FxPair::query()->create(['pair_key' => $key, 'base_currency' => $base, 'quote_currency' => $quote, 'created_by_ref' => FinanceAuthorization::actorRef()]);
                    $this->audit->record(AuditActions::FxPairCreated, $pair, ['pair' => ['from' => null, 'to' => "{$base}/{$quote}"]], ['pair_key' => $key]);

                    return $pair;
                });
            } catch (UniqueConstraintViolationException) {
                $existing = FxPair::query()->where('pair_key', $key)->firstOrFail();

                throw FxRuleException::of('pair_exists', "الزوج {$key} موجود بالاتجاه الرسمي {$existing->base_currency}/{$existing->quote_currency}؛ لا يُنشأ زوج معاكس.");
            }
        });
    }

    /** The one pair covering two currencies, or null. */
    public function find(string $a, string $b): ?FxPair
    {
        return FxPair::query()->where('pair_key', FxPair::keyFor(strtoupper($a), strtoupper($b)))->first();
    }

    public static function currency(string $value, string $rule): string
    {
        try {
            return ReconciliationRules::currency($value, $rule);
        } catch (ReconciliationRuleException $e) {
            throw FxRuleException::of($rule, $e->getMessage());
        }
    }
}

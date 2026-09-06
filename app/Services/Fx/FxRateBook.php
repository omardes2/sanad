<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\Data\Fx\RecordRateInput;
use App\Exceptions\Fx\FxRuleException;
use App\Exceptions\Fx\StaleFxException;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Models\FxPair;
use App\Models\FxRate;
use App\Models\FxRateScope;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Billing\DecimalMath;
use App\Support\Fx\FxMath;
use App\Support\Payments\FinanceAuthorization;
use App\Support\Rbac\Permission;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Manual point-in-time quotes (Phase E3). record(): official orientation only
 * → find-or-create the (pair, date) scope → FOR UPDATE → expected current
 * revision (stale ⇒ refused) → append the new revision (supersedes the
 * previous) → move the pointer → audit. No validity interval, no latest /
 * nearest / previous-day lookup exists anywhere in this class: a quote is
 * for its date, and a conversion must name the exact revision it uses.
 */
final class FxRateBook
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function record(RecordRateInput $input): FxRate
    {
        FinanceAuthorization::assertCan(Permission::FinanceFxManage);
        $base = FxPairBook::currency($input->baseCurrency, 'base_currency');
        $quote = FxPairBook::currency($input->quoteCurrency, 'quote_currency');
        $rateScaled = FxMath::rateToScaled($input->rate);
        $rate = DecimalMath::format($rateScaled, FxMath::RATE_SCALE);
        $date = self::date($input->rateDate);
        $evidence = self::ref($input->evidenceRef, 191, 'evidence_ref', required: true);
        $reason = self::ref($input->reasonCode, 32, 'reason_code');

        $pair = FxPair::query()->where('pair_key', FxPair::keyFor($base, $quote))->first()
            ?? throw FxRuleException::of('pair', "لا يوجد زوج صرف لـ{$base}/{$quote}؛ أنشئ الزوج أولًا.");

        if ($pair->base_currency !== $base || $pair->quote_currency !== $quote) {
            throw FxRuleException::of('orientation', "الاتجاه الرسمي لهذا الزوج هو {$pair->base_currency}/{$pair->quote_currency} (1 {$pair->base_currency} = rate × {$pair->quote_currency}). أدخل السعر بهذا الاتجاه؛ لا يُقلب تلقائيًا.");
        }

        return DB::transaction(function () use ($pair, $date, $rate, $evidence, $reason, $input): FxRate {
            $scope = $this->lockScope($pair, $date);

            if ($scope->current_rate_id !== $input->expectedCurrentRateId) {
                throw new StaleFxException('سعر هذا الزوج لهذا التاريخ تغيّر (المتوقع '.($input->expectedCurrentRateId ?? 'none').'، الحالي '.($scope->current_rate_id ?? 'none').'). حدّث وأعد المحاولة. لم يُكتب شيء.');
            }

            $previous = $scope->current_rate_id;
            $now = CarbonImmutable::now();
            $revision = FxRate::query()->create([
                'fx_pair_id' => $pair->id, 'scope_id' => $scope->id, 'rate_date' => $date, 'base_currency' => $pair->base_currency, 'quote_currency' => $pair->quote_currency,
                'rate' => $rate, 'source' => 'manual', 'evidence_ref' => $evidence, 'reason_code' => $reason, 'supersedes_id' => $previous,
                'recorded_by_ref' => FinanceAuthorization::actorRef(), 'created_at' => $now,
            ]);

            $scope->forceFill(['current_rate_id' => $revision->id, 'version' => $scope->version + 1, 'updated_by_ref' => FinanceAuthorization::actorRef()])->save();

            $this->audit->record(AuditActions::FxRateRecorded, $scope, [
                'current_rate_id' => ['from' => $previous, 'to' => $revision->id],
            ], ['pair' => "{$pair->base_currency}/{$pair->quote_currency}", 'rate_date' => $date, 'rate' => $rate, 'source' => 'manual', 'evidence_ref' => $evidence, 'reason_code' => $reason, 'revision' => $scope->version]);

            return $revision;
        });
    }

    /**
     * Quotes recorded for two currencies on one exact date — a display aid
     * for the finance user to pick an id from. Never used to auto-select.
     *
     * @return list<FxRate> current revisions only
     */
    public function quotesFor(string $a, string $b, string $date): array
    {
        $pair = FxPair::query()->where('pair_key', FxPair::keyFor(strtoupper($a), strtoupper($b)))->first();

        if ($pair === null) {
            return [];
        }

        $scope = FxRateScope::query()->where('fx_pair_id', $pair->id)->where('rate_date', self::date($date))->first();

        if ($scope === null || $scope->current_rate_id === null) {
            return [];
        }

        return [FxRate::query()->findOrFail($scope->current_rate_id)];
    }

    /** Is this rate row the CURRENT revision of its (pair, date) scope? */
    public static function isCurrent(FxRate $rate): bool
    {
        return FxRateScope::query()->whereKey($rate->scope_id)->value('current_rate_id') === $rate->id;
    }

    private function lockScope(FxPair $pair, string $date): FxRateScope
    {
        $find = static fn () => FxRateScope::query()->where('fx_pair_id', $pair->id)->where('rate_date', $date)->lockForUpdate()->first();
        $scope = $find();

        if ($scope === null) {
            try {
                DB::transaction(static fn () => FxRateScope::query()->create(['fx_pair_id' => $pair->id, 'rate_date' => $date, 'current_rate_id' => null, 'version' => 0]));
            } catch (UniqueConstraintViolationException) {
                // created concurrently — lock it below
            }

            $scope = $find() ?? throw new StaleFxException('تعذّر إنشاء نطاق السعر؛ أعد المحاولة.');
        }

        return $scope;
    }

    public static function date(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw FxRuleException::of('rate_date', 'تاريخ السعر بصيغة YYYY-MM-DD (UTC).');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            $date = false;
        }

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw FxRuleException::of('rate_date', 'تاريخ السعر غير صالح.');
        }

        if ($date->greaterThan(CarbonImmutable::now('UTC')->startOfDay())) {
            throw FxRuleException::of('rate_date', 'لا يُسجَّل سعر لتاريخ مستقبلي.');
        }

        return $value;
    }

    public static function ref(?string $value, int $max, string $rule, bool $required = false): ?string
    {
        try {
            return $required ? ReconciliationRules::requiredRef($value, $max, $rule) : ReconciliationRules::ref($value, $max, $rule);
        } catch (ReconciliationRuleException $e) {
            throw FxRuleException::of($rule, $e->getMessage());
        }
    }
}

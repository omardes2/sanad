<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\Data\Fx\ReportingConversionInput;
use App\Enums\FxConversionPurpose;
use App\Enums\FxSubjectType;
use App\Exceptions\Fx\FxRuleException;
use App\Exceptions\Fx\StaleFxException;
use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Models\FxRate;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Fx\FxMath;
use App\Support\Payments\FinanceAuthorization;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Frozen REPORTING conversions (Phase E3): the finance user names the exact
 * fx_rate_id; the service verifies it is the CURRENT revision for the pair
 * covering (source, target) and that its rate_date equals the subject's
 * policy date (received_at / refunded_at / period_end, UTC) — it never
 * searches for a rate. A native subject (same currency as the target) is
 * refused: NATIVE is a display state, never a rate-1 conversion. Revisions
 * are append-only under the conversion scope lock with an expected pointer.
 * The subject is never modified.
 */
final class ReportingConversionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function convert(ReportingConversionInput $input): FxConversion
    {
        FinanceAuthorization::assertCan(Permission::FinanceFxManage);
        $type = FxSubjectType::tryFrom(trim($input->subjectType)) ?? throw FxRuleException::of('subject_type', 'نوع الموضوع يجب أن يكون customer_payment أو customer_refund أو cost_reconciliation أو cost_adjustment.');
        $target = FxPairBook::currency($input->targetCurrency, 'target_currency');
        $reason = FxRateBook::ref($input->reasonCode, 32, 'reason_code');

        /** @var Model|null $subject */
        $subject = $type->modelClass()::query()->find($input->subjectId);

        if ($subject === null) {
            throw FxRuleException::of('subject', 'الموضوع غير موجود.');
        }

        $sourceCurrency = (string) $subject->getAttribute('currency');
        $sourceAmount = FxMath::formatAtScale((string) $subject->getAttribute($type->amountField()), $type->scale());
        $subjectDate = $type->policyDate($subject);

        if ($sourceCurrency === $target) {
            throw FxRuleException::of('native', "الموضوع بعملة التقرير نفسها ({$target}): يُعرض NATIVE بلا تحويل ولا سعر.");
        }

        return DB::transaction(function () use ($input, $type, $target, $reason, $subject, $sourceCurrency, $sourceAmount, $subjectDate): FxConversion {
            $scope = $this->lockScope($type, $subject->getKey(), $target);

            if ($scope->current_conversion_id !== $input->expectedCurrentConversionId) {
                throw new StaleFxException('تحويل هذا الموضوع تغيّر (المتوقع '.($input->expectedCurrentConversionId ?? 'none').'، الحالي '.($scope->current_conversion_id ?? 'none').'). لم يُكتب شيء.');
            }

            $rate = self::acceptedRate($input->fxRateId, $sourceCurrency, $target, $subjectDate->format('Y-m-d'));
            $direction = FxMath::directionFor($rate->base_currency, $rate->quote_currency, $sourceCurrency, $target);
            $targetAmount = FxMath::convert($sourceAmount, $type->scale(), (string) $rate->rate, $direction, $type->scale());
            $previous = $scope->current_conversion_id;
            $now = CarbonImmutable::now();

            $conversion = FxConversion::query()->create([
                'scope_id' => $scope->id, 'subject_type' => $type->value, 'subject_id' => $subject->getKey(), 'purpose' => FxConversionPurpose::Reporting->value,
                'subject_date' => $subjectDate, 'source_amount' => $sourceAmount, 'source_scale' => $type->scale(), 'source_currency' => $sourceCurrency,
                'fx_rate_id' => $rate->id, 'fx_rate_date' => $rate->rateDate(), 'rate_snapshot' => (string) $rate->rate, 'direction' => $direction->value,
                'target_amount' => $targetAmount, 'target_scale' => $type->scale(), 'target_currency' => $target,
                'supersedes_id' => $previous, 'reason_code' => $reason, 'actor_ref' => FinanceAuthorization::actorRef(), 'created_at' => $now,
            ]);

            $scope->forceFill(['current_conversion_id' => $conversion->id, 'version' => $scope->version + 1, 'updated_by_ref' => FinanceAuthorization::actorRef()])->save();

            $this->audit->record(AuditActions::FxConverted, $subject, [
                'current_conversion_id' => ['from' => $previous, 'to' => $conversion->id],
            ], [
                'subject_type' => $type->value, 'purpose' => 'reporting', 'subject_date' => $subjectDate->toIso8601String(),
                'source_amount' => $sourceAmount, 'source_currency' => $sourceCurrency, 'source_scale' => $type->scale(),
                'fx_rate_id' => $rate->id, 'fx_rate_date' => $rate->rateDate(), 'rate_snapshot' => (string) $rate->rate, 'direction' => $direction->value,
                'target_amount' => $targetAmount, 'target_currency' => $target, 'target_scale' => $type->scale(), 'reason_code' => $reason,
            ]);

            return $conversion;
        });
    }

    /**
     * The exact rate row the caller named, verified: exists, covers the two
     * currencies, is dated on the policy date, and is still the CURRENT
     * revision of its scope (a superseded revision is a stale choice).
     *
     * @throws FxRuleException|StaleFxException
     */
    public static function acceptedRate(int $fxRateId, string $from, string $to, string $policyDate): FxRate
    {
        $rate = FxRate::query()->find($fxRateId) ?? throw FxRuleException::of('FX_RATE_MISSING', "سعر الصرف #{$fxRateId} غير موجود. سجّل سعر {$from}/{$to} لتاريخ {$policyDate} ثم أعد العملية.");

        if (FxMath::directionFor($rate->base_currency, $rate->quote_currency, $from, $to) === null) {
            throw FxRuleException::of('FX_RATE_MISSING', "السعر #{$fxRateId} للزوج {$rate->base_currency}/{$rate->quote_currency} لا يغطي {$from}→{$to}.");
        }

        if ($rate->rateDate() !== $policyDate) {
            throw FxRuleException::of('FX_RATE_MISSING', "السعر #{$fxRateId} بتاريخ {$rate->rateDate()} وتاريخ السياسة للموضوع {$policyDate}؛ لا يُستخدم أقرب/أحدث سعر تلقائيًا. سجّل سعرًا لهذا التاريخ.");
        }

        if (! FxRateBook::isCurrent($rate)) {
            throw new StaleFxException("السعر #{$fxRateId} استُبدل بمراجعة أحدث؛ اختر المراجعة الحالية. لم يُكتب شيء.");
        }

        return $rate;
    }

    private function lockScope(FxSubjectType $type, int $subjectId, string $target): FxConversionScope
    {
        $find = static fn () => FxConversionScope::query()->where('subject_type', $type->value)->where('subject_id', $subjectId)->where('purpose', FxConversionPurpose::Reporting->value)->where('target_currency', $target)->lockForUpdate()->first();
        $scope = $find();

        if ($scope === null) {
            try {
                DB::transaction(static fn () => FxConversionScope::query()->create(['subject_type' => $type->value, 'subject_id' => $subjectId, 'purpose' => FxConversionPurpose::Reporting->value, 'target_currency' => $target, 'current_conversion_id' => null, 'version' => 0]));
            } catch (UniqueConstraintViolationException) {
                // created concurrently
            }

            $scope = $find() ?? throw new StaleFxException('تعذّر إنشاء نطاق التحويل؛ أعد المحاولة.');
        }

        return $scope;
    }

    /** Current reporting conversion of a subject into a target currency, or null. */
    public static function currentFor(FxSubjectType $type, int $subjectId, string $target): ?FxConversion
    {
        $id = FxConversionScope::query()->where('subject_type', $type->value)->where('subject_id', $subjectId)->where('purpose', FxConversionPurpose::Reporting->value)->where('target_currency', $target)->value('current_conversion_id');

        return $id === null ? null : FxConversion::query()->find($id);
    }
}

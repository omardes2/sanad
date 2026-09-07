<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\Exceptions\Fx\FxRuleException;
use App\Exceptions\Fx\StaleFxException;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsRepository;
use App\Support\Audit\AuditActions;
use App\Support\Payments\FinanceAuthorization;
use App\Support\Rbac\Permission;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The dedicated writer of `finance.reporting_currency` (a managed setting):
 * finance.fx.manage + the new code typed verbatim + audit. Changing it never
 * recomputes or rewrites a frozen conversion — only what the reports show as
 * NATIVE / CONVERTED / NOT CONVERTED changes.
 */
final class ReportingCurrencyService
{
    public function __construct(private readonly SettingsRepository $settings, private readonly AuditLogger $audit) {}

    public function current(): string
    {
        return strtoupper((string) $this->settings->get(SettingsRegistry::REPORTING_CURRENCY));
    }

    /**
     * Change the reporting currency — race-safe (Phase E5.2c).
     *
     * The caller states the currency it saw (`expectedCurrentCurrency`); the
     * truth is re-read INSIDE the transaction under a FOR UPDATE lock on the
     * setting row, so two concurrent changes can never both win: the second
     * one sees the first one's value and is refused as stale (never
     * last-writer-wins, never a second audit entry). When no row exists yet
     * (the very first write) there is nothing to lock — that race is decided
     * by the unique key on `app_settings.key`: the loser's insert violates it
     * inside a savepoint and is refused as stale with nothing written.
     *
     * Changing the currency never recomputes or rewrites a frozen conversion;
     * only what the reports show as NATIVE / CONVERTED / NOT CONVERTED changes.
     *
     * @throws FxRuleException|StaleFxException
     */
    public function change(string $currency, string $typedConfirmation, string $expectedCurrentCurrency, ?string $reasonCode = null): string
    {
        FinanceAuthorization::assertCan(Permission::FinanceFxManage);
        $code = FxPairBook::currency($currency, 'reporting_currency');
        $expected = FxPairBook::currency($expectedCurrentCurrency, 'expected_current_currency');
        $reason = FxRateBook::ref($reasonCode, 32, 'reason_code');

        if ($typedConfirmation !== $code) {
            throw FxRuleException::of('typed_confirmation', "اكتب رمز العملة الجديد حرفيًا ({$code}) لتأكيد تغيير عملة التقرير.");
        }

        return DB::transaction(function () use ($code, $expected, $reason, $typedConfirmation): string {
            $locked = $this->settings->lockManaged(SettingsRegistry::REPORTING_CURRENCY);
            $this->settings->cacheFlush(); // the locked row is the truth; never decide on a cached value
            $before = $locked === null ? $this->current() : strtoupper((string) $locked);

            if ($before !== $expected) {
                throw new StaleFxException("عملة التقرير تغيّرت (المتوقع {$expected}، الحالية {$before}). حدّث الصفحة وأعد المحاولة. لم يُكتب شيء.");
            }

            if ($before === $code) {
                throw FxRuleException::of('unchanged', "عملة التقرير هي {$code} بالفعل.");
            }

            try {
                // The repository re-checks the typed confirmation itself (no bypass through any other writer).
                $this->settings->setManaged(SettingsRegistry::REPORTING_CURRENCY, $code, $reason, $typedConfirmation);
            } catch (UniqueConstraintViolationException) {
                throw new StaleFxException("عملة التقرير كُتبت لأول مرة بواسطة طلب متزامن آخر (المتوقع {$expected}). لم يُكتب شيء.");
            }

            $this->audit->record(AuditActions::FinanceReportingCurrencyChanged, null, ['reporting_currency' => ['from' => $before, 'to' => $code]], ['typed_confirmation' => $code, 'reason_code' => $reason, 'expected_current_currency' => $expected, 'conversions_recomputed' => 0]);

            return $code;
        });
    }
}

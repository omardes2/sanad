<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\Exceptions\Fx\FxRuleException;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsRepository;
use App\Support\Audit\AuditActions;
use App\Support\Payments\FinanceAuthorization;
use App\Support\Rbac\Permission;
use App\Support\Settings\SettingsRegistry;
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

    public function change(string $currency, string $typedConfirmation, ?string $reasonCode = null): string
    {
        FinanceAuthorization::assertCan(Permission::FinanceFxManage);
        $code = FxPairBook::currency($currency, 'reporting_currency');
        $reason = FxRateBook::ref($reasonCode, 32, 'reason_code');

        if ($typedConfirmation !== $code) {
            throw FxRuleException::of('typed_confirmation', "اكتب رمز العملة الجديد حرفيًا ({$code}) لتأكيد تغيير عملة التقرير.");
        }

        $before = $this->current();

        if ($before === $code) {
            throw FxRuleException::of('unchanged', "عملة التقرير هي {$code} بالفعل.");
        }

        DB::transaction(function () use ($code, $before, $reason): void {
            $this->settings->setManaged(SettingsRegistry::REPORTING_CURRENCY, $code, $reason);
            $this->audit->record(AuditActions::FinanceReportingCurrencyChanged, null, ['reporting_currency' => ['from' => $before, 'to' => $code]], ['typed_confirmation' => $code, 'reason_code' => $reason, 'conversions_recomputed' => 0]);
        });

        return $code;
    }
}

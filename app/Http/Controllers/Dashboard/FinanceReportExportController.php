<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Http\Controllers\Controller;
use App\Models\FinancePeriodClose;
use App\Services\Reporting\CashExporter;
use App\Services\Reporting\CloseExporter;
use App\Services\Reporting\CostExporter;
use App\Services\Reporting\FxExporter;
use App\Services\Usage\UsageQuery;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase E5.1 CSV exports — every route carries `permission:finance.export`
 * and every action re-checks it (fail closed). Read-only: the exporters read
 * through the same services / projections as the pages and never write.
 *
 *  GET /dashboard/finance/cash/export?from=Y-m-d&to=Y-m-d          (window ≤ 366 days)
 *  GET /dashboard/finance/cost/export?from=YYYY-MM&to=YYYY-MM       (≤ 13 months)
 *  GET /dashboard/finance/fx/export?from=Y-m-d&to=Y-m-d            (window ≤ 366 days)
 *  GET /dashboard/finance/close/{close}/export                      (frozen close only)
 */
class FinanceReportExportController extends Controller
{
    public function cash(Request $request, CashExporter $exporter): StreamedResponse
    {
        [$from, $to] = $this->window($request);

        return $exporter->stream($from, $to);
    }

    public function cost(Request $request, CostExporter $exporter): StreamedResponse
    {
        $this->authorizeExport($request);
        $validated = $request->validate([
            'from' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            return $exporter->stream($validated['from'], $validated['to']);
        } catch (ReconciliationRuleException|InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function fx(Request $request, FxExporter $exporter): StreamedResponse
    {
        [$from, $to] = $this->window($request);

        return $exporter->stream($from, $to);
    }

    public function close(Request $request, FinancePeriodClose $close, CloseExporter $exporter): StreamedResponse
    {
        $this->authorizeExport($request);

        return $exporter->stream($close);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(Request $request): array
    {
        $this->authorizeExport($request);
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            return UsageQuery::window($validated['from'], $validated['to']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    private function authorizeExport(Request $request): void
    {
        abort_unless($request->user()?->can(Permission::FinanceExport->value) ?? false, 403);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceExporter;
use App\Services\Finance\FinanceQuery;
use App\Services\Usage\UsageQuery;
use App\Support\Rbac\Permission;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /dashboard/finance/export?from=Y-m-d&to=Y-m-d[&plan_id=&provider=&model=&operation=&channel=&cost=&attribution=&granularity=&top=]
 *
 * The route carries `permission:finance.export`; the controller re-checks it
 * (fail closed). The window is mandatory — there is no "everything".
 */
class FinanceExportController extends Controller
{
    public function __invoke(Request $request, FinanceExporter $exporter): StreamedResponse
    {
        abort_unless($request->user()?->can(Permission::FinanceExport->value) ?? false, 403);

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'plan_id' => ['nullable', 'string', 'max:20', 'regex:/^(\d+|none)$/'],
            'provider' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:191'],
            'operation' => ['nullable', 'string', 'max:32'],
            'channel' => ['nullable', 'string', 'max:32'],
            'cost' => ['nullable', 'in:priced,unpriced'],
            'attribution' => ['nullable', 'in:subscriber,system'],
            'granularity' => ['nullable', 'in:day,month'],
            'top' => ['nullable', 'integer', 'min:1', 'max:'.FinanceQuery::TOP_LIMIT_MAX],
        ]);

        try {
            [$from, $to] = UsageQuery::window($validated['from'], $validated['to']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $filters = array_intersect_key($validated, array_flip(['plan_id', 'provider', 'model', 'operation', 'channel', 'cost', 'attribution']));

        return $exporter->stream($from, $to, $filters, (string) ($validated['granularity'] ?? 'day'), (int) ($validated['top'] ?? 10));
    }
}

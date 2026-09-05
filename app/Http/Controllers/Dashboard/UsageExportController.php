<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Usage\UsageExporter;
use App\Services\Usage\UsageQuery;
use App\Support\Rbac\Permission;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /dashboard/usage/export?from=Y-m-d&to=Y-m-d[&provider=&model=&subscriber_id=&outcome=&operation=&cost=]
 *
 * The route carries `permission:usage.export`; the controller re-checks it
 * (fail closed) and decides server-side whether cost columns are included
 * (`usage.view_costs`). The window is mandatory — there is no "everything".
 */
class UsageExportController extends Controller
{
    public function __invoke(Request $request, UsageExporter $exporter): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user?->can(Permission::UsageExport->value) ?? false, 403);

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'provider' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:191'],
            'subscriber_id' => ['nullable', 'integer', 'min:1'],
            'outcome' => ['nullable', 'string', 'max:32'],
            'operation' => ['nullable', 'string', 'max:32'],
            'cost' => ['nullable', 'in:priced,unpriced'],
        ]);

        try {
            [$from, $to] = UsageQuery::window($validated['from'], $validated['to']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $exporter->stream($from, $to, $validated, (bool) $user->can(Permission::UsageViewCosts->value));
    }
}

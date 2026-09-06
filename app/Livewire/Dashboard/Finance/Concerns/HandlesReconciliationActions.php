<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance\Concerns;

use App\Exceptions\Reconciliation\StaleReconciliationException;
use App\Support\Rbac\Permission;
use Throwable;

/** The E5.2b cost invoice / reconciliation pages: `finance.reconcile`, stale = StaleReconciliationException. See HandlesFinanceActions. */
trait HandlesReconciliationActions
{
    use HandlesFinanceActions;

    protected static function pagePermission(): Permission
    {
        return Permission::FinanceReconcile;
    }

    protected static function staleException(string $message): Throwable
    {
        return new StaleReconciliationException($message);
    }
}

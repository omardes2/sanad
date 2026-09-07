<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance\Concerns;

use App\Exceptions\Fx\StaleFxException;
use App\Support\Rbac\Permission;
use Throwable;

/** The E5.2c FX pages: `finance.fx.manage`, stale = StaleFxException. See HandlesFinanceActions. */
trait HandlesFxActions
{
    use HandlesFinanceActions;

    protected static function pagePermission(): Permission
    {
        return Permission::FinanceFxManage;
    }

    protected static function staleException(string $message): Throwable
    {
        return new StaleFxException($message);
    }
}

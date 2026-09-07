<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance\Concerns;

use App\Exceptions\Close\StaleCloseException;
use App\Support\Rbac\Permission;
use Throwable;

/**
 * The E5.2c period-close page. The PAGE is readable with `finance.view`; the
 * two write actions re-check `finance.close_period` (super_admin only) — so
 * pagePermission() is the read permission and every action additionally calls
 * authorizeClose(). Stale = StaleCloseException.
 */
trait HandlesCloseActions
{
    use HandlesFinanceActions;

    protected static function pagePermission(): Permission
    {
        return Permission::FinanceView;
    }

    protected static function staleException(string $message): Throwable
    {
        return new StaleCloseException($message);
    }

    protected function authorizeClose(): void
    {
        abort_unless(auth()->user()?->can(Permission::FinanceClosePeriod->value) ?? false, 403);
    }
}

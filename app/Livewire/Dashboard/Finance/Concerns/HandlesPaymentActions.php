<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance\Concerns;

use App\Exceptions\Payments\StalePaymentStateException;
use App\Support\Rbac\Permission;
use Throwable;

/** The E5.2a payment pages: `finance.payments.manage`, stale = StalePaymentStateException. See HandlesFinanceActions. */
trait HandlesPaymentActions
{
    use HandlesFinanceActions;

    protected static function pagePermission(): Permission
    {
        return Permission::FinancePaymentsManage;
    }

    protected static function staleException(string $message): Throwable
    {
        return new StalePaymentStateException($message);
    }
}

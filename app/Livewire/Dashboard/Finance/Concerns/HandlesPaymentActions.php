<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance\Concerns;

use App\Exceptions\Payments\PaymentConflictException;
use App\Exceptions\Payments\PaymentRuleException;
use App\Exceptions\Payments\StalePaymentStateException;
use App\Support\Payments\SubmitAttempt;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Shared write-UX rules of the E5.2a payment pages:
 *  - every action re-authorizes finance.payments.manage server-side;
 *  - one attempt key per form: generated when the attempt starts, constant
 *    across retries and re-renders, rotated ONLY after success (it is the
 *    idempotency key where the service takes one, and the duplicate-submit
 *    claim everywhere);
 *  - errors are kept apart by kind (validation · stale · conflict · rule with
 *    its rule name) and shown verbatim — never a generic success, never a
 *    stack trace;
 *  - stale = "State changed": the record and its token are refreshed, the
 *    action is NEVER re-run automatically; the user re-confirms.
 */
trait HandlesPaymentActions
{
    public const STALE_MESSAGE = 'State changed — review the refreshed record and try again';

    public const DATETIME = 'Y-m-d\TH:i';

    protected function authorizeManage(): void
    {
        abort_unless(auth()->user()?->can(Permission::FinancePaymentsManage->value) ?? false, 403);
    }

    protected static function freshKey(): string
    {
        return 'ui:'.Str::uuid()->toString();
    }

    protected static function optional(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    protected function positiveInt(string $value, string $label): int
    {
        $value = trim($value);

        if (! ctype_digit($value) || (int) $value <= 0) {
            throw new InvalidArgumentException("{$label} يجب أن يكون رقمًا صحيحًا موجبًا.");
        }

        return (int) $value;
    }

    protected function utc(string $value, string $label): CarbonImmutable
    {
        try {
            $at = CarbonImmutable::createFromFormat(self::DATETIME, trim($value), 'UTC');
        } catch (Throwable) {
            $at = false;
        }

        if ($at === false) {
            throw new InvalidArgumentException("{$label} بصيغة غير صالحة (YYYY-MM-DDTHH:MM، UTC).");
        }

        return $at;
    }

    /**
     * Run one write attempt: claim the attempt key (duplicate submit ⇒ refused
     * before any service runs), call the service, classify a refusal into its
     * own error bag and release the claim so the user can resubmit the same
     * attempt on purpose. Returns true only on success.
     *
     * The claim is a UX guard only (double-click / in-flight retry): every
     * keyed service (payment, refund, payment allocation, refund allocation)
     * is idempotent for the attempt key at the database level, so the claim
     * is released after success — a later replay of the same key returns the
     * same row, a different payload is refused as a conflict. Financial
     * correctness never depends on the cache store.
     *
     * @param  callable(): void  $action
     */
    protected function attempt(string $form, string $attemptKey, callable $action): bool
    {
        $this->authorizeManage();
        $this->resetErrorBag();
        $this->notice = null;

        if (! SubmitAttempt::claim($form, $attemptKey)) {
            $this->addError($form.'.duplicate', 'Duplicate submit — this attempt is already being processed; the result will appear when it completes.');

            return false;
        }

        try {
            $action();
        } catch (StalePaymentStateException $e) {
            SubmitAttempt::release($form, $attemptKey);
            $this->addError($form.'.stale', self::STALE_MESSAGE.' ('.$e->getMessage().')');
            $this->refreshRecord();

            return false;
        } catch (PaymentConflictException $e) {
            SubmitAttempt::release($form, $attemptKey);
            $this->addError($form.'.conflict', 'Idempotency conflict — '.$e->getMessage());

            return false;
        } catch (PaymentRuleException $e) {
            SubmitAttempt::release($form, $attemptKey);
            $this->addError($form.'.rule', $e->rule.' — '.$e->getMessage());

            return false;
        } catch (InvalidArgumentException $e) {
            SubmitAttempt::release($form, $attemptKey);
            $this->addError($form.'.validation', $e->getMessage());

            return false;
        }

        SubmitAttempt::release($form, $attemptKey);

        return true;
    }

    /** The UI's own stale pre-check: the token the form was rendered with must still be the record's token. */
    protected function assertRenderedToken(string $renderedToken, string $currentToken): void
    {
        if (! hash_equals($currentToken, $renderedToken)) {
            throw new StalePaymentStateException("المتوقع {$renderedToken}، الحالي {$currentToken}");
        }
    }

    /** Re-read the record and its state token after a stale refusal — never re-run the action. */
    abstract protected function refreshRecord(): void;
}

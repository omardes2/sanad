<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Payments\ManualPaymentInput;
use App\Data\Payments\RefundInput;
use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Exceptions\Payments\PaymentConflictException;
use App\Exceptions\Payments\PaymentRuleException;
use App\Exceptions\Payments\StalePaymentStateException;
use App\Models\CustomerPayment;
use App\Services\Payments\AllocationService;
use App\Services\Payments\CustomerPaymentService;
use App\Services\Payments\RefundService;
use App\Support\Payments\SubmitAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Testing-only probe (Phase E1): performs ONE payment operation and prints a
 * single machine-readable line. Launched concurrently by the PostgreSQL
 * concurrency test to prove idempotency and the "fully succeeds or fully
 * refused, never clipped" limit rules under real parallelism.
 *
 *  record <subscriber> <key> <amount> <currency> [received_at]
 *      → created:<id> | existing:<id> | conflict
 *  refund <payment> <key> <amount>
 *      → ok:<id> | existing:<id> | rejected:<rule> | conflict
 *  allocate <payment> <subscription_event> <amount> <key>
 *      → ok:<id> | existing:<id> | rejected:<rule> | conflict
 *  allocate-refund <refund> <payment_allocation> <amount> <key>
 *      → ok:<id> | existing:<id> | rejected:<rule> | conflict
 *  allocate-claimed <key> <payment> <subscription_event> <amount>   (UI path: SubmitAttempt claim, then the keyed service)
 *      → ok:<id> | existing:<id> | duplicate | rejected:<rule> | conflict
 *  allocate-refund-claimed <key> <refund> <payment_allocation> <amount>
 *      → likewise
 */
class PaymentProbe extends Command
{
    protected $signature = 'sanad:payment-probe {op} {args*}';

    protected $description = 'Testing only: perform one payment / refund / allocation operation and print the outcome';

    protected $hidden = true;

    public function handle(CustomerPaymentService $payments, RefundService $refunds, AllocationService $allocations): int
    {
        /** @var list<string> $args */
        $args = $this->argument('args');

        try {
            $line = match ((string) $this->argument('op')) {
                'record' => $this->record($payments, $args),
                'refund' => $this->refund($refunds, $args),
                'allocate' => self::written($allocations->allocatePayment((int) $args[0], (int) $args[1], $args[2], $args[3])),
                'allocate-refund' => self::written($allocations->allocateRefund((int) $args[0], (int) $args[1], $args[2], $args[3])),
                // E5.2a: lifecycle transitions with the caller's state token (stale ⇒ refused, never retried), and the UI submit-attempt claim.
                'dispute' => 'ok:'.$payments->transition(CustomerPayment::query()->findOrFail((int) $args[0]), CustomerPaymentEventType::Disputed, $args[1], PaymentSource::Manual, 'probe')->latest_event_id,
                'resolve' => 'ok:'.$payments->transition(CustomerPayment::query()->findOrFail((int) $args[0]), CustomerPaymentEventType::DisputeResolved, $args[1], PaymentSource::Manual, 'probe')->latest_event_id,
                'claim' => SubmitAttempt::claim('probe', $args[0]) ? 'ok:claimed' : 'duplicate',
                // The UI path: claim the attempt key (UX guard only), then the service with the SAME key as its idempotency key.
                'allocate-claimed' => SubmitAttempt::claim('allocation', $args[0]) ? self::written($allocations->allocatePayment((int) $args[1], (int) $args[2], $args[3], $args[0])) : 'duplicate',
                'allocate-refund-claimed' => SubmitAttempt::claim('refund_allocation', $args[0]) ? self::written($allocations->allocateRefund((int) $args[1], (int) $args[2], $args[3], $args[0])) : 'duplicate',
                'release' => (static function (string $scope, string $key): string {
                    SubmitAttempt::release($scope, $key);

                    return 'ok:released';
                })($args[0], $args[1]),
                default => throw new \InvalidArgumentException('Unknown op'),
            };
        } catch (PaymentRuleException $e) {
            $line = 'rejected:'.$e->rule;
        } catch (PaymentConflictException) {
            $line = 'conflict';
        } catch (StalePaymentStateException) {
            $line = 'stale';
        }

        $this->line($line);

        return self::SUCCESS;
    }

    private static function written(Model $row): string
    {
        return ($row->wasRecentlyCreated ? 'ok:' : 'existing:').$row->id;
    }

    /**
     * @param  list<string>  $args
     */
    private function record(CustomerPaymentService $payments, array $args): string
    {
        $payment = $payments->recordManual(new ManualPaymentInput(
            subscriberId: (int) $args[0],
            idempotencyKey: $args[1],
            amount: $args[2],
            currency: $args[3],
            receivedAt: isset($args[4]) ? CarbonImmutable::parse($args[4], 'UTC') : CarbonImmutable::now()->subMinute(),
            reference: 'probe',
        ));

        return ($payment->wasRecentlyCreated ? 'created:' : 'existing:').$payment->id;
    }

    /**
     * @param  list<string>  $args
     */
    private function refund(RefundService $refunds, array $args): string
    {
        $refund = $refunds->record(new RefundInput(
            customerPaymentId: (int) $args[0],
            idempotencyKey: $args[1],
            amount: $args[2],
            refundedAt: isset($args[3]) ? CarbonImmutable::parse($args[3], 'UTC') : CarbonImmutable::now()->subSecond(), // a fixed value = the same payload replayed
            reasonCode: 'probe',
        ));

        return ($refund->wasRecentlyCreated ? 'ok:' : 'existing:').$refund->id;
    }
}

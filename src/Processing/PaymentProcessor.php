<?php

declare(strict_types=1);

namespace Atannex\Billing\Processing;

use Atannex\Billing\BillingManager;
use Atannex\Billing\Data\RefundResult;
use Atannex\Billing\Data\Snapshot;
use Atannex\Billing\Enums\Capability;
use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Events\AmountMismatch;
use Atannex\Billing\Events\PaymentFailed;
use Atannex\Billing\Events\PaymentInitiated;
use Atannex\Billing\Events\PaymentRefunded;
use Atannex\Billing\Events\PaymentRequiresAction;
use Atannex\Billing\Events\PaymentStatusChanged;
use Atannex\Billing\Events\PaymentSucceeded;
use Atannex\Billing\Events\RefundFailed;
use Atannex\Billing\Exceptions\GatewayException;
use Atannex\Billing\Exceptions\GatewayUnavailable;
use Atannex\Billing\Exceptions\RefundNotAllowed;
use Atannex\Billing\Exceptions\UnsupportedCapability;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Atannex\Billing\Money\Money;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place a payment changes state. Every source (the API call,
 * webhooks, the return page, reconciliation, refunds) goes through apply(),
 * which locks the row, checks the transition and the amount, writes the
 * ledger and fires events once, after the commit. Webhook, return page and
 * poller racing on the same payment therefore cannot double-fire anything.
 */
class PaymentProcessor
{
    /** Keys never written to the ledger. */
    private const SECRET_KEYS = ['client_secret', 'notif_token', 'pay_token', 'access_token', 'pin', 'password', 'api_key', 'secret'];

    /** @var list<object> */
    private array $pending = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly BillingManager $billing,
        private readonly Dispatcher $events,
        private readonly array $config,
    ) {}

    /**
     * Send a newly created payment to its gateway, falling over to the next
     * eligible one while nothing has been accepted.
     *
     * @param  list<string>  $fallbacks
     */
    public function initiate(Payment $payment, array $fallbacks = []): Payment
    {
        $gateway = $this->billing->gateway($payment->gateway);

        try {
            $prepared = $gateway->prepare($payment);

            if ($prepared !== []) {
                $payment->forceFill([
                    'gateway_data' => [...($payment->gateway_data ?? []), ...$prepared],
                    'provider_reference' => $payment->provider_reference ?? ($prepared['reference_id'] ?? null),
                ])->save();
            }

            $snapshot = $gateway->initiate($payment);
        } catch (GatewayUnavailable $e) {
            // The provider may hold the request (we chose its id): let
            // reconciliation find out instead of risking a double charge.
            if (($prepared ?? []) !== []) {
                $this->record($payment, 'unconfirmed', 'api', ['error' => $e->getMessage()]);

                return $this->apply($payment, Snapshot::status(PaymentStatus::Processing), 'api');
            }

            if ($fallbacks !== []) {
                $next = array_shift($fallbacks);
                $this->record($payment, 'failover', 'api', ['from' => $payment->gateway, 'to' => $next, 'error' => $e->getMessage()]);
                $payment->forceFill(['gateway' => $next])->save();

                return $this->initiate($payment, $fallbacks);
            }

            $this->apply($payment, Snapshot::failed('gateway_unavailable', Str::limit($e->getMessage(), 250)), 'api');

            throw $e->withPayment($payment);
        } catch (GatewayException $e) {
            $this->apply($payment, Snapshot::failed($e->providerCode ?? 'gateway_error', Str::limit($e->getMessage(), 250), $e->body), 'api');

            throw $e->withPayment($payment);
        }

        $payment = $this->apply($payment, $snapshot, 'api');

        $this->events->dispatch(new PaymentInitiated($payment));

        return $payment;
    }

    /**
     * Ask the provider where the payment stands and apply the answer.
     */
    public function refresh(Payment $payment, string $source = 'api'): Payment
    {
        $gateway = $this->billing->gateway($payment->gateway);
        $counted = $source === 'reconcile';

        // Created but never handed to a provider (the process died mid-way).
        if ($payment->status === PaymentStatus::Pending && $payment->provider_reference === null && empty($payment->gateway_data)) {
            return $payment->created_at->lt(now()->subMinutes(5))
                ? $this->apply($payment, new Snapshot(PaymentStatus::Expired, failureCode: 'not_sent', failureMessage: 'The payment was never sent to the provider.'), $source)
                : $payment;
        }

        try {
            $snapshot = $gateway->fetch($payment);
        } catch (GatewayException $e) {
            if (! $counted) {
                throw $e;
            }

            $this->record($payment, 'check_failed', $source, ['error' => $e->getMessage()]);
            $snapshot = Snapshot::status($payment->status);
        }

        $payment = $this->apply($payment, $snapshot, $source, $counted);

        if ($payment->isOpen() && $payment->expires_at !== null && $payment->expires_at->isPast()) {
            $payment = $this->expire($payment, $source);
        }

        return $payment;
    }

    /**
     * The payer is back from the hosted page.
     */
    public function complete(Payment $payment, Request $request): Payment
    {
        if (! $payment->isOpen()) {
            return $payment;
        }

        return $this->apply($payment, $this->billing->gateway($payment->gateway)->complete($payment, $request), 'return');
    }

    public function cancel(Payment $payment): Payment
    {
        if (! $payment->isOpen()) {
            return $payment;
        }

        $gateway = $this->billing->gateway($payment->gateway);
        $snapshot = $gateway->supports(Capability::Cancel) && $payment->provider_reference !== null
            ? $gateway->cancel($payment)
            : Snapshot::status(PaymentStatus::Cancelled);

        return $this->apply($payment, $snapshot, 'api');
    }

    /**
     * Apply what the provider said. Safe to call from anywhere, any number
     * of times, concurrently.
     */
    public function apply(Payment $payment, Snapshot $snapshot, string $source, bool $countCheck = false): Payment
    {
        $connection = $payment->getConnection();

        $fresh = $connection->transaction(function () use ($payment, $snapshot, $source, $countCheck) {
            /** @var Payment $locked */
            $locked = $payment->newQuery()->lockForUpdate()->findOrFail($payment->getKey());
            $from = $locked->status;
            $to = $snapshot->status;

            $locked->gateway_data = array_filter([
                ...($locked->gateway_data ?? []),
                ...array_filter($snapshot->data, fn ($value) => $value !== null),
                'redirect_url' => $snapshot->redirectUrl ?? $locked->gatewayValue('redirect_url'),
                'client_secret' => $snapshot->clientSecret ?? $locked->gatewayValue('client_secret'),
            ], fn ($value) => $value !== null);

            if ($snapshot->providerReference !== null && $locked->provider_reference === null) {
                $locked->provider_reference = $snapshot->providerReference;
            }

            $failureCode = $snapshot->failureCode;
            $failureMessage = $snapshot->failureMessage;

            // Never mark paid on the provider's word alone if its numbers differ.
            if ($to === PaymentStatus::Succeeded && $snapshot->amount !== null && ! $snapshot->amount->equals($locked->money())) {
                $this->queue(new AmountMismatch($locked, $locked->money(), $snapshot->amount));
                $this->record($locked, 'amount_mismatch', $source, ['expected' => $locked->money(), 'reported' => $snapshot->amount]);
                $to = PaymentStatus::Failed;
                $failureCode = 'amount_mismatch';
                $failureMessage = "The provider reported {$snapshot->amount}, expected {$locked->money()}.";
            }

            if ($to !== $from && $from->canTransitionTo($to)) {
                $this->transition($locked, $from, $to, $source, $failureCode, $failureMessage, $snapshot->raw);
            } elseif ($to !== $from) {
                $this->record($locked, 'stale', $source, ['reported' => $to->value]);
            }

            if ($countCheck) {
                $locked->checks++;
            }

            $this->schedule($locked);
            $locked->save();

            return $locked;
        });

        $payment->setRawAttributes($fresh->getAttributes(), true);
        $this->flush($payment);

        return $payment;
    }

    // Refunds ------------------------------------------------------------

    public function refund(Payment $payment, ?Money $amount = null, ?string $reason = null): Refund
    {
        $gateway = $this->billing->gateway($payment->gateway);

        if (! $gateway->supports(Capability::Refunds)) {
            throw UnsupportedCapability::for($gateway->name(), Capability::Refunds);
        }

        /** @var Refund $refund */
        $refund = $payment->getConnection()->transaction(function () use ($payment, $amount, $reason, $gateway) {
            /** @var Payment $locked */
            $locked = $payment->newQuery()->lockForUpdate()->findOrFail($payment->getKey());

            if (! $locked->status->isPaid()) {
                throw new RefundNotAllowed("Payment {$locked->reference} is {$locked->status->value}; only paid payments can be refunded.");
            }

            $refundable = $locked->refundable();
            $amount ??= $refundable;

            if ($amount->currency !== $locked->currency) {
                throw new RefundNotAllowed("Refund currency {$amount->currency} differs from the payment's {$locked->currency}.");
            }

            if (! $amount->isPositive() || $amount->greaterThan($refundable)) {
                throw new RefundNotAllowed("Cannot refund {$amount}; {$refundable} is refundable on {$locked->reference}.");
            }

            if (! $amount->equals($locked->money()) && ! $gateway->supports(Capability::PartialRefunds)) {
                throw UnsupportedCapability::for($gateway->name(), Capability::PartialRefunds);
            }

            return $locked->refunds()->create([
                'reference' => 'RF-'.strtoupper((string) Str::ulid()),
                'amount' => $amount->amount,
                'currency' => $amount->currency,
                'status' => RefundStatus::Pending,
                'reason' => $reason,
            ]);
        });

        try {
            $result = $gateway->refund($payment, $refund);
        } catch (Throwable $e) {
            $this->applyRefund($refund, new RefundResult(RefundStatus::Failed, failureMessage: Str::limit($e->getMessage(), 250)), 'api');

            throw $e;
        }

        return $this->applyRefund($refund, $result, 'api');
    }

    public function refreshRefund(Refund $refund, string $source = 'reconcile'): Refund
    {
        if ($refund->status->isFinal()) {
            return $refund;
        }

        $payment = $refund->payment;

        return $this->applyRefund($refund, $this->billing->gateway($payment->gateway)->fetchRefund($payment, $refund), $source);
    }

    public function applyRefund(Refund $refund, RefundResult $result, string $source): Refund
    {
        $payment = $refund->payment;

        $payment->getConnection()->transaction(function () use ($payment, $refund, $result, $source) {
            /** @var Payment $locked */
            $locked = $payment->newQuery()->lockForUpdate()->findOrFail($payment->getKey());
            /** @var Refund $current */
            $current = $refund->newQuery()->findOrFail($refund->getKey());

            if ($current->status->isFinal()) {
                return;
            }

            $current->fill([
                'provider_reference' => $current->provider_reference ?? $result->providerReference,
                'gateway_data' => [...($current->gateway_data ?? []), ...$result->data],
                'status' => $result->status,
                'failure_message' => $result->failureMessage,
                'refunded_at' => $result->status === RefundStatus::Succeeded ? now() : null,
            ])->save();

            if ($result->status === RefundStatus::Succeeded) {
                $locked->amount_refunded += $current->amount;
                $to = $locked->amount_refunded >= $locked->amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
                $this->record($locked, 'refunded', $source, ['refund' => $current->reference, 'amount' => $current->money()]);

                if ($locked->status->canTransitionTo($to)) {
                    $this->transition($locked, $locked->status, $to, $source, null, null, $result->raw);
                }

                $locked->save();
                $this->queue(new PaymentRefunded($locked, $current));
            } elseif ($result->status === RefundStatus::Failed) {
                $this->record($locked, 'refund_failed', $source, ['refund' => $current->reference, 'error' => $result->failureMessage]);
                $this->queue(new RefundFailed($locked, $current));
            }

            $refund->setRawAttributes($current->getAttributes(), true);
            $payment->setRawAttributes($locked->getAttributes(), true);
        });

        $this->flush($payment);

        return $refund;
    }

    // Internals ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $raw
     */
    private function transition(Payment $payment, PaymentStatus $from, PaymentStatus $to, string $source, ?string $failureCode, ?string $failureMessage, array $raw): void
    {
        $payment->status = $to;

        if ($to === PaymentStatus::Succeeded) {
            $payment->paid_at ??= now();
            $payment->failure_code = null;
            $payment->failure_message = null;
        } elseif ($to->isUnsuccessful()) {
            $payment->failed_at = now();
            $payment->failure_code = $failureCode ?? $to->value;
            $payment->failure_message = $failureMessage !== null ? Str::limit($failureMessage, 250) : null;
        }

        $this->record($payment, $from->isUnsuccessful() && $to === PaymentStatus::Succeeded ? 'late_success' : 'status_changed', $source, [
            'provider' => $this->redact($raw),
        ], $from, $to);

        $this->queue(new PaymentStatusChanged($payment, $from, $to, $source));

        match (true) {
            $to === PaymentStatus::Succeeded => $this->queue(new PaymentSucceeded($payment)),
            $to->isUnsuccessful() => $this->queue(new PaymentFailed($payment)),
            $to === PaymentStatus::RequiresAction => $this->queue(new PaymentRequiresAction($payment)),
            default => null,
        };
    }

    private function expire(Payment $payment, string $source): Payment
    {
        $gateway = $this->billing->gateway($payment->gateway);

        if ($gateway->supports(Capability::Cancel) && $payment->provider_reference !== null) {
            try {
                $gateway->cancel($payment);
            } catch (Throwable) {
                // Expiring locally is what matters; a late success still lands.
            }
        }

        return $this->apply($payment, new Snapshot(PaymentStatus::Expired, failureCode: 'expired', failureMessage: 'The payment was not completed in time.'), $source);
    }

    /**
     * Next provider check on the back-off; nothing once settled.
     */
    private function schedule(Payment $payment): void
    {
        if (! $payment->status->isOpen()) {
            $payment->next_check_at = null;

            return;
        }

        if ($payment->expires_at === null) {
            $minutes = (int) ($this->billing->gateway($payment->gateway)->config('expires_after') ?? 60);
            $payment->expires_at = now()->addMinutes(max(1, $minutes));
        }

        $backoff = array_values(array_map('intval', (array) ($this->config['reconcile']['backoff'] ?? [10, 30, 60, 300])));
        $delay = $backoff[min($payment->checks, count($backoff) - 1)] ?? 60;
        $next = now()->addSeconds($delay);

        // One last look right at the expiry, so the payment is closed on time.
        $payment->next_check_at = $next->gt($payment->expires_at) ? $payment->expires_at->copy() : $next;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(Payment $payment, string $type, string $source, array $data = [], ?PaymentStatus $from = null, ?PaymentStatus $to = null): void
    {
        $payment->events()->create([
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'source' => $source,
            'data' => array_filter($data, fn ($value) => $value !== null && $value !== []) ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->redact($value, $depth + 1);
            } else {
                $clean[$key] = is_string($value) ? Str::limit($value, 500) : $value;
            }
        }

        return $clean;
    }

    private function queue(object $event): void
    {
        $this->pending[] = $event;
    }

    /**
     * Fire queued events once the outermost transaction commits.
     */
    private function flush(Payment $payment): void
    {
        $events = $this->pending;
        $this->pending = [];

        if ($events === []) {
            return;
        }

        $dispatch = function () use ($events) {
            foreach ($events as $event) {
                $this->events->dispatch($event);
            }
        };

        $connection = $payment->getConnection();

        $connection->transactionLevel() > 0 ? $connection->afterCommit($dispatch) : $dispatch();
    }
}

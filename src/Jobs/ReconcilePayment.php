<?php

declare(strict_types=1);

namespace Atannex\Billing\Jobs;

use Atannex\Billing\Models\Payment;
use Atannex\Billing\Processing\PaymentProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Checks one open (or locally closed, still watched) payment with its provider.
 */
class ReconcilePayment implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $paymentId) {}

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public function handle(PaymentProcessor $processor): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment !== null && ($payment->isOpen() || $payment->failed())) {
            $processor->refresh($payment, 'reconcile');
        }
    }
}

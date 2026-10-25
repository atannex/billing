<?php

declare(strict_types=1);

namespace Atannex\Billing\Events;

use Atannex\Billing\Models\Payment;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the provider for the first time. $payment->redirectUrl() is ready.
 */
class PaymentInitiated
{
    use SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}

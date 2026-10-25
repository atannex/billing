<?php

declare(strict_types=1);

namespace Atannex\Billing\Events;

use Atannex\Billing\Models\Payment;
use Illuminate\Queue\SerializesModels;

/**
 * Failed, cancelled or expired; $payment->status says which.
 */
class PaymentFailed
{
    use SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}

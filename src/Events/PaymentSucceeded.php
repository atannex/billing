<?php

declare(strict_types=1);

namespace Atannex\Billing\Events;

use Atannex\Billing\Models\Payment;
use Illuminate\Queue\SerializesModels;

/**
 * The provider confirmed the money (amount and currency checked).
 */
class PaymentSucceeded
{
    use SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}

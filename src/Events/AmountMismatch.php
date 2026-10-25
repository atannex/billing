<?php

declare(strict_types=1);

namespace Atannex\Billing\Events;

use Atannex\Billing\Models\Payment;
use Atannex\Billing\Money\Money;
use Illuminate\Queue\SerializesModels;

/**
 * The provider reported success for a different amount or currency than
 * the payment asked for. The payment is not marked paid; look into it.
 */
class AmountMismatch
{
    use SerializesModels;

    public function __construct(public readonly Payment $payment, public readonly Money $expected, public readonly Money $reported) {}
}

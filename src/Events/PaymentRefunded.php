<?php

declare(strict_types=1);

namespace Atannex\Billing\Events;

use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Illuminate\Queue\SerializesModels;

/**
 * A refund was confirmed by the provider.
 */
class PaymentRefunded
{
    use SerializesModels;

    public function __construct(public readonly Payment $payment, public readonly Refund $refund) {}
}

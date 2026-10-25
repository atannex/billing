<?php

declare(strict_types=1);

namespace Atannex\Billing\Events;

use Atannex\Billing\Models\Payment;
use Illuminate\Queue\SerializesModels;

/**
 * The payer must act: open $payment->redirectUrl() or confirm on your page.
 */
class PaymentRequiresAction
{
    use SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}

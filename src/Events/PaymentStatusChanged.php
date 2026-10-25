<?php

declare(strict_types=1);

namespace Atannex\Billing\Events;

use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Models\Payment;
use Illuminate\Queue\SerializesModels;

/**
 * Every transition, once, after the database commit. $source is api,
 * webhook, return, reconcile or refund.
 */
class PaymentStatusChanged
{
    use SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentStatus $from,
        public readonly PaymentStatus $to,
        public readonly string $source,
    ) {}
}

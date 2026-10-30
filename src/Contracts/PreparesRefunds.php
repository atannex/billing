<?php

declare(strict_types=1);

namespace Atannex\Billing\Contracts;

use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;

/**
 * A gateway that picks the id of a refund itself. The processor stores what
 * prepareRefund() returns on the refund before calling refund(), so a
 * refund whose request timed out can still be looked up and is never
 * failed (and its amount freed) while the provider may have paid it out.
 * A `reference_id` key also becomes the refund's provider_reference.
 */
interface PreparesRefunds
{
    /**
     * @return array<string, mixed>
     */
    public function prepareRefund(Payment $payment, Refund $refund): array;
}

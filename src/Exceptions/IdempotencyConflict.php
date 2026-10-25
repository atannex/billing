<?php

declare(strict_types=1);

namespace Atannex\Billing\Exceptions;

class IdempotencyConflict extends BillingException
{
    public static function for(string $key): self
    {
        return new self("Idempotency key [{$key}] was already used for a different payment.");
    }
}

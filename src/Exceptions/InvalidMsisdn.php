<?php

declare(strict_types=1);

namespace Atannex\Billing\Exceptions;

class InvalidMsisdn extends BillingException
{
    public static function for(string $number, ?string $reason = null): self
    {
        return new self("[{$number}] is not a valid phone number".($reason ? " ({$reason})" : '').'.');
    }
}

<?php

declare(strict_types=1);

namespace Atannex\Billing\Exceptions;

class NoEligibleGateway extends BillingException
{
    public static function for(string $currency, ?string $method, ?string $operator): self
    {
        $details = array_filter(['currency '.$currency, $method ? 'method '.$method : null, $operator ? 'operator '.$operator : null]);

        return new self('No enabled gateway accepts '.implode(', ', $details).'.');
    }
}

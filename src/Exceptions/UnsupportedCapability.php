<?php

declare(strict_types=1);

namespace Atannex\Billing\Exceptions;

use Atannex\Billing\Enums\Capability;

class UnsupportedCapability extends BillingException
{
    public static function for(string $gateway, Capability $capability): self
    {
        return new self("The [{$gateway}] gateway does not support {$capability->value}.");
    }
}

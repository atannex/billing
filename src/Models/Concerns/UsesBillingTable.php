<?php

declare(strict_types=1);

namespace Atannex\Billing\Models\Concerns;

trait UsesBillingTable
{
    public function getTable(): string
    {
        return (string) config('billing.table_prefix', 'billing_').static::BASE_TABLE;
    }
}

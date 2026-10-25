<?php

declare(strict_types=1);

use Atannex\Billing\BillingManager;

if (! function_exists('billing')) {
    function billing(): BillingManager
    {
        return app(BillingManager::class);
    }
}

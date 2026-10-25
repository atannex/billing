<?php

declare(strict_types=1);

namespace Atannex\Billing\Facades;

use Atannex\Billing\BillingManager;
use Atannex\Billing\Testing\BillingFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Atannex\Billing\PendingPayment amount(int|float|string|\Atannex\Billing\Money\Money $amount, ?string $currency = null)
 * @method static \Atannex\Billing\PendingPayment minor(int $amount, ?string $currency = null)
 * @method static \Atannex\Billing\Models\Payment|null find(string $reference)
 * @method static \Atannex\Billing\Models\Payment findOrFail(string $reference)
 * @method static \Atannex\Billing\Models\Payment refresh(\Atannex\Billing\Models\Payment|string $payment)
 * @method static \Atannex\Billing\Models\Payment complete(\Atannex\Billing\Models\Payment|string $payment, \Illuminate\Http\Request $request)
 * @method static \Atannex\Billing\Models\Payment cancel(\Atannex\Billing\Models\Payment|string $payment)
 * @method static \Atannex\Billing\Models\Refund refund(\Atannex\Billing\Models\Payment|string $payment, int|float|string|\Atannex\Billing\Money\Money|null $amount = null, ?string $reason = null)
 * @method static \Atannex\Billing\Contracts\Gateway gateway(?string $name = null)
 * @method static BillingManager extend(string $driver, \Closure $factory)
 * @method static list<string> gateways()
 * @method static list<string> enabled()
 * @method static \Atannex\Billing\Processing\GatewayRouter router()
 * @method static \Atannex\Billing\Processing\PaymentProcessor processor()
 * @method static bool isFaked()
 *
 * @see BillingManager
 */
class Billing extends Facade
{
    public static function fake(): BillingFake
    {
        return static::getFacadeRoot()->fake();
    }

    protected static function getFacadeAccessor(): string
    {
        return BillingManager::class;
    }
}

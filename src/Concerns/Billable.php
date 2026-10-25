<?php

declare(strict_types=1);

namespace Atannex\Billing\Concerns;

use Atannex\Billing\BillingManager;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Money\Money;
use Atannex\Billing\PendingPayment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * For the model that pays (usually User).
 *
 *     $user->charge(5000)->for($order)->charge();
 *     $user->payments()->paid()->get();
 *
 * Override billingName(), billingEmail() or billingPhone() when the
 * attributes are named differently.
 */
trait Billable
{
    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'customer')->latest('id');
    }

    public function charge(int|float|string|Money $amount, ?string $currency = null): PendingPayment
    {
        return app(BillingManager::class)->amount($amount, $currency)->by($this);
    }

    public function billingName(): ?string
    {
        return $this->getAttribute('name');
    }

    public function billingEmail(): ?string
    {
        return $this->getAttribute('email');
    }

    public function billingPhone(): ?string
    {
        return $this->getAttribute('phone');
    }
}

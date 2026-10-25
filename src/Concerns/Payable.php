<?php

declare(strict_types=1);

namespace Atannex\Billing\Concerns;

use Atannex\Billing\BillingManager;
use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Money\Money;
use Atannex\Billing\PendingPayment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * For what is paid for (an order, an invoice, a ticket).
 *
 *     $order->pay(5000)->phone('677123456')->charge();
 *     $order->isPaid();            // any successful, not fully refunded payment
 *     $order->amountPaid('XAF');   // net of refunds
 */
trait Payable
{
    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->latest('id');
    }

    /** @return MorphOne<Payment, $this> */
    public function latestPayment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable')->latestOfMany();
    }

    public function pay(int|float|string|Money $amount, ?string $currency = null): PendingPayment
    {
        return app(BillingManager::class)->amount($amount, $currency)->for($this);
    }

    public function isPaid(): bool
    {
        return $this->payments()->status(PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded)->exists();
    }

    public function amountPaid(?string $currency = null): Money
    {
        $currency ??= app(BillingManager::class)->defaultCurrency();

        $totals = $this->payments()->paid()->where('currency', strtoupper($currency))
            ->toBase()
            ->selectRaw('coalesce(sum(amount), 0) as paid, coalesce(sum(amount_refunded), 0) as refunded')
            ->first();

        return Money::minor((int) ($totals->paid ?? 0) - (int) ($totals->refunded ?? 0), $currency);
    }
}

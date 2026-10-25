<?php

declare(strict_types=1);

namespace Atannex\Billing;

use Atannex\Billing\Concerns\Billable;
use Atannex\Billing\Enums\Capability;
use Atannex\Billing\Enums\PaymentMethod;
use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Exceptions\IdempotencyConflict;
use Atannex\Billing\Exceptions\InvalidMsisdn;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Money\Money;
use Atannex\Billing\Support\Msisdn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;

/**
 * A payment being described, sent by charge().
 *
 *     Billing::amount(5000)
 *         ->for($order)                 // what is being paid for
 *         ->by($user)                   // who pays (fills name, email, phone)
 *         ->phone('677 12 34 56')       // routes to MTN MoMo or Orange Money
 *         ->description('Annual subscription')
 *         ->returnTo(route('orders.show', $order))
 *         ->idempotencyKey("order-{$order->id}")
 *         ->charge();
 */
class PendingPayment
{
    use Conditionable;

    private ?Model $payable = null;

    private ?Model $customer = null;

    private ?string $name = null;

    private ?string $email = null;

    private ?string $phone = null;

    private ?string $country = null;

    private ?string $description = null;

    private ?string $method = null;

    private ?string $gateway = null;

    private ?string $returnUrl = null;

    private ?string $cancelUrl = null;

    private ?string $idempotencyKey = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    public function __construct(private readonly BillingManager $billing, private Money $money) {}

    public function currency(string $currency): static
    {
        $this->money = Money::of($this->money->decimal(), $currency);

        return $this;
    }

    /** The model being paid for (an order, an invoice, a subscription). */
    public function for(Model $payable): static
    {
        $this->payable = $payable;

        return $this;
    }

    /** The paying model; Billable models also fill name, email and phone. */
    public function by(Model $customer): static
    {
        $this->customer = $customer;

        if (in_array(Billable::class, class_uses_recursive($customer), true)) {
            /** @var Model&Billable $customer */
            $this->name ??= $customer->billingName();
            $this->email ??= $customer->billingEmail();
            $this->phone ??= $customer->billingPhone();
        }

        return $this;
    }

    public function name(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function email(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /** A mobile number in any common form; also drives operator routing. */
    public function phone(?string $phone, ?string $country = null): static
    {
        $this->phone = $phone;
        $this->country = $country ?? $this->country;

        return $this;
    }

    public function description(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /** card, mobile_money, wallet or bank: narrows routing. */
    public function method(PaymentMethod|string|null $method): static
    {
        $this->method = $method instanceof PaymentMethod ? $method->value : $method;

        return $this;
    }

    /** Use exactly this gateway, skipping routing and failover. */
    public function via(string $gateway): static
    {
        $this->gateway = $gateway;

        return $this;
    }

    /** Where to send the payer after the provider's page. */
    public function returnTo(?string $url, ?string $cancelUrl = null): static
    {
        $this->returnUrl = $url;
        $this->cancelUrl = $cancelUrl ?? $this->cancelUrl;

        return $this;
    }

    /**
     * Charging twice with the same key returns the first payment instead of
     * charging again (double clicks, retried jobs).
     */
    public function idempotencyKey(?string $key): static
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): static
    {
        $this->metadata = [...$this->metadata, ...$metadata];

        return $this;
    }

    public function money(): Money
    {
        return $this->money;
    }

    /**
     * The gateways this payment would go to, best first.
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        if ($this->gateway !== null) {
            return [$this->gateway];
        }

        if (! ($this->billing->config('routing.enabled', true))) {
            return [(string) $this->billing->config('gateway')];
        }

        return $this->billing->router()->candidates($this->money->currency, $this->method, $this->msisdn());
    }

    public function charge(): Payment
    {
        if (! $this->money->isPositive()) {
            throw new \InvalidArgumentException('A payment amount must be positive.');
        }

        if ($this->idempotencyKey !== null && ($existing = $this->existing())) {
            return $existing;
        }

        $msisdn = $this->msisdn();
        $candidates = $this->candidates();
        $gateway = array_shift($candidates);
        $fallbacks = $this->gateway === null && $this->billing->config('routing.failover', false) ? $candidates : [];

        if ($msisdn === null && $this->billing->gateway($gateway)->supports(Capability::Push)) {
            throw InvalidMsisdn::for((string) ($this->phone ?? ''), "[{$gateway}] needs the payer's mobile number");
        }

        try {
            $payment = Payment::query()->create([
                'reference' => $this->billing->config('reference_prefix', 'PAY-').strtoupper((string) Str::ulid()),
                'gateway' => $gateway,
                'method' => $this->method,
                'status' => PaymentStatus::Pending,
                'amount' => $this->money->amount,
                'currency' => $this->money->currency,
                'payable_type' => $this->payable?->getMorphClass(),
                'payable_id' => $this->payable?->getKey(),
                'customer_type' => $this->customer?->getMorphClass(),
                'customer_id' => $this->customer?->getKey(),
                'customer_name' => $this->name,
                'customer_email' => $this->email,
                'customer_phone' => $msisdn?->international() ?? $this->phone,
                'description' => $this->description !== null ? Str::limit($this->description, 250) : null,
                'idempotency_key' => $this->idempotencyKey,
                'return_url' => $this->returnUrl,
                'cancel_url' => $this->cancelUrl,
                'metadata' => $this->metadata ?: null,
                // Safety net for a process that dies before the provider answers.
                'next_check_at' => now()->addMinutes(5),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if ($this->idempotencyKey !== null && ($existing = $this->existing())) {
                return $existing;
            }

            throw $e;
        }

        return $this->billing->processor()->initiate($payment, $fallbacks);
    }

    private function existing(): ?Payment
    {
        $payment = Payment::query()->where('idempotency_key', $this->idempotencyKey)->first();

        if ($payment !== null && ! $payment->money()->equals($this->money)) {
            throw IdempotencyConflict::for((string) $this->idempotencyKey);
        }

        return $payment;
    }

    private function msisdn(): ?Msisdn
    {
        // A malformed number only matters to gateways that need one, which
        // then refuse the payment with InvalidMsisdn.
        return Msisdn::tryParse($this->phone, $this->country ?? (string) $this->billing->config('country', 'CM'));
    }
}

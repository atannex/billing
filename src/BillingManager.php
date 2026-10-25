<?php

declare(strict_types=1);

namespace Atannex\Billing;

use Atannex\Billing\Contracts\Gateway;
use Atannex\Billing\Exceptions\BillingException;
use Atannex\Billing\Gateways\FakeGateway;
use Atannex\Billing\Gateways\FlutterwaveGateway;
use Atannex\Billing\Gateways\MtnMomoGateway;
use Atannex\Billing\Gateways\OrangeMoneyGateway;
use Atannex\Billing\Gateways\PayPalGateway;
use Atannex\Billing\Gateways\StripeGateway;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Atannex\Billing\Money\Money;
use Atannex\Billing\Processing\GatewayRouter;
use Atannex\Billing\Processing\PaymentProcessor;
use Atannex\Billing\Testing\BillingFake;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;

/**
 * Entry point behind the Billing facade.
 *
 *     $payment = Billing::amount(5000)->phone('677123456')->for($order)->charge();
 *     Billing::refund($payment, Money::of(2000, 'XAF'), 'Damaged item');
 *     Billing::gateway('mtn_momo')->isActive('237677123456');
 */
class BillingManager
{
    /** @var array<string, Gateway> */
    private array $gateways = [];

    /** @var array<string, Closure(string, array<string, mixed>, Container): Gateway> */
    private array $extensions = [];

    private ?BillingFake $fake = null;

    public function __construct(private readonly Container $app) {}

    // Payments -----------------------------------------------------------

    /**
     * Start a payment in major units: amount(5000) is 5000 XAF,
     * amount('12.50', 'USD') is $12.50.
     */
    public function amount(int|float|string|Money $amount, ?string $currency = null): PendingPayment
    {
        $money = $amount instanceof Money ? $amount : Money::of($amount, $currency ?? $this->defaultCurrency());

        return new PendingPayment($this, $money);
    }

    /** Start a payment in minor units (cents). */
    public function minor(int $amount, ?string $currency = null): PendingPayment
    {
        return new PendingPayment($this, Money::minor($amount, $currency ?? $this->defaultCurrency()));
    }

    public function find(string $reference): ?Payment
    {
        return Payment::query()->where('reference', $reference)->first();
    }

    public function findOrFail(string $reference): Payment
    {
        return Payment::query()->where('reference', $reference)->firstOrFail();
    }

    /** Ask the provider for the latest status now. */
    public function refresh(Payment|string $payment): Payment
    {
        return $this->processor()->refresh($this->resolve($payment));
    }

    public function complete(Payment|string $payment, Request $request): Payment
    {
        return $this->processor()->complete($this->resolve($payment), $request);
    }

    public function cancel(Payment|string $payment): Payment
    {
        return $this->processor()->cancel($this->resolve($payment));
    }

    /**
     * Refund all that is left, or part of it. $amount in major units of the
     * payment's currency, or Money.
     */
    public function refund(Payment|string $payment, int|float|string|Money|null $amount = null, ?string $reason = null): Refund
    {
        $payment = $this->resolve($payment);

        if ($amount !== null && ! $amount instanceof Money) {
            $amount = Money::of($amount, $payment->currency);
        }

        return $this->processor()->refund($payment, $amount, $reason);
    }

    // Gateways -----------------------------------------------------------

    public function gateway(?string $name = null): Gateway
    {
        $name ??= (string) $this->config('gateway');

        return $this->gateways[$name] ??= $this->build($name);
    }

    /**
     * Register a driver: Billing::extend('cinetpay', fn (string $name, array $config, $app) => new CinetPayGateway(…)).
     *
     * @param  Closure(string, array<string, mixed>, Container): Gateway  $factory
     */
    public function extend(string $driver, Closure $factory): static
    {
        $this->extensions[$driver] = $factory;
        $this->gateways = [];

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function gatewayConfig(string $name): ?array
    {
        $config = $this->config("gateways.{$name}");

        return is_array($config) ? $config : null;
    }

    /**
     * @return list<string> configured gateway names
     */
    public function gateways(): array
    {
        return array_keys((array) $this->config('gateways', []));
    }

    /**
     * @return list<string> gateways that routing may pick
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->gateways(), fn (string $name) => (bool) ($this->gatewayConfig($name)['enabled'] ?? true)));
    }

    public function router(): GatewayRouter
    {
        return new GatewayRouter($this, (array) $this->config(null, []));
    }

    public function processor(): PaymentProcessor
    {
        return $this->app->make(PaymentProcessor::class);
    }

    /** Forget resolved gateways (after changing config at runtime). */
    public function forgetGateways(): static
    {
        $this->gateways = [];

        return $this;
    }

    // Testing ------------------------------------------------------------

    /**
     * Swap every gateway for a scripted fake that keeps its configured
     * name, currencies, methods and operators.
     */
    public function fake(): BillingFake
    {
        $this->gateways = [];

        return $this->fake = new BillingFake;
    }

    public function isFaked(): bool
    {
        return $this->fake !== null;
    }

    // Internals ----------------------------------------------------------

    public function defaultCurrency(): string
    {
        return (string) $this->config('currency', 'XAF');
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        $config = $this->app['config'];

        return $key === null ? $config->get('billing', $default) : $config->get("billing.{$key}", $default);
    }

    private function resolve(Payment|string $payment): Payment
    {
        return $payment instanceof Payment ? $payment : $this->findOrFail($payment);
    }

    private function build(string $name): Gateway
    {
        $config = $this->gatewayConfig($name);
        $http = $this->app->make(Http::class);
        $cache = $this->app->make(CacheFactory::class)->store($this->config('cache_store'));

        if ($this->fake !== null) {
            return new FakeGateway($name, $config ?? [], $http, $cache, $this->fake);
        }

        if ($config === null) {
            throw new BillingException("Billing gateway [{$name}] is not configured.");
        }

        $driver = (string) ($config['driver'] ?? $name);

        if (isset($this->extensions[$driver])) {
            return ($this->extensions[$driver])($name, $config, $this->app);
        }

        return match ($driver) {
            'stripe' => new StripeGateway($name, $config, $http, $cache),
            'mtn_momo' => new MtnMomoGateway($name, $config, $http, $cache),
            'orange_money' => new OrangeMoneyGateway($name, $config, $http, $cache),
            'paypal' => new PayPalGateway($name, $config, $http, $cache),
            'flutterwave' => new FlutterwaveGateway($name, $config, $http, $cache),
            'fake' => new FakeGateway($name, $config, $http, $cache),
            default => throw new BillingException("Billing driver [{$driver}] for gateway [{$name}] is not supported."),
        };
    }
}

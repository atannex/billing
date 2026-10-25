<?php

declare(strict_types=1);

namespace Atannex\Billing\Processing;

use Atannex\Billing\BillingManager;
use Atannex\Billing\Enums\Capability;
use Atannex\Billing\Exceptions\NoEligibleGateway;
use Atannex\Billing\Support\Msisdn;

/**
 * Picks gateways for a payment: the ones in routing.order that are
 * enabled, take the currency and method, and serve the payer's operator.
 * MTN numbers land on MTN MoMo, Orange numbers on Orange Money, a USD card
 * payment on Stripe, without the caller choosing.
 */
class GatewayRouter
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly BillingManager $billing,
        private readonly array $config,
    ) {}

    /**
     * @return list<string> eligible gateway names, best first
     */
    public function candidates(string $currency, ?string $method = null, ?Msisdn $msisdn = null): array
    {
        $order = (array) ($this->config['routing']['order'] ?? []);
        $default = (string) ($this->config['gateway'] ?? '');

        if ($default !== '' && ! in_array($default, $order, true)) {
            $order[] = $default;
        }

        $eligible = array_values(array_filter(
            array_unique($order),
            fn (string $name) => $this->eligible($name, $currency, $method, $msisdn),
        ));

        if ($eligible === []) {
            throw NoEligibleGateway::for($currency, $method, $msisdn?->operator());
        }

        return $eligible;
    }

    public function eligible(string $name, string $currency, ?string $method = null, ?Msisdn $msisdn = null): bool
    {
        $config = $this->billing->gatewayConfig($name);

        if ($config === null || ! ($config['enabled'] ?? true)) {
            return false;
        }

        $gateway = $this->billing->gateway($name);

        if (! $gateway->accepts($currency)) {
            return false;
        }

        $methods = (array) ($config['methods'] ?? []);

        if ($method !== null && $methods !== [] && ! in_array($method, $methods, true)) {
            return false;
        }

        $operators = array_map('strtolower', (array) ($config['operators'] ?? []));

        if ($operators !== []) {
            return $msisdn !== null && in_array($msisdn->operator(), $operators, true);
        }

        return ! $gateway->supports(Capability::Push) || $msisdn !== null;
    }
}

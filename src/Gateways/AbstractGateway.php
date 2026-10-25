<?php

declare(strict_types=1);

namespace Atannex\Billing\Gateways;

use Atannex\Billing\Contracts\Gateway as GatewayContract;
use Atannex\Billing\Data\RefundResult;
use Atannex\Billing\Data\Snapshot;
use Atannex\Billing\Data\WebhookEvent;
use Atannex\Billing\Enums\Capability;
use Atannex\Billing\Exceptions\GatewayException;
use Atannex\Billing\Exceptions\GatewayUnavailable;
use Atannex\Billing\Exceptions\UnsupportedCapability;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Atannex\Billing\Money\Currency;
use Atannex\Billing\Money\Money;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Shared plumbing for drivers: configuration, HTTP with one error policy,
 * cached OAuth tokens and currency mapping.
 */
abstract class AbstractGateway implements GatewayContract
{
    /** @var list<Capability> */
    protected array $capabilities = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly string $name,
        protected readonly array $config,
        protected readonly Http $http,
        protected readonly Cache $cache,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function accepts(string $currency): bool
    {
        $currencies = array_map('strtoupper', (array) ($this->config['currencies'] ?? []));

        return $currencies === [] || in_array(strtoupper($currency), $currencies, true);
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->config : data_get($this->config, $key, $default);
    }

    public function prepare(Payment $payment): array
    {
        return [];
    }

    public function complete(Payment $payment, Request $request): Snapshot
    {
        return $this->fetch($payment);
    }

    public function cancel(Payment $payment): Snapshot
    {
        throw UnsupportedCapability::for($this->name, Capability::Cancel);
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        throw UnsupportedCapability::for($this->name, Capability::Refunds);
    }

    public function fetchRefund(Payment $payment, Refund $refund): RefundResult
    {
        throw UnsupportedCapability::for($this->name, Capability::Refunds);
    }

    public function verifyWebhook(Request $request, ?Payment $payment): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw UnsupportedCapability::for($this->name, Capability::Webhooks);
    }

    // HTTP ---------------------------------------------------------------

    protected function request(): PendingRequest
    {
        return $this->http->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 10));
    }

    /**
     * Run a request with billing's error policy: unreachable or 5xx →
     * GatewayUnavailable, any other failure → GatewayException carrying the
     * provider's own message.
     *
     * @param  Closure(PendingRequest): Response  $send
     */
    protected function send(Closure $send, ?PendingRequest $request = null): Response
    {
        try {
            $response = $send($request ?? $this->request());
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable("[{$this->name}] unreachable: {$e->getMessage()}", $this->name, previous: $e);
        }

        if ($response->serverError()) {
            throw GatewayUnavailable::fromResponse($this->name, $response, $this->errorMessage($response));
        }

        if ($response->failed()) {
            throw GatewayException::fromResponse($this->name, $response, $this->errorMessage($response), $this->errorCode($response));
        }

        return $response;
    }

    /** The provider's error text, for exceptions and failure_message. */
    protected function errorMessage(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return trim(substr($response->body(), 0, 200)) ?: null;
        }

        foreach (['error.message', 'message', 'error_description', 'description', 'detail', 'reason', 'error'] as $key) {
            $value = data_get($body, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function errorCode(Response $response): ?string
    {
        foreach (['error.code', 'code', 'name', 'error'] as $key) {
            $value = data_get($response->json() ?? [], $key);

            if (is_scalar($value) && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * An OAuth token cached until shortly before it expires.
     *
     * @param  Closure(): array{0: string, 1: int}  $fetch  returns [token, lifetime in seconds]
     */
    protected function token(string $scope, Closure $fetch): string
    {
        $key = $this->tokenKey($scope);
        $token = $this->cache->get($key);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        [$token, $ttl] = $fetch();
        $this->cache->put($key, $token, max(30, $ttl - 60));

        return $token;
    }

    protected function forgetToken(string $scope): void
    {
        $this->cache->forget($this->tokenKey($scope));
    }

    private function tokenKey(string $scope): string
    {
        return 'billing:'.$this->name.':'.$scope.':'.md5(serialize($this->config));
    }

    // Money --------------------------------------------------------------

    /** The code sent to the provider (XAF → EUR in the MTN sandbox). */
    protected function providerCurrency(string $currency): string
    {
        return strtoupper((string) (($this->config['currency_map'] ?? [])[strtoupper($currency)] ?? $currency));
    }

    /** A provider's currency code read back as the payment's own. */
    protected function ownCurrency(string $providerCurrency, string $expected): string
    {
        $providerCurrency = strtoupper($providerCurrency);

        return $this->providerCurrency($expected) === $providerCurrency ? strtoupper($expected) : $providerCurrency;
    }

    /** "5000", "12.50": the major-unit amount most APIs want. */
    protected function decimalAmount(Payment|Refund $model): string
    {
        return $model->money()->decimal();
    }

    /**
     * Money from a provider's major-unit amount, in the payment's terms.
     */
    protected function moneyFromDecimal(int|float|string|null $amount, ?string $providerCurrency, Payment $payment): ?Money
    {
        if ($amount === null || $amount === '' || $providerCurrency === null || $providerCurrency === '') {
            return null;
        }

        $currency = $this->ownCurrency($providerCurrency, $payment->currency);
        $decimals = Currency::decimals($currency);

        // Providers send "5000.0" for zero-decimal currencies; round to the unit.
        return Money::of(number_format((float) $amount, $decimals, '.', ''), $currency);
    }

    protected function moneyFromMinor(?int $amount, ?string $providerCurrency, Payment $payment): ?Money
    {
        if ($amount === null || $providerCurrency === null || $providerCurrency === '') {
            return null;
        }

        return Money::minor($amount, $this->ownCurrency($providerCurrency, $payment->currency));
    }

    protected function payloadId(Request $request): string
    {
        return 'sha1:'.sha1($request->getContent());
    }
}

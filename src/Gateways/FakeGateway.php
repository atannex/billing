<?php

declare(strict_types=1);

namespace Atannex\Billing\Gateways;

use Atannex\Billing\Data\RefundResult;
use Atannex\Billing\Data\Snapshot;
use Atannex\Billing\Data\WebhookEvent;
use Atannex\Billing\Enums\Capability;
use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Exceptions\GatewayUnavailable;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Atannex\Billing\Support\Urls;
use Atannex\Billing\Testing\BillingFake;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A provider that answers from a script (BillingFake). As the `fake` driver
 * it lets local development run the whole flow without credentials: a
 * redirect goes straight to the return page and the payment then succeeds.
 */
class FakeGateway extends AbstractGateway
{
    protected array $capabilities = [
        Capability::Redirect,
        Capability::Refunds,
        Capability::PartialRefunds,
        Capability::Cancel,
        Capability::Webhooks,
    ];

    private readonly BillingFake $fake;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(string $name, array $config, Http $http, Cache $cache, ?BillingFake $fake = null)
    {
        parent::__construct($name, $config, $http, $cache);

        $this->fake = $fake ?? $this->standalone($config);
    }

    public function capabilities(): array
    {
        // A fake standing in for a push gateway behaves like one in routing.
        return ($this->config['operators'] ?? []) !== []
            ? [...$this->capabilities, Capability::Push]
            : $this->capabilities;
    }

    public function initiate(Payment $payment): Snapshot
    {
        if ($this->fake->unavailable) {
            throw new GatewayUnavailable("[{$this->name}] unreachable (fake)", $this->name);
        }

        $this->fake->charged[] = $payment->reference;
        $this->fake->provider[$payment->reference] = $this->fake->outcome;
        $id = 'fake_'.Str::lower(Str::random(16));

        return new Snapshot(
            $this->fake->outcome,
            providerReference: $id,
            redirectUrl: $this->fake->outcome === PaymentStatus::RequiresAction ? Urls::return($payment) : null,
            amount: $this->fake->outcome === PaymentStatus::Succeeded ? $payment->money() : null,
            failureCode: $this->fake->outcome === PaymentStatus::Failed ? $this->fake->failureCode : null,
            failureMessage: $this->fake->outcome === PaymentStatus::Failed ? $this->fake->failureMessage : null,
        );
    }

    public function fetch(Payment $payment): Snapshot
    {
        if ($this->fake->unavailable) {
            throw new GatewayUnavailable("[{$this->name}] unreachable (fake)", $this->name);
        }

        $status = $this->fake->provider[$payment->reference] ?? $payment->status;

        if ($status->isOpen() && $this->fake->eventually !== null) {
            $status = $this->fake->provider[$payment->reference] = $this->fake->eventually;
        }

        return new Snapshot(
            $status,
            amount: $status === PaymentStatus::Succeeded ? $payment->money() : null,
            failureCode: $status === PaymentStatus::Failed ? ($this->fake->failureCode ?? 'declined') : null,
            failureMessage: $status === PaymentStatus::Failed ? $this->fake->failureMessage : null,
        );
    }

    public function cancel(Payment $payment): Snapshot
    {
        $this->fake->provider[$payment->reference] = PaymentStatus::Cancelled;

        return Snapshot::status(PaymentStatus::Cancelled);
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        $this->fake->refunded[] = $refund->reference;

        return new RefundResult($this->fake->refundOutcome, 'fake_re_'.Str::lower(Str::random(12)));
    }

    public function fetchRefund(Payment $payment, Refund $refund): RefundResult
    {
        return new RefundResult($this->fake->refundOutcome, $refund->provider_reference);
    }

    public function verifyWebhook(Request $request, ?Payment $payment): bool
    {
        return true;
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        return new WebhookEvent(
            (string) $request->input('id', $this->payloadId($request)),
            (string) $request->input('type', 'payment.updated'),
            $request->input('reference'),
            refresh: true,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function standalone(array $config): BillingFake
    {
        $fake = new BillingFake;
        $fake->outcome = PaymentStatus::tryFrom((string) ($config['outcome'] ?? 'succeeded')) ?? PaymentStatus::Succeeded;
        $fake->eventually = PaymentStatus::Succeeded;

        return $fake;
    }
}

<?php

declare(strict_types=1);

namespace Atannex\Billing\Gateways;

use Atannex\Billing\Data\RefundResult;
use Atannex\Billing\Data\Snapshot;
use Atannex\Billing\Data\WebhookEvent;
use Atannex\Billing\Enums\Capability;
use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Exceptions\GatewayException;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Atannex\Billing\Support\Urls;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Flutterwave Standard (v3): a hosted page for cards, francophone mobile
 * money, USSD and bank transfers across Africa and beyond. Every
 * notification is re-verified with Flutterwave, as they recommend.
 */
class FlutterwaveGateway extends AbstractGateway
{
    protected array $capabilities = [
        Capability::Redirect,
        Capability::Refunds,
        Capability::PartialRefunds,
        Capability::Webhooks,
    ];

    public function initiate(Payment $payment): Snapshot
    {
        $body = $this->call('post', '/v3/payments', [
            'tx_ref' => $payment->reference,
            'amount' => $this->decimalAmount($payment),
            'currency' => $this->providerCurrency($payment->currency),
            'redirect_url' => Urls::return($payment),
            'payment_options' => (string) $this->config('payment_options', ''),
            'customer' => array_filter([
                'email' => $payment->customer_email ?: 'customer+'.strtolower($payment->reference).'@example.invalid',
                'phonenumber' => $payment->msisdn()?->international() ?? $payment->customer_phone,
                'name' => $payment->customer_name,
            ]),
            'customizations' => array_filter([
                'title' => $this->config('title'),
                'description' => $payment->description,
                'logo' => $this->config('logo'),
            ]),
            'meta' => ['billing_reference' => $payment->reference],
        ]);

        $link = data_get($body, 'data.link');

        if (! is_string($link) || $link === '') {
            throw new GatewayException("[{$this->name}] no payment link in the answer: ".($body['message'] ?? 'unknown error'), $this->name, body: $body);
        }

        return new Snapshot(PaymentStatus::RequiresAction, redirectUrl: $link, raw: $body);
    }

    public function fetch(Payment $payment): Snapshot
    {
        try {
            $body = $this->call('get', '/v3/transactions/verify_by_reference', ['tx_ref' => $payment->reference]);
        } catch (GatewayException $e) {
            // No transaction yet: the payer has not submitted the hosted form.
            if (in_array($e->httpStatus, [400, 404], true)) {
                return Snapshot::status(PaymentStatus::RequiresAction);
            }

            throw $e;
        }

        $data = (array) ($body['data'] ?? []);

        $status = match (strtolower((string) ($data['status'] ?? ''))) {
            'successful' => PaymentStatus::Succeeded,
            'failed' => PaymentStatus::Failed,
            'cancelled' => PaymentStatus::Cancelled,
            'pending' => PaymentStatus::Processing,
            default => PaymentStatus::RequiresAction,
        };

        return new Snapshot(
            $status,
            providerReference: isset($data['id']) ? (string) $data['id'] : null,
            amount: $status === PaymentStatus::Succeeded ? $this->moneyFromDecimal($data['amount'] ?? null, $data['currency'] ?? null, $payment) : null,
            failureCode: $status->isUnsuccessful() ? strtolower($status->value) : null,
            failureMessage: $status->isUnsuccessful() ? ($data['processor_response'] ?? null) : null,
            data: array_filter(['transaction_id' => $data['id'] ?? null, 'flw_ref' => $data['flw_ref'] ?? null]),
            raw: $body,
        );
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        $id = $payment->gatewayValue('transaction_id') ?? $payment->provider_reference;
        $body = $this->call('post', "/v3/transactions/{$id}/refund", [
            'amount' => $this->decimalAmount($refund),
            'comments' => $refund->reason,
        ]);

        return $this->mapRefund((array) ($body['data'] ?? []), $body);
    }

    public function fetchRefund(Payment $payment, Refund $refund): RefundResult
    {
        $body = $this->call('get', '/v3/refunds/'.$refund->provider_reference);

        return $this->mapRefund((array) ($body['data'] ?? []), $body);
    }

    /**
     * Flutterwave sends the secret hash set in its dashboard in `verif-hash`.
     */
    public function verifyWebhook(Request $request, ?Payment $payment): bool
    {
        $hash = (string) $this->config('webhook_hash', '');

        return $hash !== '' && hash_equals($hash, (string) $request->header('verif-hash', ''));
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $data = (array) $request->input('data', []);
        $type = (string) ($request->input('event') ?? $request->input('event.type') ?? 'unknown');

        if (! str_starts_with($type, 'charge.')) {
            return WebhookEvent::ignored($this->payloadId($request), $type);
        }

        return new WebhookEvent(
            'flw:'.($data['id'] ?? '').':'.($data['status'] ?? ''),
            $type,
            $data['tx_ref'] ?? null,
            isset($data['id']) ? (string) $data['id'] : null,
            refresh: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $payload = []): array
    {
        $client = $this->request()
            ->baseUrl(rtrim((string) $this->config('base_url', 'https://api.flutterwave.com'), '/'))
            ->withToken((string) $this->config('secret'));

        return (array) $this->send(fn (PendingRequest $http) => $http->{$method}($path, $payload), $client)->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    private function mapRefund(array $data, array $raw): RefundResult
    {
        return new RefundResult(
            match (strtolower((string) ($data['status'] ?? ''))) {
                'completed', 'successful' => RefundStatus::Succeeded,
                'failed', 'declined' => RefundStatus::Failed,
                default => RefundStatus::Pending,
            },
            isset($data['id']) ? (string) $data['id'] : null,
            raw: $raw,
        );
    }
}

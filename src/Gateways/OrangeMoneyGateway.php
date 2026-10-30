<?php

declare(strict_types=1);

namespace Atannex\Billing\Gateways;

use Atannex\Billing\Data\Snapshot;
use Atannex\Billing\Data\WebhookEvent;
use Atannex\Billing\Enums\Capability;
use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Exceptions\GatewayException;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Money\Money;
use Atannex\Billing\Support\Urls;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Orange Money in two flavours:
 *
 * - webpay: Orange Developer Web Payment. The payer is redirected to an
 *   Orange page; Orange notifies notif_url with a notif_token that must
 *   match the one issued for the payment.
 * - merchant: Orange Cameroon's merchant payment API (mp/init + mp/pay).
 *   A USSD prompt is pushed to the payer's phone, like MTN. mp/init runs
 *   in prepare(), so the payToken is stored before the prompt is pushed
 *   and a timed-out mp/pay is checked rather than failed.
 *
 * Either way a notification only triggers a status check with Orange.
 */
class OrangeMoneyGateway extends AbstractGateway
{
    public function capabilities(): array
    {
        return $this->merchantMode()
            ? [Capability::Push, Capability::Webhooks]
            : [Capability::Redirect, Capability::Webhooks];
    }

    /** Merchant mode: the payToken from mp/init, kept before mp/pay is sent. */
    public function prepare(Payment $payment): array
    {
        if (! $this->merchantMode() || $payment->msisdn() === null) {
            return [];
        }

        return ['pay_token' => $this->initToken()];
    }

    public function initiate(Payment $payment): Snapshot
    {
        return $this->merchantMode() ? $this->push($payment) : $this->webpay($payment);
    }

    public function fetch(Payment $payment): Snapshot
    {
        return $this->merchantMode() ? $this->fetchMerchant($payment) : $this->fetchWebpay($payment);
    }

    public function verifyWebhook(Request $request, ?Payment $payment): bool
    {
        if ($payment === null) {
            return false;
        }

        if ($this->merchantMode()) {
            return true;
        }

        $expected = (string) $payment->gatewayValue('notif_token', '');

        return $expected !== '' && hash_equals($expected, (string) $request->input('notif_token', ''));
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $status = (string) ($request->input('status') ?? $request->input('data.status') ?? 'notification');

        return new WebhookEvent(
            $request->input('txnid') ? 'txn:'.$request->input('txnid').':'.$status : $this->payloadId($request),
            'payment.'.strtolower($status),
            providerReference: $request->input('payToken') ?? $request->input('data.payToken'),
            refresh: true,
        );
    }

    private function merchantMode(): bool
    {
        return $this->config('mode', 'webpay') === 'merchant';
    }

    // Web Payment --------------------------------------------------------

    private function webpay(Payment $payment): Snapshot
    {
        $body = (array) $this->webpayCall(fn (PendingRequest $http) => $http->post($this->webpayPath('webpayment'), [
            'merchant_key' => (string) $this->config('webpay.merchant_key'),
            'currency' => $this->providerCurrency($payment->currency),
            'order_id' => $payment->reference,
            'amount' => $payment->amount,
            'return_url' => Urls::return($payment),
            'cancel_url' => Urls::return($payment, cancelled: true),
            'notif_url' => Urls::webhook($this->name, $payment),
            'lang' => (string) $this->config('webpay.lang', 'fr'),
            'reference' => Str::limit((string) ($payment->description ?: $payment->reference), 30, ''),
        ]))->json();

        if (empty($body['payment_url']) || empty($body['pay_token'])) {
            throw new GatewayException("[{$this->name}] no payment URL in the answer: ".($body['message'] ?? 'unknown error'), $this->name, body: $body);
        }

        return new Snapshot(
            PaymentStatus::RequiresAction,
            providerReference: $body['pay_token'],
            redirectUrl: $body['payment_url'],
            data: array_filter(['pay_token' => $body['pay_token'], 'notif_token' => $body['notif_token'] ?? null]),
            raw: $body,
        );
    }

    private function fetchWebpay(Payment $payment): Snapshot
    {
        $body = (array) $this->webpayCall(fn (PendingRequest $http) => $http->post($this->webpayPath('transactionstatus'), [
            'order_id' => $payment->reference,
            'amount' => $payment->amount,
            'pay_token' => (string) ($payment->gatewayValue('pay_token') ?? $payment->provider_reference),
        ]))->json();

        // Orange keys the lookup by amount, so a SUCCESS here is for this amount.
        return $this->map((string) ($body['status'] ?? ''), $body, null, amountImplied: true);
    }

    private function webpayPath(string $endpoint): string
    {
        return sprintf('/orange-money-webpay/%s/%s/%s', $this->config('webpay.country', 'cm'), $this->config('webpay.version', 'v1'), $endpoint);
    }

    /**
     * @param  Closure(PendingRequest): Response  $send
     */
    private function webpayCall(Closure $send): Response
    {
        return $this->authorized('webpay', $send, fn () => $this->webpayClient()->withToken($this->webpayToken()));
    }

    private function webpayClient(): PendingRequest
    {
        return $this->request()->baseUrl(rtrim((string) $this->config('webpay.base_url', 'https://api.orange.com'), '/'));
    }

    private function webpayToken(): string
    {
        return $this->token('webpay', function () {
            $body = (array) $this->send(fn (PendingRequest $http) => $http
                ->withBasicAuth((string) $this->config('webpay.client_id'), (string) $this->config('webpay.client_secret'))
                ->asForm()
                ->post('/oauth/v3/token', ['grant_type' => 'client_credentials']), $this->webpayClient())->json();

            return [(string) ($body['access_token'] ?? ''), (int) ($body['expires_in'] ?? 3600)];
        });
    }

    // Merchant payment (USSD push) ---------------------------------------

    private function push(Payment $payment): Snapshot
    {
        $msisdn = $payment->msisdn();

        if ($msisdn === null) {
            return Snapshot::failed('invalid_phone', 'A valid Orange Money number is required.');
        }

        $payToken = (string) ($payment->gatewayValue('pay_token') ?? $this->initToken());

        $body = (array) $this->merchantCall(fn (PendingRequest $http) => $http->post($this->merchantPath('mp/pay'), [
            'notifUrl' => Urls::webhook($this->name, $payment),
            'channelUserMsisdn' => (string) $this->config('merchant.channel_msisdn'),
            'amount' => $this->decimalAmount($payment),
            'subscriberMsisdn' => $msisdn->national,
            'pin' => (string) $this->config('merchant.pin'),
            'orderId' => $payment->reference,
            'description' => Str::limit((string) ($payment->description ?: $payment->reference), 120, ''),
            'payToken' => $payToken,
        ]))->json();

        $snapshot = $this->map((string) (data_get($body, 'data.status') ?? 'PENDING'), $body, null);

        return new Snapshot(
            $snapshot->status === PaymentStatus::RequiresAction ? PaymentStatus::Processing : $snapshot->status,
            providerReference: $payToken,
            failureCode: $snapshot->failureCode,
            failureMessage: $snapshot->failureMessage,
            data: ['pay_token' => $payToken],
            raw: $body,
        );
    }

    private function initToken(): string
    {
        $init = (array) $this->merchantCall(fn (PendingRequest $http) => $http->post($this->merchantPath('mp/init')))->json();
        $payToken = (string) (data_get($init, 'data.payToken') ?? '');

        if ($payToken === '') {
            throw new GatewayException("[{$this->name}] no payToken in the answer: ".($init['message'] ?? 'unknown error'), $this->name, body: $init);
        }

        return $payToken;
    }

    private function fetchMerchant(Payment $payment): Snapshot
    {
        $payToken = (string) ($payment->gatewayValue('pay_token') ?? $payment->provider_reference);
        $body = (array) $this->merchantCall(fn (PendingRequest $http) => $http->get($this->merchantPath("mp/paymentstatus/{$payToken}")))->json();

        $amount = data_get($body, 'data.amount');

        return $this->map(
            (string) (data_get($body, 'data.status') ?? ''),
            $body,
            $this->moneyFromDecimal(is_scalar($amount) ? (string) $amount : null, $this->providerCurrency($payment->currency), $payment),
        );
    }

    private function merchantPath(string $endpoint): string
    {
        return rtrim((string) $this->config('merchant.api_path', '/omcoreapis/1.0.2'), '/').'/'.$endpoint;
    }

    /**
     * @param  Closure(PendingRequest): Response  $send
     */
    private function merchantCall(Closure $send): Response
    {
        return $this->authorized('merchant', $send, fn () => $this->merchantClient()
            ->withToken($this->merchantToken())
            ->withHeaders(['X-AUTH-TOKEN' => base64_encode($this->config('merchant.username').':'.$this->config('merchant.password'))]));
    }

    private function merchantClient(): PendingRequest
    {
        return $this->request()->baseUrl(rtrim((string) $this->config('merchant.base_url'), '/'));
    }

    private function merchantToken(): string
    {
        return $this->token('merchant', function () {
            $body = (array) $this->send(fn (PendingRequest $http) => $http
                ->withBasicAuth((string) $this->config('merchant.consumer_key'), (string) $this->config('merchant.consumer_secret'))
                ->asForm()
                ->post((string) $this->config('merchant.token_path', '/token'), ['grant_type' => 'client_credentials']), $this->merchantClient())->json();

            return [(string) ($body['access_token'] ?? ''), (int) ($body['expires_in'] ?? 3600)];
        });
    }

    // Shared -------------------------------------------------------------

    /**
     * @param  Closure(PendingRequest): Response  $send
     * @param  Closure(): PendingRequest  $client
     */
    private function authorized(string $scope, Closure $send, Closure $client): Response
    {
        try {
            return $this->send($send, $client());
        } catch (GatewayException $e) {
            if ($e->httpStatus !== 401) {
                throw $e;
            }

            $this->forgetToken($scope);

            return $this->send($send, $client());
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function map(string $status, array $raw, ?Money $amount, bool $amountImplied = false): Snapshot
    {
        $status = match (strtoupper($status)) {
            'SUCCESS', 'SUCCESSFUL', 'SUCCESSFULL' => PaymentStatus::Succeeded,
            'PENDING' => PaymentStatus::Processing,
            'FAILED' => PaymentStatus::Failed,
            'EXPIRED' => PaymentStatus::Expired,
            'CANCELLED', 'CANCELED' => PaymentStatus::Cancelled,
            default => PaymentStatus::RequiresAction,     // INITIATED: the payer has not acted yet
        };

        $message = data_get($raw, 'data.inittxnmessage') ?? data_get($raw, 'data.confirmtxnmessage') ?? ($raw['message'] ?? null);

        return new Snapshot(
            $status,
            amount: $status === PaymentStatus::Succeeded ? $amount : null,
            failureCode: $status->isUnsuccessful() ? strtolower($status->value) : null,
            failureMessage: $status->isUnsuccessful() && is_string($message) ? $message : null,
            data: array_filter(['txnid' => $raw['txnid'] ?? data_get($raw, 'data.txnid')]),
            raw: $raw,
            amountImplied: $status === PaymentStatus::Succeeded && $amountImplied,
        );
    }
}

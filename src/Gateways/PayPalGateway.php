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
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PayPal Orders v2. The payer approves on PayPal; the order is captured the
 * first time billing sees it approved, whether that is on the return page,
 * from a webhook or during reconciliation, so a closed tab never strands
 * an approved payment.
 */
class PayPalGateway extends AbstractGateway
{
    protected array $capabilities = [
        Capability::Redirect,
        Capability::Refunds,
        Capability::PartialRefunds,
        Capability::Webhooks,
    ];

    public function initiate(Payment $payment): Snapshot
    {
        $body = (array) $this->call(fn (PendingRequest $http) => $http
            ->withHeaders(['PayPal-Request-Id' => 'billing-'.($payment->idempotency_key ?? $payment->reference)])
            ->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $payment->reference,
                    'custom_id' => $payment->reference,
                    'invoice_id' => $payment->reference,
                    'description' => Str::limit((string) ($payment->description ?: $payment->reference), 127, ''),
                    'amount' => [
                        'currency_code' => $this->providerCurrency($payment->currency),
                        'value' => $this->decimalAmount($payment),
                    ],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => array_filter([
                    'return_url' => Urls::return($payment),
                    'cancel_url' => Urls::return($payment, cancelled: true),
                    'brand_name' => $this->config('brand_name'),
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                ])]],
            ]))->json();

        return $this->mapOrder($body, $payment);
    }

    public function fetch(Payment $payment): Snapshot
    {
        $order = (array) $this->call(fn (PendingRequest $http) => $http->get('/v2/checkout/orders/'.$payment->provider_reference))->json();

        if (($order['status'] ?? null) === 'APPROVED') {
            $order = $this->capture($payment);
        }

        return $this->mapOrder($order, $payment);
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        $capture = $payment->gatewayValue('capture_id') ?? $this->fetch($payment)->data['capture_id'] ?? null;

        if (! is_string($capture) || $capture === '') {
            throw new GatewayException("[{$this->name}] payment {$payment->reference} has no capture to refund.", $this->name);
        }

        $body = (array) $this->call(fn (PendingRequest $http) => $http
            ->withHeaders(['PayPal-Request-Id' => 'billing-'.$refund->reference])
            ->post("/v2/payments/captures/{$capture}/refund", array_filter([
                'amount' => ['value' => $this->decimalAmount($refund), 'currency_code' => $this->providerCurrency($refund->currency)],
                'invoice_id' => $refund->reference,
                'note_to_payer' => $refund->reason ? Str::limit($refund->reason, 255, '') : null,
            ])))->json();

        return $this->mapRefund($body);
    }

    public function fetchRefund(Payment $payment, Refund $refund): RefundResult
    {
        return $this->mapRefund((array) $this->call(fn (PendingRequest $http) => $http->get('/v2/payments/refunds/'.$refund->provider_reference))->json());
    }

    /**
     * Verified by PayPal's verify-webhook-signature endpoint; without a
     * webhook_id nothing is accepted (return page and reconciliation still
     * settle payments).
     */
    public function verifyWebhook(Request $request, ?Payment $payment): bool
    {
        $webhookId = (string) $this->config('webhook_id', '');

        if ($webhookId === '' || ! $request->hasHeader('PAYPAL-TRANSMISSION-SIG')) {
            return false;
        }

        $body = (array) $this->call(fn (PendingRequest $http) => $http->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $webhookId,
            'webhook_event' => json_decode($request->getContent(), true),
        ]))->json();

        return ($body['verification_status'] ?? null) === 'SUCCESS';
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $event = (array) json_decode($request->getContent(), true);
        $id = (string) ($event['id'] ?? $this->payloadId($request));
        $type = (string) ($event['event_type'] ?? 'unknown');
        $resource = (array) ($event['resource'] ?? []);

        return match (true) {
            str_starts_with($type, 'CHECKOUT.ORDER.') => new WebhookEvent(
                $id,
                $type,
                data_get($resource, 'purchase_units.0.custom_id'),
                $resource['id'] ?? null,
                refresh: true,
            ),
            in_array($type, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DECLINED'], true) => new WebhookEvent(
                $id,
                $type,
                $resource['custom_id'] ?? null,
                data_get($resource, 'supplementary_data.related_ids.order_id'),
                refresh: true,
            ),
            default => WebhookEvent::ignored($id, $type),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function capture(Payment $payment): array
    {
        return (array) $this->call(fn (PendingRequest $http) => $http
            ->withHeaders(['PayPal-Request-Id' => 'billing-'.$payment->reference.'-capture'])
            ->withBody('{}', 'application/json')
            ->post('/v2/checkout/orders/'.$payment->provider_reference.'/capture'))->json();
    }

    // Requests -----------------------------------------------------------

    /**
     * @param  Closure(PendingRequest): Response  $send
     */
    private function call(Closure $send): Response
    {
        try {
            return $this->send($send, $this->client()->withToken($this->accessToken()));
        } catch (GatewayException $e) {
            if ($e->httpStatus !== 401) {
                throw $e;
            }

            $this->forgetToken('oauth');

            return $this->send($send, $this->client()->withToken($this->accessToken()));
        }
    }

    private function client(): PendingRequest
    {
        return $this->request()->baseUrl(rtrim((string) $this->config('base_url', 'https://api-m.sandbox.paypal.com'), '/'));
    }

    private function accessToken(): string
    {
        return $this->token('oauth', function () {
            $body = (array) $this->send(fn (PendingRequest $http) => $http
                ->withBasicAuth((string) $this->config('client_id'), (string) $this->config('client_secret'))
                ->asForm()
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']), $this->client())->json();

            return [(string) ($body['access_token'] ?? ''), (int) ($body['expires_in'] ?? 3600)];
        });
    }

    // Mapping ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $order
     */
    private function mapOrder(array $order, Payment $payment): Snapshot
    {
        $capture = (array) data_get($order, 'purchase_units.0.payments.captures.0', []);
        $redirect = collect((array) ($order['links'] ?? []))->first(fn ($link) => in_array($link['rel'] ?? null, ['payer-action', 'approve'], true));

        $status = match ($order['status'] ?? null) {
            'COMPLETED' => match ($capture['status'] ?? 'COMPLETED') {
                'COMPLETED' => PaymentStatus::Succeeded,
                'PENDING' => PaymentStatus::Processing,
                'REFUNDED', 'PARTIALLY_REFUNDED' => PaymentStatus::Succeeded,
                default => PaymentStatus::Failed,
            },
            'APPROVED' => PaymentStatus::Processing,
            'VOIDED' => PaymentStatus::Cancelled,
            default => PaymentStatus::RequiresAction,
        };

        return new Snapshot(
            $status,
            providerReference: $order['id'] ?? null,
            redirectUrl: $redirect['href'] ?? null,
            amount: $status === PaymentStatus::Succeeded
                ? $this->moneyFromDecimal(data_get($capture, 'amount.value'), data_get($capture, 'amount.currency_code'), $payment)
                : null,
            failureCode: $status === PaymentStatus::Failed ? strtolower((string) ($capture['status'] ?? 'declined')) : null,
            failureMessage: $status === PaymentStatus::Failed ? (string) data_get($capture, 'status_details.reason', 'PayPal declined the payment.') : null,
            data: array_filter(['order_id' => $order['id'] ?? null, 'capture_id' => $capture['id'] ?? null]),
            raw: $order,
        );
    }

    /**
     * @param  array<string, mixed>  $refund
     */
    private function mapRefund(array $refund): RefundResult
    {
        return new RefundResult(
            match ($refund['status'] ?? null) {
                'COMPLETED' => RefundStatus::Succeeded,
                'CANCELLED', 'FAILED' => RefundStatus::Failed,
                default => RefundStatus::Pending,
            },
            $refund['id'] ?? null,
            data_get($refund, 'status_details.reason'),
            raw: $refund,
        );
    }
}

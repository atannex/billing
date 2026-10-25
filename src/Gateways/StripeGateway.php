<?php

declare(strict_types=1);

namespace Atannex\Billing\Gateways;

use Atannex\Billing\Data\RefundResult;
use Atannex\Billing\Data\Snapshot;
use Atannex\Billing\Data\WebhookEvent;
use Atannex\Billing\Enums\Capability;
use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Atannex\Billing\Money\Money;
use Atannex\Billing\Support\Urls;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Stripe through its REST API: hosted Checkout (redirect) or a
 * PaymentIntent confirmed on your page with Stripe.js (`mode: intent`).
 * Webhooks are verified with the signing secret and trusted as-is.
 */
class StripeGateway extends AbstractGateway
{
    protected array $capabilities = [
        Capability::Redirect,
        Capability::Embedded,
        Capability::Refunds,
        Capability::PartialRefunds,
        Capability::Cancel,
        Capability::Webhooks,
    ];

    public function initiate(Payment $payment): Snapshot
    {
        return $this->config('mode', 'checkout') === 'intent'
            ? $this->createIntent($payment)
            : $this->createSession($payment);
    }

    public function fetch(Payment $payment): Snapshot
    {
        $id = (string) $payment->provider_reference;

        if (str_starts_with($id, 'pi_')) {
            return $this->mapIntent($this->get("/v1/payment_intents/{$id}"), $payment);
        }

        return $this->mapSession($this->get("/v1/checkout/sessions/{$id}"), $payment);
    }

    public function cancel(Payment $payment): Snapshot
    {
        $id = (string) $payment->provider_reference;

        $path = str_starts_with($id, 'pi_') ? "/v1/payment_intents/{$id}/cancel" : "/v1/checkout/sessions/{$id}/expire";
        $body = $this->post($path, [], $payment->reference.':cancel');

        return new Snapshot(PaymentStatus::Cancelled, raw: $body);
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        $intent = $payment->gatewayValue('payment_intent');

        if (! is_string($intent) || $intent === '') {
            $intent = $this->fetch($payment)->data['payment_intent'] ?? null;
        }

        $body = $this->post('/v1/refunds', array_filter([
            'payment_intent' => $intent,
            'amount' => $refund->amount,
            'metadata' => ['billing_reference' => $payment->reference, 'billing_refund' => $refund->reference, 'reason' => $refund->reason],
        ]), $refund->reference);

        return $this->mapRefund($body);
    }

    public function fetchRefund(Payment $payment, Refund $refund): RefundResult
    {
        return $this->mapRefund($this->get('/v1/refunds/'.$refund->provider_reference));
    }

    public function verifyWebhook(Request $request, ?Payment $payment): bool
    {
        $secret = (string) $this->config('webhook_secret', '');
        $header = (string) $request->header('Stripe-Signature', '');

        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > (int) $this->config('webhook_tolerance', 300)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $event = (array) json_decode($request->getContent(), true);
        $id = (string) ($event['id'] ?? $this->payloadId($request));
        $type = (string) ($event['type'] ?? 'unknown');
        $object = (array) data_get($event, 'data.object', []);
        $reference = data_get($object, 'metadata.billing_reference') ?? ($object['client_reference_id'] ?? null);

        return match (true) {
            str_starts_with($type, 'checkout.session.') => new WebhookEvent(
                $id,
                $type,
                $reference,
                $object['id'] ?? null,
                $type === 'checkout.session.async_payment_failed'
                    ? new Snapshot(PaymentStatus::Failed, failureCode: 'async_payment_failed', failureMessage: 'The payment did not complete.', raw: $object)
                    : $this->mapSession($object, null),
            ),
            in_array($type, ['payment_intent.succeeded', 'payment_intent.processing', 'payment_intent.canceled'], true) => new WebhookEvent(
                $id,
                $type,
                $reference,
                $object['id'] ?? null,
                $this->mapIntent($object, null),
            ),
            in_array($type, ['refund.created', 'refund.updated', 'refund.failed'], true) => new WebhookEvent(
                $id,
                $type,
                $reference,
                refundProviderReference: $object['id'] ?? null,
            ),
            default => WebhookEvent::ignored($id, $type),
        };
    }

    // Requests -----------------------------------------------------------

    private function createSession(Payment $payment): Snapshot
    {
        // Stripe allows a session to stay open between 30 minutes and 24 hours.
        $minutes = min(1440, max(31, (int) $this->config('expires_after', 1440)));

        $body = $this->post('/v1/checkout/sessions', array_filter([
            'mode' => 'payment',
            'client_reference_id' => $payment->reference,
            'customer_email' => $payment->customer_email,
            'success_url' => Urls::return($payment),
            'cancel_url' => Urls::return($payment, cancelled: true),
            'expires_at' => now()->addMinutes($minutes)->getTimestamp(),
            'payment_method_types' => $this->config('payment_method_types') ?: null,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($this->providerCurrency($payment->currency)),
                    'unit_amount' => $payment->amount,
                    'product_data' => ['name' => $payment->description ?: $payment->reference],
                ],
            ]],
            'metadata' => ['billing_reference' => $payment->reference],
            'payment_intent_data' => [
                'description' => $payment->description,
                'metadata' => ['billing_reference' => $payment->reference],
            ],
        ]), $payment->idempotency_key ?? $payment->reference);

        return new Snapshot(
            PaymentStatus::RequiresAction,
            providerReference: $body['id'] ?? null,
            redirectUrl: $body['url'] ?? null,
            data: ['session_id' => $body['id'] ?? null],
            raw: $body,
        );
    }

    private function createIntent(Payment $payment): Snapshot
    {
        $types = (array) $this->config('payment_method_types', []);

        $body = $this->post('/v1/payment_intents', array_filter([
            'amount' => $payment->amount,
            'currency' => strtolower($this->providerCurrency($payment->currency)),
            'description' => $payment->description,
            'receipt_email' => $payment->customer_email,
            'payment_method_types' => $types ?: null,
            'automatic_payment_methods' => $types ? null : ['enabled' => 'true'],
            'metadata' => ['billing_reference' => $payment->reference],
        ]), $payment->idempotency_key ?? $payment->reference);

        return $this->mapIntent($body, $payment);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return (array) $this->send(fn (PendingRequest $http) => $http->get($path), $this->client())->json();
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function post(string $path, array $form, ?string $idempotencyKey = null): array
    {
        $client = $this->client()->asForm();

        if ($idempotencyKey !== null) {
            $client->withHeaders(['Idempotency-Key' => 'billing:'.$idempotencyKey.':'.md5($path)]);
        }

        return (array) $this->send(fn (PendingRequest $http) => $http->post($path, $form), $client)->json();
    }

    private function client(): PendingRequest
    {
        $client = $this->request()
            ->baseUrl(rtrim((string) $this->config('base_url', 'https://api.stripe.com'), '/'))
            ->withToken((string) $this->config('secret'));

        if ($version = $this->config('api_version')) {
            $client->withHeaders(['Stripe-Version' => $version]);
        }

        return $client;
    }

    // Mapping ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $session
     */
    private function mapSession(array $session, ?Payment $payment): Snapshot
    {
        $intent = $session['payment_intent'] ?? null;
        $intent = is_array($intent) ? ($intent['id'] ?? null) : $intent;

        $status = match (true) {
            in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true) => PaymentStatus::Succeeded,
            ($session['status'] ?? null) === 'expired' => PaymentStatus::Expired,
            ($session['status'] ?? null) === 'complete' => PaymentStatus::Processing,
            default => PaymentStatus::RequiresAction,
        };

        $amount = isset($session['amount_total'], $session['currency'])
            ? Money::minor((int) $session['amount_total'], $payment ? $this->ownCurrency((string) $session['currency'], $payment->currency) : strtoupper((string) $session['currency']))
            : null;

        return new Snapshot(
            $status,
            providerReference: $session['id'] ?? null,
            redirectUrl: $session['url'] ?? null,
            amount: $status === PaymentStatus::Succeeded ? $amount : null,
            failureCode: $status === PaymentStatus::Expired ? 'expired' : null,
            data: array_filter(['session_id' => $session['id'] ?? null, 'payment_intent' => $intent]),
            raw: $session,
        );
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function mapIntent(array $intent, ?Payment $payment): Snapshot
    {
        $status = match ($intent['status'] ?? null) {
            'succeeded' => PaymentStatus::Succeeded,
            'processing', 'requires_capture' => PaymentStatus::Processing,
            'canceled' => PaymentStatus::Cancelled,
            default => PaymentStatus::RequiresAction,
        };

        $received = $intent['amount_received'] ?? $intent['amount'] ?? null;
        $currency = isset($intent['currency']) ? strtoupper((string) $intent['currency']) : null;

        return new Snapshot(
            $status,
            providerReference: $intent['id'] ?? null,
            clientSecret: $intent['client_secret'] ?? null,
            amount: $status === PaymentStatus::Succeeded && $received !== null && $currency !== null
                ? Money::minor((int) $received, $payment ? $this->ownCurrency($currency, $payment->currency) : $currency)
                : null,
            failureCode: data_get($intent, 'last_payment_error.code'),
            failureMessage: data_get($intent, 'last_payment_error.message'),
            data: array_filter(['payment_intent' => $intent['id'] ?? null, 'client_secret' => $intent['client_secret'] ?? null]),
            raw: $intent,
        );
    }

    /**
     * @param  array<string, mixed>  $refund
     */
    private function mapRefund(array $refund): RefundResult
    {
        return new RefundResult(
            match ($refund['status'] ?? null) {
                'succeeded' => RefundStatus::Succeeded,
                'failed', 'canceled' => RefundStatus::Failed,
                default => RefundStatus::Pending,
            },
            $refund['id'] ?? null,
            $refund['failure_reason'] ?? null,
            raw: $refund,
        );
    }
}

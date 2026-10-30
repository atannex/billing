<?php

declare(strict_types=1);

namespace Atannex\Billing\Gateways;

use Atannex\Billing\Contracts\PreparesRefunds;
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
 * MTN Mobile Money (MoMo Open API): Request to Pay pushes a prompt to the
 * payer's phone and the payment stays Processing until they approve it.
 * MTN's callbacks are unsigned, so they only trigger a status check.
 * Refunds use the Disbursement product when its keys are configured.
 */
class MtnMomoGateway extends AbstractGateway implements PreparesRefunds
{
    protected array $capabilities = [Capability::Push, Capability::Webhooks];

    public function capabilities(): array
    {
        return $this->disbursementConfigured()
            ? [...$this->capabilities, Capability::Refunds, Capability::PartialRefunds]
            : $this->capabilities;
    }

    /** The X-Reference-Id is ours, so a timed-out request can still be looked up. */
    public function prepare(Payment $payment): array
    {
        return ['reference_id' => (string) Str::uuid()];
    }

    public function initiate(Payment $payment): Snapshot
    {
        $msisdn = $payment->msisdn();
        $referenceId = (string) $payment->gatewayValue('reference_id', Str::uuid());

        if ($msisdn === null) {
            return Snapshot::failed('invalid_phone', 'A valid MTN Mobile Money number is required.');
        }

        if ($this->config('validate_account') && ! $this->isActive($msisdn->international())) {
            return Snapshot::failed('account_inactive', 'This number has no active MTN Mobile Money account.');
        }

        $message = Str::limit((string) ($this->config('payer_message') ?: $payment->description ?: $payment->reference), 160, '');

        $this->collection(fn (PendingRequest $http) => $http
            ->withHeaders(array_filter([
                'X-Reference-Id' => $referenceId,
                'X-Callback-Url' => $this->config('callbacks', true) ? Urls::webhook($this->name, $payment) : null,
            ]))
            ->post('/collection/v1_0/requesttopay', [
                'amount' => $this->decimalAmount($payment),
                'currency' => $this->providerCurrency($payment->currency),
                'externalId' => $payment->reference,
                'payer' => ['partyIdType' => 'MSISDN', 'partyId' => $msisdn->international()],
                'payerMessage' => $message,
                'payeeNote' => Str::limit($payment->reference.' '.$payment->description, 160, ''),
            ]));

        return new Snapshot(PaymentStatus::Processing, providerReference: $referenceId, data: ['reference_id' => $referenceId]);
    }

    public function fetch(Payment $payment): Snapshot
    {
        $referenceId = (string) ($payment->gatewayValue('reference_id') ?? $payment->provider_reference);

        try {
            $body = (array) $this->collection(fn (PendingRequest $http) => $http->get("/collection/v1_0/requesttopay/{$referenceId}"))->json();
        } catch (GatewayException $e) {
            // MTN never registered the request. Give it a moment first: right
            // after a timeout the record may not be visible yet.
            if ($e->httpStatus === 404 && $payment->created_at->lt(now()->subMinutes(2))) {
                return Snapshot::failed('not_found', 'MTN has no record of this payment.');
            }

            if ($e->httpStatus === 404) {
                return Snapshot::status(PaymentStatus::Processing);
            }

            throw $e;
        }

        return $this->mapTransfer($body, $payment);
    }

    /** The disbursement X-Reference-Id is ours too: stored before the call. */
    public function prepareRefund(Payment $payment, Refund $refund): array
    {
        return $this->disbursementConfigured() ? ['reference_id' => (string) Str::uuid()] : [];
    }

    public function refund(Payment $payment, Refund $refund): RefundResult
    {
        if (! $this->disbursementConfigured()) {
            return parent::refund($payment, $refund);
        }

        $referenceId = (string) (data_get($refund->gateway_data ?? [], 'reference_id') ?? $refund->provider_reference ?? Str::uuid());

        $this->disbursement(fn (PendingRequest $http) => $http
            ->withHeaders(['X-Reference-Id' => $referenceId])
            ->post('/disbursement/v1_0/refund', [
                'amount' => $this->decimalAmount($refund),
                'currency' => $this->providerCurrency($refund->currency),
                'externalId' => $refund->reference,
                'payerMessage' => Str::limit('Refund '.$payment->reference, 160, ''),
                'payeeNote' => Str::limit((string) ($refund->reason ?: 'Refund '.$payment->reference), 160, ''),
                'referenceIdToRefund' => (string) ($payment->gatewayValue('reference_id') ?? $payment->provider_reference),
            ]));

        return new RefundResult(RefundStatus::Pending, $referenceId);
    }

    public function fetchRefund(Payment $payment, Refund $refund): RefundResult
    {
        $referenceId = (string) (data_get($refund->gateway_data ?? [], 'reference_id') ?? $refund->provider_reference);

        try {
            $body = (array) $this->disbursement(fn (PendingRequest $http) => $http->get('/disbursement/v1_0/refund/'.$referenceId))->json();
        } catch (GatewayException $e) {
            // MTN never registered the refund (the request was lost). Give a
            // just-timed-out request time to show up before freeing the amount.
            if ($e->httpStatus === 404) {
                return $refund->created_at->lt(now()->subMinutes(10))
                    ? new RefundResult(RefundStatus::Failed, $referenceId, 'MTN has no record of this refund.')
                    : new RefundResult(RefundStatus::Pending, $referenceId);
            }

            throw $e;
        }

        return new RefundResult(
            match (strtoupper((string) ($body['status'] ?? ''))) {
                'SUCCESSFUL' => RefundStatus::Succeeded,
                'FAILED', 'REJECTED' => RefundStatus::Failed,
                default => RefundStatus::Pending,
            },
            $referenceId,
            $this->reasonText($body['reason'] ?? null),
            raw: $body,
        );
    }

    /**
     * MTN does not sign callbacks. Accept only ones that name a payment; the
     * controller then asks MTN for the real status.
     */
    public function verifyWebhook(Request $request, ?Payment $payment): bool
    {
        return $payment !== null || is_string($request->input('externalId'));
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        return new WebhookEvent(
            $this->payloadId($request),
            'requesttopay.'.strtolower((string) $request->input('status', 'callback')),
            $request->input('externalId'),
            $request->input('referenceId'),
            refresh: true,
        );
    }

    /**
     * Whether the number holds an active MoMo wallet.
     */
    public function isActive(string $msisdn): bool
    {
        $body = (array) $this->collection(fn (PendingRequest $http) => $http->get("/collection/v1_0/accountholder/msisdn/{$msisdn}/active"))->json();

        return (bool) ($body['result'] ?? false);
    }

    /**
     * @return array<string, mixed> availableBalance and currency of the collection account
     */
    public function balance(): array
    {
        return (array) $this->collection(fn (PendingRequest $http) => $http->get('/collection/v1_0/account/balance'))->json();
    }

    // Requests -----------------------------------------------------------

    /**
     * @param  Closure(PendingRequest): Response  $send
     */
    private function collection(Closure $send): Response
    {
        return $this->product('collection', $send);
    }

    /**
     * @param  Closure(PendingRequest): Response  $send
     */
    private function disbursement(Closure $send): Response
    {
        return $this->product('disbursement', $send);
    }

    /**
     * A product call with its token, retried once with a fresh token when
     * MTN rejects the cached one.
     *
     * @param  Closure(PendingRequest): Response  $send
     */
    private function product(string $product, Closure $send): Response
    {
        try {
            return $this->send($send, $this->client($product));
        } catch (GatewayException $e) {
            if ($e->httpStatus !== 401) {
                throw $e;
            }

            $this->forgetToken($product);

            return $this->send($send, $this->client($product));
        }
    }

    private function client(string $product): PendingRequest
    {
        return $this->base($product)
            ->withToken($this->accessToken($product))
            ->withHeaders(['X-Target-Environment' => (string) $this->config('environment', 'sandbox')]);
    }

    private function base(string $product): PendingRequest
    {
        return $this->request()
            ->baseUrl(rtrim((string) $this->config('base_url'), '/'))
            ->withHeaders(['Ocp-Apim-Subscription-Key' => (string) $this->config("{$product}.subscription_key")]);
    }

    private function accessToken(string $product): string
    {
        return $this->token($product, function () use ($product) {
            $body = (array) $this->send(fn (PendingRequest $http) => $http
                ->withBasicAuth((string) $this->config("{$product}.api_user"), (string) $this->config("{$product}.api_key"))
                ->post("/{$product}/token/"), $this->base($product))->json();

            return [(string) ($body['access_token'] ?? ''), (int) ($body['expires_in'] ?? 3600)];
        });
    }

    private function disbursementConfigured(): bool
    {
        return filled($this->config('disbursement.subscription_key'))
            && filled($this->config('disbursement.api_user'))
            && filled($this->config('disbursement.api_key'));
    }

    // Mapping ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $body
     */
    private function mapTransfer(array $body, Payment $payment): Snapshot
    {
        $reason = $body['reason'] ?? null;
        $code = is_array($reason) ? ($reason['code'] ?? null) : $reason;
        $code = is_string($code) ? strtoupper($code) : null;

        $status = match (strtoupper((string) ($body['status'] ?? 'PENDING'))) {
            'SUCCESSFUL' => PaymentStatus::Succeeded,
            'FAILED', 'REJECTED' => in_array($code, ['EXPIRED', 'TRANSACTION_TIMEOUT'], true) ? PaymentStatus::Expired : PaymentStatus::Failed,
            'TIMEOUT', 'EXPIRED' => PaymentStatus::Expired,
            default => PaymentStatus::Processing,
        };

        return new Snapshot(
            $status,
            amount: $status === PaymentStatus::Succeeded ? $this->moneyFromDecimal($body['amount'] ?? null, $body['currency'] ?? null, $payment) : null,
            failureCode: $status === PaymentStatus::Succeeded || $status === PaymentStatus::Processing ? null : strtolower((string) ($code ?? 'failed')),
            failureMessage: $status === PaymentStatus::Succeeded || $status === PaymentStatus::Processing ? null : $this->reasonText($reason),
            data: array_filter(['financial_transaction_id' => $body['financialTransactionId'] ?? null]),
            raw: $body,
        );
    }

    private function reasonText(mixed $reason): ?string
    {
        if (is_array($reason) && is_string($reason['message'] ?? null) && $reason['message'] !== '') {
            return $reason['message'];
        }

        $code = is_array($reason) ? ($reason['code'] ?? null) : $reason;

        return match (is_string($code) ? strtoupper($code) : null) {
            null => null,
            'APPROVAL_REJECTED' => 'The payer declined the payment.',
            'NOT_ENOUGH_FUNDS' => 'Not enough funds on the Mobile Money account.',
            'PAYER_LIMIT_REACHED' => 'The payer has reached their transaction limit.',
            'PAYER_NOT_FOUND', 'PAYEE_NOT_FOUND' => 'This number has no MTN Mobile Money account.',
            'EXPIRED', 'TRANSACTION_TIMEOUT' => 'The payer did not answer in time.',
            'NOT_ALLOWED', 'NOT_ALLOWED_TARGET_ENVIRONMENT' => 'MTN did not allow this transaction.',
            default => is_string($code) ? Str::of($code)->lower()->replace('_', ' ')->ucfirst()->toString() : null,
        };
    }
}

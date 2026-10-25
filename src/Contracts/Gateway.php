<?php

declare(strict_types=1);

namespace Atannex\Billing\Contracts;

use Atannex\Billing\Data\RefundResult;
use Atannex\Billing\Data\Snapshot;
use Atannex\Billing\Data\WebhookEvent;
use Atannex\Billing\Enums\Capability;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Illuminate\Http\Request;

/**
 * A payment provider. Drivers only translate between the provider's API and
 * billing's vocabulary; persistence, state transitions, amount checks,
 * locking and events are handled once, by the PaymentProcessor.
 */
interface Gateway
{
    /** The configured name ("mtn_momo"), not the driver. */
    public function name(): string;

    public function supports(Capability $capability): bool;

    /** Whether this gateway takes payments in the currency. */
    public function accepts(string $currency): bool;

    public function config(?string $key = null, mixed $default = null): mixed;

    /**
     * Driver state to store before the provider is called, so a payment
     * whose request timed out can still be looked up (MTN's X-Reference-Id).
     *
     * @return array<string, mixed>
     */
    public function prepare(Payment $payment): array;

    /** Send the payment to the provider. */
    public function initiate(Payment $payment): Snapshot;

    /** Ask the provider where the payment stands. */
    public function fetch(Payment $payment): Snapshot;

    /** The payer came back from the hosted page (PayPal captures here). */
    public function complete(Payment $payment, Request $request): Snapshot;

    public function cancel(Payment $payment): Snapshot;

    public function refund(Payment $payment, Refund $refund): RefundResult;

    public function fetchRefund(Payment $payment, Refund $refund): RefundResult;

    /**
     * Whether a notification really comes from the provider. $payment is set
     * when the callback URL named one (per-payment callback URLs).
     */
    public function verifyWebhook(Request $request, ?Payment $payment): bool;

    public function parseWebhook(Request $request): WebhookEvent;
}

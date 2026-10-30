<?php

declare(strict_types=1);

namespace Atannex\Billing\Http\Controllers;

use Atannex\Billing\BillingManager;
use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Exceptions\BillingException;
use Atannex\Billing\Models\Payment;
use Illuminate\Http\JsonResponse;

/**
 * GET {prefix}/payments/{reference}/status
 *
 * For a "approve the prompt on your phone" screen to poll. An open payment
 * not checked in the last few seconds is checked with the provider first,
 * so the page learns the outcome even when the callback never comes.
 */
class StatusController
{
    public function __invoke(BillingManager $billing, string $reference): JsonResponse
    {
        $payment = Payment::query()->where('reference', $reference)->firstOrFail();

        if ($payment->isOpen() && $payment->updated_at->lt(now()->subSeconds((int) $billing->config('routes.status_refresh_after', 5)))) {
            try {
                $payment = $billing->processor()->refresh($payment, 'poll');
            } catch (BillingException) {
                // Answer with what is known; the next poll tries again.
            }
        }

        return new JsonResponse(self::present($payment));
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Payment $payment): array
    {
        return [
            'reference' => $payment->reference,
            'status' => $payment->status->value,
            'paid' => $payment->isPaid(),
            'open' => $payment->isOpen(),
            'amount' => $payment->money(),
            'redirect_url' => $payment->isOpen() ? $payment->redirectUrl() : null,
            'failure_message' => self::failureMessage($payment),
        ];
    }

    /**
     * A message fit for the payer: one of billing's own, never the
     * provider's text (which can carry internal details). The full reason
     * stays on the payment for staff.
     */
    public static function failureMessage(Payment $payment): ?string
    {
        if (! $payment->failed()) {
            return null;
        }

        return match ($payment->failure_code) {
            'invalid_phone' => 'The phone number is not valid for this payment method.',
            'account_inactive', 'payer_not_found' => 'This number has no active mobile money account.',
            'approval_rejected', 'rejected' => 'The payer declined the payment.',
            'not_enough_funds', 'insufficient_funds' => 'Not enough funds on the account.',
            'payer_limit_reached' => 'The payer has reached their transaction limit.',
            'expired', 'transaction_timeout', 'not_sent' => 'The payment was not completed in time.',
            default => match ($payment->status) {
                PaymentStatus::Cancelled => 'The payment was cancelled.',
                PaymentStatus::Expired => 'The payment was not completed in time.',
                default => 'The payment did not go through.',
            },
        };
    }
}

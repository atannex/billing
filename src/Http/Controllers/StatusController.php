<?php

declare(strict_types=1);

namespace Atannex\Billing\Http\Controllers;

use Atannex\Billing\BillingManager;
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
            'failure_message' => $payment->failure_message,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Atannex\Billing\Http\Controllers;

use Atannex\Billing\BillingManager;
use Atannex\Billing\Exceptions\BillingException;
use Atannex\Billing\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET {prefix}/return/{reference}
 *
 * Where hosted pages send the payer back. The status is taken from the
 * provider, never from the query string, then the payer goes on to the
 * payment's return_url (or cancel_url) with ?payment=…&status=….
 */
class ReturnController
{
    public function __invoke(Request $request, BillingManager $billing, string $reference): RedirectResponse|JsonResponse
    {
        $payment = Payment::query()->where('reference', $reference)->firstOrFail();
        $processor = $billing->processor();

        try {
            $payment = $request->query('outcome') === 'cancel'
                ? $this->cancelled($billing, $payment)
                : $processor->complete($payment, $request);
        } catch (BillingException) {
            // The provider is slow or down: reconciliation settles it later.
        }

        if ($request->expectsJson()) {
            return new JsonResponse(StatusController::present($payment));
        }

        $target = $payment->isPaid()
            ? ($payment->return_url ?? $billing->config('redirects.success', '/'))
            : ($payment->cancel_url ?? $payment->return_url ?? $billing->config('redirects.failure', '/'));

        $separator = str_contains((string) $target, '?') ? '&' : '?';

        return new RedirectResponse($target.$separator.http_build_query(['payment' => $payment->reference, 'status' => $payment->status->value]));
    }

    /**
     * The payer backed out. A payment that went through anyway still wins.
     * Only a gateway that can call the payment off at the provider closes
     * it here; elsewhere the link proves nothing (anyone can open it, and
     * the prompt may still be live), so the provider decides and the
     * payment expires on its own if nobody pays.
     */
    private function cancelled(BillingManager $billing, Payment $payment): Payment
    {
        $processor = $billing->processor();
        $payment = $processor->refresh($payment, 'return');

        return $payment->isOpen() && $processor->cancellable($payment) ? $processor->cancel($payment) : $payment;
    }
}

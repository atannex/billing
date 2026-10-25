<?php

declare(strict_types=1);

namespace Atannex\Billing\Http\Controllers;

use Atannex\Billing\BillingManager;
use Atannex\Billing\Events\WebhookHandled;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Atannex\Billing\Models\WebhookCall;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * POST {prefix}/webhooks/{gateway}/{reference?}
 *
 * Verify → record once → apply. Duplicates are answered without being
 * processed again; a failure answers 500 so the provider retries.
 */
class WebhookController
{
    /** Request headers worth keeping with a call (no cookies or credentials). */
    private const HEADERS = ['content-type', 'user-agent', 'stripe-signature', 'verif-hash', 'paypal-transmission-id', 'paypal-transmission-time', 'x-forwarded-for'];

    public function __invoke(Request $request, BillingManager $billing, Dispatcher $events, string $gateway, ?string $reference = null): JsonResponse
    {
        if ($billing->gatewayConfig($gateway) === null && ! $billing->isFaked()) {
            return new JsonResponse(['status' => 'unknown_gateway'], 404);
        }

        $driver = $billing->gateway($gateway);
        $payment = $reference !== null ? Payment::query()->where('reference', $reference)->where('gateway', $gateway)->first() : null;

        if ($reference !== null && $payment === null) {
            return new JsonResponse(['status' => 'unknown_payment'], 404);
        }

        if (! $driver->verifyWebhook($request, $payment)) {
            return new JsonResponse(['status' => 'invalid_signature'], 403);
        }

        $event = $driver->parseWebhook($request);

        $call = WebhookCall::query()->createOrFirst(
            ['gateway' => $gateway, 'event_id' => Str::limit($event->id, 191, '')],
            [
                'event_type' => Str::limit($event->type, 120, ''),
                'status' => 'received',
                'payload' => $request->getContent(),
                'headers' => array_filter(array_map(fn ($values) => $values[0] ?? null, array_intersect_key($request->headers->all(), array_flip(self::HEADERS)))),
            ],
        );

        if (! $call->wasRecentlyCreated && in_array($call->status, ['processed', 'ignored'], true)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        try {
            $payment ??= $this->locate($gateway, $event->reference, $event->providerReference);
            $processor = $billing->processor();
            $status = 'processed';

            if ($payment !== null && $event->refundProviderReference !== null) {
                $refund = Refund::query()->where('payment_id', $payment->id)->where('provider_reference', $event->refundProviderReference)->first();
                $refund !== null ? $processor->refreshRefund($refund, 'webhook') : $status = 'ignored';
            } elseif ($payment !== null && $event->snapshot !== null) {
                $processor->apply($payment, $event->snapshot, 'webhook');
            } elseif ($payment !== null && $event->refresh) {
                $processor->refresh($payment, 'webhook');
            } else {
                $status = 'ignored';
            }

            $call->forceFill(['status' => $status, 'payment_id' => $payment?->id, 'processed_at' => now(), 'exception' => null])->save();
        } catch (Throwable $e) {
            $call->forceFill(['status' => 'failed', 'payment_id' => $payment?->id, 'exception' => Str::limit($e::class.': '.$e->getMessage(), 2000)])->save();
            report($e);

            return new JsonResponse(['status' => 'failed'], 500);
        }

        $events->dispatch(new WebhookHandled($call, $event));

        return new JsonResponse(['status' => $status]);
    }

    private function locate(string $gateway, ?string $reference, ?string $providerReference): ?Payment
    {
        if ($reference === null && $providerReference === null) {
            return null;
        }

        return Payment::query()
            ->where('gateway', $gateway)
            ->where(fn ($query) => $query
                ->when($reference !== null, fn ($q) => $q->orWhere('reference', $reference))
                ->when($providerReference !== null, fn ($q) => $q->orWhere('provider_reference', $providerReference)))
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace Atannex\Billing\Support;

use Atannex\Billing\Models\Payment;

/**
 * Callback and return URLs handed to providers. With routes.public_url set
 * (an ngrok tunnel while developing) they point there instead of APP_URL.
 */
final class Urls
{
    public static function webhook(string $gateway, ?Payment $payment = null): string
    {
        return self::public(route('billing.webhook', array_filter([
            'gateway' => $gateway,
            'reference' => $payment?->reference,
        ])));
    }

    public static function return(Payment $payment, bool $cancelled = false): string
    {
        return self::public(route('billing.return', array_filter([
            'reference' => $payment->reference,
            'outcome' => $cancelled ? 'cancel' : null,
        ])));
    }

    public static function public(string $url): string
    {
        $base = config('billing.routes.public_url');

        if (! is_string($base) || $base === '') {
            return $url;
        }

        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return rtrim($base, '/').$path;
    }
}

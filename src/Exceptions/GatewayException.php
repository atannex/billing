<?php

declare(strict_types=1);

namespace Atannex\Billing\Exceptions;

use Atannex\Billing\Models\Payment;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The provider refused a request (4xx) or answered with something unusable.
 */
class GatewayException extends BillingException
{
    public ?Payment $payment = null;

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        public readonly string $gateway = '',
        public readonly ?string $providerCode = null,
        public readonly ?int $httpStatus = null,
        public readonly array $body = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(string $gateway, Response $response, ?string $message = null, ?string $code = null): static
    {
        $body = (array) ($response->json() ?? []);

        return new static(
            sprintf('[%s] %s (HTTP %d)', $gateway, $message ?? 'request refused', $response->status()),
            $gateway,
            $code,
            $response->status(),
            $body,
        );
    }

    public function withPayment(Payment $payment): static
    {
        $this->payment = $payment;

        return $this;
    }
}

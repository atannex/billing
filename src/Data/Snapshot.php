<?php

declare(strict_types=1);

namespace Atannex\Billing\Data;

use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Money\Money;

/**
 * What a provider says about a payment at one moment. Gateways return it;
 * the processor decides what to do with it. `amount` is what the provider
 * reports was charged and is checked against the payment before success;
 * a success without it is not believed unless `amountImplied` says the
 * provider's answer can only concern the payment's own amount.
 */
final class Snapshot
{
    /**
     * @param  array<string, mixed>  $data  driver state to keep on the payment (tokens, ids)
     * @param  array<string, mixed>  $raw  the provider's answer, for the ledger
     * @param  bool  $amountImplied  the lookup itself was keyed by the amount (Orange Web Payment)
     */
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $clientSecret = null,
        public readonly ?Money $amount = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly array $data = [],
        public readonly array $raw = [],
        public readonly bool $amountImplied = false,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function status(PaymentStatus $status, array $raw = []): self
    {
        return new self($status, raw: $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function failed(?string $code, ?string $message, array $raw = []): self
    {
        return new self(PaymentStatus::Failed, failureCode: $code, failureMessage: $message, raw: $raw);
    }
}

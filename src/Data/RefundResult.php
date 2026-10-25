<?php

declare(strict_types=1);

namespace Atannex\Billing\Data;

use Atannex\Billing\Enums\RefundStatus;

final class RefundResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly RefundStatus $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureMessage = null,
        public readonly array $data = [],
        public readonly array $raw = [],
    ) {}
}

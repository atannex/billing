<?php

declare(strict_types=1);

namespace Atannex\Billing\Data;

/**
 * A provider notification, reduced to what billing needs: which payment it
 * concerns and either a trustworthy snapshot (signed payloads) or a request
 * to ask the provider again (unsigned callbacks are never believed).
 */
final class WebhookEvent
{
    /**
     * @param  string  $id  provider event id, or a hash of the payload; used to drop duplicates
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $reference = null,
        public readonly ?string $providerReference = null,
        public readonly ?Snapshot $snapshot = null,
        public readonly bool $refresh = false,
        public readonly ?string $refundProviderReference = null,
    ) {}

    /** Nothing to do (an event type billing does not track). */
    public static function ignored(string $id, string $type): self
    {
        return new self($id, $type);
    }

    public function concernsPayment(): bool
    {
        return $this->reference !== null || $this->providerReference !== null;
    }
}

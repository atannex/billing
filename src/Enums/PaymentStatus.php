<?php

declare(strict_types=1);

namespace Atannex\Billing\Enums;

/**
 * Where a payment is in its life. Transitions are guarded: a stale webhook
 * can never move a refunded payment back to pending. The provider is the
 * source of truth, so a payment expired or failed locally may still turn
 * Succeeded when the provider later confirms the money arrived.
 */
enum PaymentStatus: string
{
    /** Created here, not sent to a provider yet. */
    case Pending = 'pending';

    /** Accepted by the provider, waiting for the payer (USSD prompt, bank). */
    case Processing = 'processing';

    /** The payer must act: open the redirect URL or confirm with Stripe.js. */
    case RequiresAction = 'requires_action';

    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::RequiresAction, self::Succeeded, self::Failed, self::Cancelled, self::Expired],
            self::Processing => [self::RequiresAction, self::Succeeded, self::Failed, self::Cancelled, self::Expired],
            self::RequiresAction => [self::Processing, self::Succeeded, self::Failed, self::Cancelled, self::Expired],
            self::Failed, self::Cancelled, self::Expired => [self::Succeeded],
            self::Succeeded => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],
            self::Refunded => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->next(), true);
    }

    /** Still waiting on the provider or the payer; reconciliation checks these. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::RequiresAction], true);
    }

    /** The money arrived (and may have been partly or fully returned since). */
    public function isPaid(): bool
    {
        return in_array($this, [self::Succeeded, self::PartiallyRefunded, self::Refunded], true);
    }

    public function isUnsuccessful(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled, self::Expired], true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Processing->value, self::RequiresAction->value];
    }
}

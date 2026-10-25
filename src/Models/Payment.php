<?php

declare(strict_types=1);

namespace Atannex\Billing\Models;

use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Models\Concerns\UsesBillingTable;
use Atannex\Billing\Money\Money;
use Atannex\Billing\Support\Msisdn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $reference
 * @property string $gateway
 * @property ?string $method
 * @property PaymentStatus $status
 * @property int $amount
 * @property string $currency
 * @property int $amount_refunded
 * @property ?string $customer_name
 * @property ?string $customer_email
 * @property ?string $customer_phone
 * @property ?string $description
 * @property ?string $provider_reference
 * @property ?string $idempotency_key
 * @property ?string $return_url
 * @property ?string $cancel_url
 * @property ?array<string, mixed> $gateway_data
 * @property ?array<string, mixed> $metadata
 * @property ?string $failure_code
 * @property ?string $failure_message
 * @property int $checks
 * @property ?Carbon $next_check_at
 * @property ?Carbon $expires_at
 * @property ?Carbon $paid_at
 * @property ?Carbon $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Payment extends Model
{
    use UsesBillingTable;

    protected const BASE_TABLE = 'payments';

    protected $guarded = ['id'];

    /** Driver tokens (Orange notif_token, pay_token) never leave the server. */
    protected $hidden = ['gateway_data'];

    protected $attributes = [
        'amount_refunded' => 0,
        'checks' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'amount_refunded' => 'integer',
            'gateway_data' => 'array',
            'metadata' => 'array',
            'checks' => 'integer',
            'next_check_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    // Relations ----------------------------------------------------------

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** @return HasMany<PaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }

    // Money --------------------------------------------------------------

    public function money(): Money
    {
        return Money::minor($this->amount, $this->currency);
    }

    public function refunded(): Money
    {
        return Money::minor($this->amount_refunded, $this->currency);
    }

    /** What can still be refunded, net of refunds in flight. */
    public function refundable(): Money
    {
        if (! $this->status->isPaid()) {
            return Money::zero($this->currency);
        }

        $reserved = (int) $this->refunds()->whereIn('status', ['pending', 'succeeded'])->sum('amount');

        return Money::minor(max(0, $this->amount - $reserved), $this->currency);
    }

    /** What was kept: the amount minus refunds, or zero if never paid. */
    public function net(): Money
    {
        return $this->status->isPaid() ? $this->money()->minus($this->refunded()) : Money::zero($this->currency);
    }

    // State --------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    public function succeeded(): bool
    {
        return $this->status === PaymentStatus::Succeeded;
    }

    public function failed(): bool
    {
        return $this->status->isUnsuccessful();
    }

    public function requiresAction(): bool
    {
        return $this->status === PaymentStatus::RequiresAction;
    }

    /** Where to send the payer, when the gateway uses a hosted page. */
    public function redirectUrl(): ?string
    {
        return $this->gatewayValue('redirect_url');
    }

    /** For Stripe.js or another embedded confirmation. */
    public function clientSecret(): ?string
    {
        return $this->gatewayValue('client_secret');
    }

    public function msisdn(): ?Msisdn
    {
        return Msisdn::tryParse($this->customer_phone, (string) config('billing.country', 'CM'));
    }

    public function gatewayValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->gateway_data ?? [], $key, $default);
    }

    // Scopes -------------------------------------------------------------

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', PaymentStatus::openValues());
    }

    /**
     * Open payments whose next provider check is due.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->whereIn('status', PaymentStatus::openValues())
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', now());
    }

    /** @param Builder<self> $query */
    public function scopePaid(Builder $query): void
    {
        $query->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value]);
    }

    /** @param Builder<self> $query */
    public function scopeStatus(Builder $query, PaymentStatus|string ...$statuses): void
    {
        $query->whereIn('status', array_map(fn ($s) => $s instanceof PaymentStatus ? $s->value : $s, $statuses));
    }

    /** @param Builder<self> $query */
    public function scopeGateway(Builder $query, string $gateway): void
    {
        $query->where('gateway', $gateway);
    }
}

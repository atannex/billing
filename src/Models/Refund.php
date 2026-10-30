<?php

declare(strict_types=1);

namespace Atannex\Billing\Models;

use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Models\Concerns\UsesBillingTable;
use Atannex\Billing\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $payment_id
 * @property string $reference
 * @property ?string $provider_reference
 * @property int $amount
 * @property string $currency
 * @property RefundStatus $status
 * @property ?string $reason
 * @property ?array<string, mixed> $gateway_data
 * @property ?string $failure_message
 * @property ?Carbon $refunded_at
 * @property-read Payment $payment
 */
class Refund extends Model
{
    use UsesBillingTable;

    protected const BASE_TABLE = 'refunds';

    /** Written by the PaymentProcessor only (forceFill). */
    protected $guarded = ['id', 'payment_id', 'amount', 'currency', 'status', 'provider_reference', 'gateway_data', 'failure_message', 'refunded_at'];

    protected $hidden = ['gateway_data'];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'integer',
            'gateway_data' => 'array',
            'refunded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function money(): Money
    {
        return Money::minor($this->amount, $this->currency);
    }
}

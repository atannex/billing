<?php

declare(strict_types=1);

namespace Atannex\Billing\Models;

use Atannex\Billing\Models\Concerns\UsesBillingTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a payment's audit trail: what changed, from where (api,
 * webhook, return, reconcile, refund) and what the provider said.
 *
 * @property int $id
 * @property int $payment_id
 * @property string $type
 * @property ?string $from_status
 * @property ?string $to_status
 * @property string $source
 * @property ?array<string, mixed> $data
 * @property Carbon $created_at
 */
class PaymentEvent extends Model
{
    use UsesBillingTable;

    protected const BASE_TABLE = 'payment_events';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

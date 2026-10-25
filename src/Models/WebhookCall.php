<?php

declare(strict_types=1);

namespace Atannex\Billing\Models;

use Atannex\Billing\Models\Concerns\UsesBillingTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Every provider notification received, kept for auditing and to drop
 * duplicates (providers retry, sometimes for days).
 *
 * @property int $id
 * @property string $gateway
 * @property string $event_id
 * @property ?string $event_type
 * @property ?int $payment_id
 * @property string $status received|processed|ignored|failed|invalid
 * @property ?string $payload
 * @property ?array<string, mixed> $headers
 * @property ?string $exception
 * @property ?Carbon $processed_at
 */
class WebhookCall extends Model
{
    use UsesBillingTable;

    protected const BASE_TABLE = 'webhook_calls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

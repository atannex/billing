<?php

declare(strict_types=1);

namespace Atannex\Billing\Console;

use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Jobs\ReconcilePayment;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Atannex\Billing\Processing\PaymentProcessor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Checks every open payment whose next check is due (and locally closed
 * ones still watched for a late success), and pending refunds.
 * Scheduled every minute by the package (reconcile.schedule).
 */
class ReconcileCommand extends Command
{
    protected $signature = 'billing:reconcile
        {--sync : Check inline even when reconcile.queue is set}
        {--limit= : At most this many payments (default reconcile.batch)}
        {--gateway= : Only this gateway}';

    protected $description = 'Check open payments and pending refunds with their providers';

    public function handle(PaymentProcessor $processor): int
    {
        $queue = config('billing.reconcile.queue');
        $inline = $this->option('sync') || $queue === null;
        $limit = (int) ($this->option('limit') ?: config('billing.reconcile.batch', 200));

        $payments = Payment::query()
            ->due()
            ->when($this->option('gateway'), fn ($query, $gateway) => $query->where('gateway', $gateway))
            ->orderBy('next_check_at')
            ->limit($limit)
            ->get();

        $settled = 0;
        $failed = 0;

        foreach ($payments as $payment) {
            // Claim it so an overlapping run does not check it twice.
            $payment->newQuery()->whereKey($payment->getKey())->update(['next_check_at' => now()->addMinutes(2)]);

            if (! $inline) {
                ReconcilePayment::dispatch($payment->id)->onQueue($queue);

                continue;
            }

            try {
                $before = $payment->status;
                $payment = $processor->refresh($payment, 'reconcile');
                $settled += $payment->status !== $before && ! $payment->isOpen() ? 1 : 0;
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->components->warn("{$payment->reference}: {$e->getMessage()}");
            }
        }

        $refunds = Refund::query()
            ->where('status', RefundStatus::Pending)
            ->where('updated_at', '<=', now()->subSeconds(30))
            ->with('payment')
            ->limit($limit)
            ->get();

        foreach ($refunds as $refund) {
            try {
                $processor->refreshRefund($refund);
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->components->warn("{$refund->reference}: {$e->getMessage()}");
            }
        }

        $this->components->info(sprintf(
            '%d payments %s, %d settled; %d refunds checked%s.',
            $payments->count(),
            $inline ? 'checked' : 'queued',
            $settled,
            $refunds->count(),
            $failed > 0 ? ", {$failed} errors" : '',
        ));

        return self::SUCCESS;
    }
}

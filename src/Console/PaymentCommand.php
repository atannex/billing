<?php

declare(strict_types=1);

namespace Atannex\Billing\Console;

use Atannex\Billing\BillingManager;
use Atannex\Billing\Models\Payment;
use Illuminate\Console\Command;

class PaymentCommand extends Command
{
    protected $signature = 'billing:payment {reference : PAY-… reference or provider reference}
        {--refresh : Ask the provider for the latest status first}';

    protected $description = 'Show a payment, its refunds and its audit trail';

    public function handle(BillingManager $billing): int
    {
        $reference = (string) $this->argument('reference');
        $payment = Payment::query()->where('reference', $reference)->orWhere('provider_reference', $reference)->first();

        if ($payment === null) {
            $this->components->error("No payment [{$reference}].");

            return self::FAILURE;
        }

        if ($this->option('refresh')) {
            $payment = $billing->processor()->refresh($payment, 'console');
        }

        $this->components->twoColumnDetail('<fg=white;options=bold>'.$payment->reference.'</>', $payment->status->label());
        $this->components->twoColumnDetail('Amount', $payment->money()->format().($payment->amount_refunded > 0 ? ' (refunded '.$payment->refunded()->format().')' : ''));
        $this->components->twoColumnDetail('Gateway', $payment->gateway.($payment->provider_reference ? ' · '.$payment->provider_reference : ''));
        $this->components->twoColumnDetail('Customer', implode(' · ', array_filter([$payment->customer_name, $payment->customer_email, $payment->customer_phone])) ?: '–');
        $this->components->twoColumnDetail('Created', (string) $payment->created_at);

        if ($payment->paid_at) {
            $this->components->twoColumnDetail('Paid', (string) $payment->paid_at);
        }

        if ($payment->failure_code) {
            $this->components->twoColumnDetail('Failure', $payment->failure_code.($payment->failure_message ? ': '.$payment->failure_message : ''));
        }

        if ($payment->isOpen()) {
            $this->components->twoColumnDetail('Next check', ($payment->next_check_at?->diffForHumans() ?? '–').' · expires '.($payment->expires_at?->diffForHumans() ?? '–').' · '.$payment->checks.' checks');
        }

        if ($payment->refunds()->exists()) {
            $this->newLine();
            $this->table(['Refund', 'Amount', 'Status', 'Reason', 'At'], $payment->refunds->map(fn ($refund) => [
                $refund->reference, $refund->money()->format(), $refund->status->value, $refund->reason, (string) ($refund->refunded_at ?? $refund->created_at),
            ])->all());
        }

        $this->newLine();
        $this->table(['When', 'Event', 'Change', 'Source'], $payment->events->map(fn ($event) => [
            (string) $event->created_at,
            $event->type,
            $event->from_status || $event->to_status ? ($event->from_status ?? '?').' → '.($event->to_status ?? '?') : '',
            $event->source,
        ])->all());

        return self::SUCCESS;
    }
}

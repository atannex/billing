<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Feature;

use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Events\PaymentSucceeded;
use Atannex\Billing\Facades\Billing;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class ReconcileTest extends TestCase
{
    #[Test]
    public function open_payments_are_checked_on_a_backoff(): void
    {
        $this->freezeSecond();
        $fake = Billing::fake()->process();
        $payment = Billing::amount(5000)->phone('677123456')->charge();

        $this->assertEquals(now()->addSeconds(10), $payment->next_check_at);
        $this->assertEquals(now()->addMinutes(10), $payment->expires_at);

        $this->artisan('billing:reconcile');
        $this->assertSame(0, $payment->refresh()->checks, 'Not due yet.');

        $this->travel(11)->seconds();
        $this->artisan('billing:reconcile')->assertSuccessful();
        $payment->refresh();
        $this->assertSame(1, $payment->checks);
        $this->assertEquals(now()->addSeconds(20), $payment->next_check_at);

        $fake->provider[$payment->reference] = PaymentStatus::Succeeded;
        $this->travel(21)->seconds();
        $this->artisan('billing:reconcile');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNull($payment->next_check_at);
    }

    #[Test]
    public function an_unanswered_payment_expires(): void
    {
        Billing::fake()->process();
        $payment = Billing::amount(5000)->phone('677123456')->charge();

        $this->travel(11)->minutes();
        $this->artisan('billing:reconcile');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertSame('expired', $payment->failure_code);
    }

    #[Test]
    public function the_last_check_lands_on_the_expiry(): void
    {
        $this->freezeSecond();
        config(['billing.reconcile.backoff' => [3600]]);
        Billing::fake()->process();

        $payment = Billing::amount(5000)->phone('677123456')->charge();

        $this->assertEquals($payment->expires_at, $payment->next_check_at);
    }

    #[Test]
    public function a_late_success_still_lands_after_a_local_expiry(): void
    {
        $fake = Billing::fake()->process();
        $payment = Billing::amount(5000)->phone('677123456')->charge();
        $this->travel(11)->minutes();
        $this->artisan('billing:reconcile');
        $this->assertSame(PaymentStatus::Expired, $payment->refresh()->status);
        Event::fake([PaymentSucceeded::class]);

        $fake->settle($payment, PaymentStatus::Succeeded);

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertTrue($payment->events()->where('type', 'late_success')->exists());
        Event::assertDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function a_stale_report_never_reopens_a_settled_payment(): void
    {
        $fake = Billing::fake();
        $payment = Billing::amount(5000)->charge();

        $fake->settle($payment, PaymentStatus::Processing);

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertTrue($payment->events()->where('type', 'stale')->exists());
    }

    #[Test]
    public function a_payment_never_sent_is_closed(): void
    {
        Billing::fake();
        $payment = Payment::query()->forceCreate([
            'reference' => 'PAY-ORPHAN', 'gateway' => 'stripe', 'status' => 'pending',
            'amount' => 100, 'currency' => 'XAF', 'next_check_at' => now()->addMinutes(5),
        ]);

        $this->travel(6)->minutes();
        $this->artisan('billing:reconcile');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertSame('not_sent', $payment->failure_code);
    }

    #[Test]
    public function provider_errors_during_reconciliation_are_retried_later(): void
    {
        $fake = Billing::fake()->process();
        $payment = Billing::amount(5000)->phone('677123456')->charge();
        $fake->unavailable();

        $this->travel(11)->seconds();
        $this->artisan('billing:reconcile')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Processing, $payment->status);
        $this->assertSame(1, $payment->checks);
        $this->assertTrue($payment->events()->where('type', 'check_failed')->exists());
    }

    #[Test]
    public function the_console_shows_a_payment_and_its_ledger(): void
    {
        $fake = Billing::fake()->process();
        $payment = Billing::amount(5000)->phone('677123456')->description('Abonnement')->charge();
        $fake->settle($payment, PaymentStatus::Succeeded);

        $this->artisan('billing:payment', ['reference' => $payment->reference])
            ->expectsOutputToContain($payment->reference)
            ->expectsOutputToContain('status_changed')
            ->assertSuccessful();

        $this->artisan('billing:gateways')->expectsOutputToContain('mtn_momo')->assertSuccessful();
        $this->artisan('billing:prune')->assertSuccessful();
    }
}

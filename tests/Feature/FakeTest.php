<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Feature;

use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Events\PaymentStatusChanged;
use Atannex\Billing\Events\PaymentSucceeded;
use Atannex\Billing\Exceptions\IdempotencyConflict;
use Atannex\Billing\Facades\Billing;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Tests\Fixtures\Order;
use Atannex\Billing\Tests\Fixtures\User;
use Atannex\Billing\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class FakeTest extends TestCase
{
    #[Test]
    public function the_fake_keeps_routing_real(): void
    {
        $fake = Billing::fake()->process();

        $payment = Billing::amount(5000)->phone('699123456')->charge();

        $this->assertSame('orange_money', $payment->gateway);
        $this->assertSame(PaymentStatus::Processing, $payment->status);
        $fake->assertCharged(fn (Payment $p) => $p->gateway === 'orange_money' && $p->amount === 5000);
        $fake->assertChargedTimes(1);
        $fake->assertNotCharged(fn (Payment $p) => $p->gateway === 'mtn_momo');
        Http::assertNothingSent();
    }

    #[Test]
    public function settle_moves_a_payment_like_the_provider_would(): void
    {
        $fake = Billing::fake()->process();
        $payment = Billing::amount(5000)->phone('677123456')->charge();
        Event::fake([PaymentSucceeded::class, PaymentStatusChanged::class]);

        $fake->settle($payment, PaymentStatus::Succeeded);

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        Event::assertDispatched(PaymentSucceeded::class);
        Event::assertDispatched(PaymentStatusChanged::class, fn ($e) => $e->from === PaymentStatus::Processing && $e->source === 'webhook');
    }

    #[Test]
    public function failures_carry_the_scripted_reason(): void
    {
        Billing::fake()->fail('insufficient_funds', 'Not enough money.');

        $payment = Billing::amount(5000)->charge();

        $this->assertTrue($payment->failed());
        $this->assertSame('insufficient_funds', $payment->failure_code);
        $this->assertSame('Not enough money.', $payment->failure_message);
    }

    #[Test]
    public function the_same_idempotency_key_returns_the_first_payment(): void
    {
        $fake = Billing::fake();

        $first = Billing::amount(5000)->idempotencyKey('order-1')->charge();
        $second = Billing::amount(5000)->idempotencyKey('order-1')->charge();

        $this->assertTrue($first->is($second));
        $fake->assertChargedTimes(1);

        $this->expectException(IdempotencyConflict::class);
        Billing::amount(9000)->idempotencyKey('order-1')->charge();
    }

    #[Test]
    public function billable_and_payable_models_fill_in_the_details(): void
    {
        Billing::fake();
        $user = User::query()->create(['name' => 'Ada Eyong', 'email' => 'ada@example.com', 'phone' => '677123456']);
        $order = Order::query()->create(['total' => 5000]);

        $payment = $user->charge(5000)->for($order)->charge();

        $this->assertSame('mtn_momo', $payment->gateway);
        $this->assertSame('Ada Eyong', $payment->customer_name);
        $this->assertSame('ada@example.com', $payment->customer_email);
        $this->assertTrue($payment->customer->is($user));
        $this->assertTrue($payment->payable->is($order));
        $this->assertTrue($order->isPaid());
        $this->assertSame(5000, $order->amountPaid('XAF')->amount);
        $this->assertTrue($order->latestPayment->is($payment));
        $this->assertCount(1, $user->payments);
    }

    #[Test]
    public function refunds_through_the_fake(): void
    {
        $fake = Billing::fake();
        $payment = Billing::amount(5000)->charge();

        Billing::refund($payment, 1000);
        $fake->assertRefunded(fn ($refund) => $refund->amount === 1000);

        $fake->refunds(RefundStatus::Failed);

        try {
            Billing::refund($payment, 1000);
        } catch (\Throwable) {
        }

        $this->assertSame(1000, $payment->refresh()->amount_refunded);
        $this->assertSame(4000, $payment->refundable()->amount + 0);
    }

    #[Test]
    public function the_status_endpoint_checks_open_payments_with_the_provider(): void
    {
        $fake = Billing::fake()->process();
        $payment = Billing::amount(5000)->phone('677123456')->charge();

        $this->getJson('/billing/payments/'.$payment->reference.'/status')
            ->assertOk()->assertJson(['status' => 'processing', 'open' => true, 'paid' => false]);

        $fake->provider[$payment->reference] = PaymentStatus::Succeeded;
        $this->travel(10)->seconds();

        $this->getJson('/billing/payments/'.$payment->reference.'/status')
            ->assertOk()->assertJson(['status' => 'succeeded', 'paid' => true, 'amount' => ['amount' => 5000, 'currency' => 'XAF']])
            ->assertJsonMissingPath('gateway_data');
    }

    #[Test]
    public function events_wait_for_the_outer_transaction(): void
    {
        Billing::fake();
        Event::fake([PaymentSucceeded::class]);

        DB::transaction(function () {
            Billing::amount(5000)->charge();
            Event::assertNotDispatched(PaymentSucceeded::class);
        });

        Event::assertDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function cancelling_an_open_payment(): void
    {
        Billing::fake()->requireAction();
        $payment = Billing::amount(5000)->charge();

        $this->assertNotNull($payment->redirectUrl());
        $this->assertSame(PaymentStatus::Cancelled, Billing::cancel($payment)->status);
        $this->assertSame(PaymentStatus::Cancelled, Billing::cancel($payment)->status);
    }

    #[Test]
    public function the_fake_driver_runs_the_whole_flow_locally(): void
    {
        config(['billing.gateways.fake' => ['driver' => 'fake', 'outcome' => 'requires_action']]);

        $payment = Billing::amount(5000)->via('fake')->returnTo('https://shop.test/thanks')->charge();

        $this->get($payment->redirectUrl())->assertRedirect('https://shop.test/thanks?payment='.$payment->reference.'&status=succeeded');
    }
}

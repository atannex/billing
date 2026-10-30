<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Feature;

use Atannex\Billing\Data\Snapshot;
use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Events\PaymentRefunded;
use Atannex\Billing\Events\PaymentStatusChanged;
use Atannex\Billing\Exceptions\GatewayException;
use Atannex\Billing\Exceptions\RefundNotAllowed;
use Atannex\Billing\Facades\Billing;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class RefundSafetyTest extends TestCase
{
    private string $refundReference = '';

    #[Test]
    public function a_timed_out_refund_stays_pending_and_reserved_until_reconciliation(): void
    {
        $this->fakeMtn(refund: Http::response('upstream timeout', 504));
        $payment = Billing::refresh(Billing::amount(5000)->phone('677123456')->charge());

        $refund = Billing::refund($payment, 2000);

        // MTN may have paid it out: never failed, its amount stays reserved.
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame(3000, $payment->refresh()->refundable()->amount);
        $this->assertTrue($payment->events()->where('type', 'refund_unconfirmed')->exists());

        // The X-Reference-Id was stored before the call, so it can be looked up.
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $refund->provider_reference);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/disbursement/v1_0/refund')
            && $request->hasHeader('X-Reference-Id', $refund->provider_reference));

        try {
            Billing::refund($payment, 4000);
            $this->fail('The pending refund must stay reserved.');
        } catch (RefundNotAllowed) {
        }

        $this->travel(1)->minutes();
        $this->artisan('billing:reconcile')->assertSuccessful();

        $this->assertSame(RefundStatus::Succeeded, $refund->refresh()->status);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->refresh()->status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/disbursement/v1_0/refund/'.$refund->provider_reference));
    }

    #[Test]
    public function a_refused_refund_fails_and_frees_the_amount(): void
    {
        $this->fakeMtn(refund: Http::response(['message' => 'Bad request'], 400));
        $payment = Billing::refresh(Billing::amount(5000)->phone('677123456')->charge());

        try {
            Billing::refund($payment, 2000);
            $this->fail('A refused refund throws.');
        } catch (GatewayException) {
        }

        $this->assertSame(RefundStatus::Failed, $payment->refunds()->first()->status);
        $this->assertSame(5000, $payment->refresh()->refundable()->amount);
    }

    #[Test]
    public function a_refund_lost_in_transit_is_found_at_stripe_by_its_reference(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                $request->method() === 'POST' && str_ends_with($request->url(), '/v1/checkout/sessions') => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/x', 'status' => 'open']),
                $request->method() === 'POST' && str_ends_with($request->url(), '/v1/refunds') => Http::response('bad gateway', 502),
                str_contains($request->url(), '/v1/refunds?') => Http::response(['data' => [
                    ['id' => 're_other', 'status' => 'succeeded', 'metadata' => ['billing_refund' => 'RF-SOMETHING-ELSE']],
                    ['id' => 're_lost', 'status' => 'succeeded', 'metadata' => ['billing_refund' => $this->refundReference]],
                ]]),
                default => Http::response(['error' => ['message' => 'unexpected']], 404),
            };
        });

        $payment = Billing::amount(25, 'USD')->via('stripe')->charge();
        Billing::processor()->apply($payment, new Snapshot(PaymentStatus::Succeeded, amount: $payment->money(), data: ['payment_intent' => 'pi_1']), 'test');

        $refund = Billing::refund($payment, 10);
        $this->refundReference = $refund->reference;

        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertNull($refund->provider_reference);

        $this->travel(1)->minutes();
        $this->artisan('billing:reconcile')->assertSuccessful();

        $refund->refresh();
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame('re_lost', $refund->provider_reference);
        $this->assertSame(1000, $payment->refresh()->amount_refunded);
    }

    #[Test]
    public function a_second_partial_refund_does_not_log_a_status_change(): void
    {
        Billing::fake();
        $payment = Billing::amount(5000)->charge();
        Event::fake([PaymentStatusChanged::class, PaymentRefunded::class]);

        Billing::refund($payment, 1000);
        Billing::refund($payment, 1000);

        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->refresh()->status);
        $this->assertSame(2000, $payment->amount_refunded);
        Event::assertDispatchedTimes(PaymentStatusChanged::class, 1);
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
        $this->assertSame(1, $payment->events()->where('type', 'status_changed')->where('to_status', 'partially_refunded')->count());
    }

    #[Test]
    public function money_and_state_are_not_mass_assignable(): void
    {
        $payment = new Payment(['reference' => 'PAY-X', 'status' => 'succeeded', 'amount' => 1, 'amount_refunded' => 0, 'paid_at' => now(), 'gateway_data' => ['x' => 1]]);

        $this->assertSame('PAY-X', $payment->reference);
        $this->assertNull($payment->getAttribute('status'));
        $this->assertNull($payment->getAttribute('amount'));
        $this->assertNull($payment->getAttribute('paid_at'));
        $this->assertNull($payment->getAttribute('gateway_data'));
    }

    private function fakeMtn(mixed $refund): void
    {
        Http::fake([
            'momo.test/collection/token/' => Http::response(['access_token' => 'momo-token', 'expires_in' => 3600]),
            'momo.test/collection/v1_0/requesttopay' => Http::response(null, 202),
            'momo.test/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => '5000', 'currency' => 'XAF']),
            'momo.test/disbursement/token/' => Http::response(['access_token' => 'dis-token', 'expires_in' => 3600]),
            'momo.test/disbursement/v1_0/refund' => $refund,
            'momo.test/disbursement/v1_0/refund/*' => Http::response(['status' => 'SUCCESSFUL']),
        ]);
    }
}

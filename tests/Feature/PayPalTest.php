<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Feature;

use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Facades\Billing;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class PayPalTest extends TestCase
{
    #[Test]
    public function it_creates_an_order_and_captures_it_when_the_payer_returns(): void
    {
        $payment = $this->order();

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('ORDER-1', $payment->provider_reference);
        $this->assertSame('https://paypal.test/checkoutnow?token=ORDER-1', $payment->redirectUrl());

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
            && $request['purchase_units'][0]['amount'] === ['currency_code' => 'EUR', 'value' => '49.90']
            && $request['purchase_units'][0]['custom_id'] === $payment->reference
            && $request->hasHeader('PayPal-Request-Id', 'billing-'.$payment->reference));

        // PayPal appends its own query string to the return URL.
        $this->get('/billing/return/'.$payment->reference.'?token=ORDER-1&PayerID=P1')->assertRedirect();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame('CAP-1', $payment->gatewayValue('capture_id'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/ORDER-1/capture'));
    }

    #[Test]
    public function an_approved_order_is_captured_by_reconciliation_when_the_tab_was_closed(): void
    {
        $payment = $this->order();

        $this->travel(1)->minutes();
        $this->artisan('billing:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
    }

    #[Test]
    public function webhooks_are_verified_with_paypal_then_checked(): void
    {
        $payment = $this->order(verification: 'FAILURE');

        $this->postJson('/billing/webhooks/paypal', ['id' => 'WH-EVT-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORDER-1']], ['PAYPAL-TRANSMISSION-SIG' => 'sig'])
            ->assertForbidden();

        $this->assertSame(PaymentStatus::RequiresAction, $payment->refresh()->status);
    }

    #[Test]
    public function a_verified_webhook_settles_the_payment(): void
    {
        $payment = $this->order();

        $this->postJson('/billing/webhooks/paypal', ['id' => 'WH-EVT-2', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORDER-1']], [
            'PAYPAL-TRANSMISSION-SIG' => 'sig', 'PAYPAL-TRANSMISSION-ID' => 't1', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        ])->assertOk()->assertJson(['status' => 'processed']);

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
    }

    #[Test]
    public function it_refunds_the_capture(): void
    {
        $payment = Billing::refresh($this->order());

        $refund = Billing::refund($payment, '9.90', 'Late delivery');

        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->refresh()->status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/payments/captures/CAP-1/refund')
            && $request['amount'] === ['value' => '9.90', 'currency_code' => 'EUR']);
    }

    #[Test]
    public function paypal_is_not_routed_currencies_it_cannot_settle(): void
    {
        $this->assertNotContains('paypal', Billing::router()->candidates('XAF', 'wallet'));
        $this->assertContains('paypal', Billing::router()->candidates('EUR', 'wallet'));
    }

    private function order(string $verification = 'SUCCESS'): Payment
    {
        Http::fake([
            'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'pp-token', 'expires_in' => 32400]),
            'paypal.test/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'self', 'href' => 'https://paypal.test/v2/checkout/orders/ORDER-1'], ['rel' => 'payer-action', 'href' => 'https://paypal.test/checkoutnow?token=ORDER-1']]], 201),
            'paypal.test/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            'paypal.test/v2/checkout/orders/ORDER-1/capture' => Http::response(['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '49.90']]]]]]], 201),
            'paypal.test/v2/payments/captures/CAP-1/refund' => Http::response(['id' => 'REF-1', 'status' => 'COMPLETED'], 201),
            'paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $verification]),
        ]);

        return Billing::amount('49.90', 'EUR')->method('wallet')->via('paypal')->description('Annual plan')->charge();
    }
}

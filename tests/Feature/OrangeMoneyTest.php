<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Feature;

use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Facades\Billing;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class OrangeMoneyTest extends TestCase
{
    #[Test]
    public function webpay_redirects_an_orange_number_to_orange(): void
    {
        $payment = $this->webpayPayment();

        $this->assertSame('orange_money', $payment->gateway);
        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('https://orange.test/pay/abc', $payment->redirectUrl());
        $this->assertSame('pt-1', $payment->provider_reference);
        $this->assertSame('nt-secret', $payment->gatewayValue('notif_token'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://orange.test/orange-money-webpay/cm/v1/webpayment'
            && $request->hasHeader('Authorization', 'Bearer om-token')
            && $request['merchant_key'] === 'mk-1'
            && $request['order_id'] === $payment->reference
            && $request['amount'] === 3000
            && str_contains($request['notif_url'], '/billing/webhooks/orange_money/'.$payment->reference));
    }

    #[Test]
    public function a_notification_needs_the_notif_token_issued_for_the_payment(): void
    {
        $payment = $this->webpayPayment();

        $this->postJson('/billing/webhooks/orange_money/'.$payment->reference, ['status' => 'SUCCESS', 'notif_token' => 'guess', 'txnid' => 'MP1'])
            ->assertForbidden();

        $this->postJson('/billing/webhooks/orange_money', ['status' => 'SUCCESS', 'notif_token' => 'nt-secret', 'txnid' => 'MP1'])
            ->assertForbidden();

        $this->assertSame(PaymentStatus::RequiresAction, $payment->refresh()->status);
    }

    #[Test]
    public function a_genuine_notification_is_confirmed_with_orange(): void
    {
        $payment = $this->webpayPayment(status: 'SUCCESS');

        $this->postJson('/billing/webhooks/orange_money/'.$payment->reference, ['status' => 'SUCCESS', 'notif_token' => 'nt-secret', 'txnid' => 'MP1'])
            ->assertOk()->assertJson(['status' => 'processed']);

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transactionstatus')
            && $request['pay_token'] === 'pt-1'
            && $request['order_id'] === $payment->reference
            && $request['amount'] === 3000);
    }

    #[Test]
    public function the_return_page_checks_and_redirects(): void
    {
        $payment = $this->webpayPayment(status: 'SUCCESS', returnUrl: 'https://shop.test/orders/7');

        $this->get('/billing/return/'.$payment->reference)
            ->assertRedirect('https://shop.test/orders/7?payment='.$payment->reference.'&status=succeeded');
    }

    #[Test]
    public function merchant_mode_pushes_a_ussd_prompt(): void
    {
        config(['billing.gateways.orange_money.mode' => 'merchant']);
        Billing::forgetGateways();

        Http::fake([
            'omcm.test/token' => Http::response(['access_token' => 'om-merchant', 'expires_in' => 3600]),
            'omcm.test/omcoreapis/1.0.2/mp/init' => Http::response(['message' => 'Payment request successfully initiated', 'data' => ['payToken' => 'MP2201']]),
            'omcm.test/omcoreapis/1.0.2/mp/pay' => Http::response(['message' => 'Merchant payment successfully initiated', 'data' => ['status' => 'PENDING', 'payToken' => 'MP2201']]),
            'omcm.test/omcoreapis/1.0.2/mp/paymentstatus/MP2201' => Http::response(['data' => ['status' => 'SUCCESSFULL', 'amount' => '1500']]),
        ]);

        $payment = Billing::amount(1500)->phone('+237 699 00 11 22')->charge();

        $this->assertSame(PaymentStatus::Processing, $payment->status);
        $this->assertSame('MP2201', $payment->provider_reference);
        $this->assertNull($payment->redirectUrl());

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/mp/pay')
            && $request->hasHeader('X-AUTH-TOKEN', base64_encode('u:p'))
            && $request['subscriberMsisdn'] === '699001122'
            && $request['channelUserMsisdn'] === '691000000'
            && $request['amount'] === '1500'
            && $request['payToken'] === 'MP2201');

        $this->assertSame(PaymentStatus::Succeeded, Billing::refresh($payment)->status);
    }

    private function webpayPayment(string $status = 'INITIATED', ?string $returnUrl = null): Payment
    {
        Http::fake([
            'orange.test/oauth/v3/token' => Http::response(['access_token' => 'om-token', 'expires_in' => 7776000]),
            'orange.test/orange-money-webpay/cm/v1/webpayment' => Http::response(['status' => 201, 'message' => 'OK', 'pay_token' => 'pt-1', 'payment_url' => 'https://orange.test/pay/abc', 'notif_token' => 'nt-secret'], 201),
            'orange.test/orange-money-webpay/cm/v1/transactionstatus' => Http::response(['status' => $status, 'order_id' => 'x', 'txnid' => 'MP1']),
        ]);

        return Billing::amount(3000)->phone('699123456')->returnTo($returnUrl)->charge();
    }
}

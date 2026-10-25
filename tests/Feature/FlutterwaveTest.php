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

class FlutterwaveTest extends TestCase
{
    #[Test]
    public function it_returns_a_hosted_payment_link(): void
    {
        $payment = $this->payment(['status' => 'successful', 'amount' => 10000, 'currency' => 'XAF', 'id' => 4421]);

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('https://checkout.flutterwave.test/v3/hosted/pay/abc', $payment->redirectUrl());

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v3/payments')
            && $request['tx_ref'] === $payment->reference
            && $request['amount'] === '10000'
            && $request['currency'] === 'XAF'
            && $request['customer']['email'] === 'ngozi@example.com');
    }

    #[Test]
    public function a_webhook_with_the_secret_hash_is_re_verified(): void
    {
        $payment = $this->payment(['status' => 'successful', 'amount' => 10000, 'currency' => 'XAF', 'id' => 4421]);

        $this->postJson('/billing/webhooks/flutterwave', ['event' => 'charge.completed', 'data' => ['id' => 4421, 'tx_ref' => $payment->reference, 'status' => 'successful']], ['verif-hash' => 'wrong'])
            ->assertForbidden();

        $this->postJson('/billing/webhooks/flutterwave', ['event' => 'charge.completed', 'data' => ['id' => 4421, 'tx_ref' => $payment->reference, 'status' => 'successful']], ['verif-hash' => 'flw-hash'])
            ->assertOk()->assertJson(['status' => 'processed']);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(4421, $payment->gatewayValue('transaction_id'));
    }

    #[Test]
    public function a_verified_success_for_less_money_is_refused(): void
    {
        $payment = Billing::refresh($this->payment(['status' => 'successful', 'amount' => 100, 'currency' => 'XAF', 'id' => 1]));

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('amount_mismatch', $payment->failure_code);
    }

    #[Test]
    public function an_untouched_link_stays_open(): void
    {
        Http::fake([
            'flw.test/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/x']]),
            'flw.test/v3/transactions/verify_by_reference*' => Http::response(['status' => 'error', 'message' => 'No transaction was found for this id'], 400),
        ]);

        $payment = Billing::refresh(Billing::amount(10000)->via('flutterwave')->email('a@b.c')->charge());

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function payment(array $transaction): Payment
    {
        Http::fake([
            'flw.test/v3/payments' => Http::response(['status' => 'success', 'message' => 'Hosted Link', 'data' => ['link' => 'https://checkout.flutterwave.test/v3/hosted/pay/abc']]),
            'flw.test/v3/transactions/verify_by_reference*' => Http::response(['status' => 'success', 'data' => $transaction]),
        ]);

        return Billing::amount(10000)->via('flutterwave')->email('ngozi@example.com')->name('Ngozi')->charge();
    }
}

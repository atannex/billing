<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Feature;

use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Events\PaymentFailed;
use Atannex\Billing\Events\PaymentSucceeded;
use Atannex\Billing\Exceptions\InvalidMsisdn;
use Atannex\Billing\Facades\Billing;
use Atannex\Billing\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class MtnMomoTest extends TestCase
{
    #[Test]
    public function it_pushes_a_request_to_pay_to_an_mtn_number(): void
    {
        $this->fakeMtn();

        $payment = Billing::amount(5000)->phone('677 12 34 56')->description('Abonnement')->charge();

        $this->assertSame('mtn_momo', $payment->gateway);
        $this->assertSame(PaymentStatus::Processing, $payment->status);
        $this->assertSame('237677123456', $payment->customer_phone);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $payment->provider_reference);
        $this->assertSame($payment->provider_reference, $payment->gatewayValue('reference_id'));
        $this->assertNotNull($payment->expires_at);

        Http::assertSent(function (Request $request) use ($payment) {
            return $request->url() === 'https://momo.test/collection/v1_0/requesttopay'
                && $request->hasHeader('X-Reference-Id', $payment->provider_reference)
                && $request->hasHeader('X-Target-Environment', 'mtncameroon')
                && $request->hasHeader('Ocp-Apim-Subscription-Key', 'sub-key')
                && $request->hasHeader('Authorization', 'Bearer momo-token')
                && str_contains($request->header('X-Callback-Url')[0], '/billing/webhooks/mtn_momo/'.$payment->reference)
                && $request['amount'] === '5000'
                && $request['currency'] === 'XAF'
                && $request['externalId'] === $payment->reference
                && $request['payer'] === ['partyIdType' => 'MSISDN', 'partyId' => '237677123456'];
        });
    }

    #[Test]
    public function the_token_is_cached_between_payments(): void
    {
        $this->fakeMtn();

        Billing::amount(100)->phone('677123456')->charge();
        Billing::amount(200)->phone('677123457')->charge();

        Http::assertSentCount(3);
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/collection/token/')));
    }

    #[Test]
    public function an_unsigned_callback_only_triggers_a_status_check(): void
    {
        $this->fakeMtn(status: ['status' => 'SUCCESSFUL', 'amount' => '5000', 'currency' => 'XAF', 'financialTransactionId' => '987654']);
        $payment = Billing::amount(5000)->phone('677123456')->charge();
        Event::fake([PaymentSucceeded::class]);

        // The body claims success; billing asks MTN rather than believing it.
        $this->putJson('/billing/webhooks/mtn_momo/'.$payment->reference, ['externalId' => $payment->reference, 'status' => 'SUCCESSFUL', 'amount' => '5'])
            ->assertOk()->assertJson(['status' => 'processed']);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame('987654', $payment->gatewayValue('financial_transaction_id'));
        Event::assertDispatched(PaymentSucceeded::class);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_ends_with($request->url(), '/requesttopay/'.$payment->provider_reference));
    }

    #[Test]
    public function a_declined_prompt_fails_the_payment_with_a_readable_reason(): void
    {
        $this->fakeMtn(status: ['status' => 'FAILED', 'reason' => 'APPROVAL_REJECTED']);
        $payment = Billing::amount(5000)->phone('677123456')->charge();
        Event::fake([PaymentFailed::class]);

        $payment = Billing::refresh($payment);

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('approval_rejected', $payment->failure_code);
        $this->assertSame('The payer declined the payment.', $payment->failure_message);
        Event::assertDispatched(PaymentFailed::class);
    }

    #[Test]
    public function a_timed_out_request_stays_open_for_reconciliation(): void
    {
        Http::fake([
            'momo.test/collection/token/' => Http::response(['access_token' => 'momo-token', 'expires_in' => 3600]),
            'momo.test/collection/v1_0/requesttopay' => Http::response('upstream timeout', 504),
        ]);

        $payment = Billing::amount(5000)->phone('677123456')->charge();

        $this->assertSame(PaymentStatus::Processing, $payment->status);
        $this->assertNotNull($payment->provider_reference);
        $this->assertTrue($payment->events()->where('type', 'unconfirmed')->exists());
    }

    #[Test]
    public function an_mtn_gateway_needs_a_valid_number(): void
    {
        $this->expectException(InvalidMsisdn::class);

        Billing::amount(5000)->via('mtn_momo')->charge();
    }

    #[Test]
    public function an_expired_token_is_renewed_once(): void
    {
        Http::fake([
            'momo.test/collection/token/' => Http::sequence()
                ->push(['access_token' => 'old', 'expires_in' => 3600])
                ->push(['access_token' => 'new', 'expires_in' => 3600]),
            'momo.test/collection/v1_0/requesttopay' => Http::sequence()
                ->push(['message' => 'Access token expired'], 401)
                ->push(null, 202),
        ]);

        $payment = Billing::amount(5000)->phone('677123456')->charge();

        $this->assertSame(PaymentStatus::Processing, $payment->status);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer new'));
    }

    #[Test]
    public function refunds_go_through_disbursement(): void
    {
        $this->fakeMtn(status: ['status' => 'SUCCESSFUL', 'amount' => '5000', 'currency' => 'XAF']);
        Http::fake([
            'momo.test/disbursement/token/' => Http::response(['access_token' => 'dis-token', 'expires_in' => 3600]),
            'momo.test/disbursement/v1_0/refund' => Http::response(null, 202),
            'momo.test/disbursement/v1_0/refund/*' => Http::response(['status' => 'SUCCESSFUL']),
        ]);

        $payment = Billing::refresh(Billing::amount(5000)->phone('677123456')->charge());
        $refund = Billing::refund($payment, 2000);

        $this->assertSame(RefundStatus::Pending, $refund->status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/disbursement/v1_0/refund')
            && $request['referenceIdToRefund'] === $payment->provider_reference
            && $request['amount'] === '2000'
            && $request->hasHeader('Ocp-Apim-Subscription-Key', 'dis-key'));

        $this->travel(1)->minutes();
        $this->artisan('billing:reconcile')->assertSuccessful();

        $this->assertSame(RefundStatus::Succeeded, $refund->refresh()->status);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function fakeMtn(array $status = ['status' => 'PENDING']): void
    {
        Http::fake([
            'momo.test/collection/token/' => Http::response(['access_token' => 'momo-token', 'expires_in' => 3600]),
            'momo.test/collection/v1_0/requesttopay' => Http::response(null, 202),
            'momo.test/collection/v1_0/requesttopay/*' => Http::response($status),
        ]);
    }
}

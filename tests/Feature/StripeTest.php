<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Feature;

use Atannex\Billing\Data\Snapshot;
use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Events\AmountMismatch;
use Atannex\Billing\Events\PaymentInitiated;
use Atannex\Billing\Events\PaymentRefunded;
use Atannex\Billing\Events\PaymentSucceeded;
use Atannex\Billing\Exceptions\RefundNotAllowed;
use Atannex\Billing\Facades\Billing;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\WebhookCall;
use Atannex\Billing\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

class StripeTest extends TestCase
{
    #[Test]
    public function it_creates_a_checkout_session(): void
    {
        Event::fake([PaymentInitiated::class]);
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'status' => 'open'])]);

        $payment = Billing::amount('25.00', 'USD')->description('T-shirt')->email('ada@example.com')->charge();

        $this->assertSame('stripe', $payment->gateway);
        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('cs_test_1', $payment->provider_reference);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $payment->redirectUrl());
        $this->assertNotNull($payment->next_check_at);
        Event::assertDispatched(PaymentInitiated::class);

        Http::assertSent(function (Request $request) use ($payment) {
            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request->hasHeader('Authorization', 'Bearer sk_test_123')
                && str_starts_with($request->header('Idempotency-Key')[0], 'billing:')
                && $request['line_items'][0]['price_data']['unit_amount'] == 2500
                && $request['line_items'][0]['price_data']['currency'] === 'usd'
                && $request['metadata']['billing_reference'] === $payment->reference
                && $request['customer_email'] === 'ada@example.com'
                && str_contains($request['success_url'], '/billing/return/'.$payment->reference);
        });
    }

    #[Test]
    public function zero_decimal_currencies_are_sent_as_is(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_2', 'url' => 'https://checkout.stripe.com/x'])]);

        Billing::amount(5000, 'XAF')->via('stripe')->charge();

        Http::assertSent(fn (Request $request) => $request['line_items'][0]['price_data']['unit_amount'] == 5000);
    }

    #[Test]
    public function a_signed_webhook_settles_the_payment(): void
    {
        $payment = $this->checkoutSession();
        Event::fake([PaymentSucceeded::class]);

        $response = $this->stripeWebhook([
            'id' => 'evt_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
                'amount_total' => 2500, 'currency' => 'usd', 'payment_intent' => 'pi_1',
                'metadata' => ['billing_reference' => $payment->reference],
            ]],
        ]);

        $response->assertOk()->assertJson(['status' => 'processed']);
        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertNull($payment->next_check_at);
        $this->assertSame('pi_1', $payment->gatewayValue('payment_intent'));
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);

        // Stripe retries: the second delivery is answered, not reprocessed.
        $this->stripeWebhook(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['metadata' => ['billing_reference' => $payment->reference]]]])
            ->assertOk()->assertJson(['status' => 'duplicate']);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        $this->assertSame(1, WebhookCall::query()->count());
    }

    #[Test]
    public function an_unsigned_or_stale_webhook_is_refused(): void
    {
        $payment = $this->checkoutSession();
        $payload = json_encode(['id' => 'evt_x', 'type' => 'checkout.session.completed', 'data' => ['object' => ['payment_status' => 'paid', 'metadata' => ['billing_reference' => $payment->reference]]]]);

        $this->call('POST', '/billing/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=forged'], $payload)
            ->assertForbidden();

        $old = time() - 3600;
        $signature = hash_hmac('sha256', $old.'.'.$payload, 'whsec_test');
        $this->call('POST', '/billing/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$old},v1={$signature}"], $payload)
            ->assertForbidden();

        $this->assertSame(PaymentStatus::RequiresAction, $payment->refresh()->status);
    }

    #[Test]
    public function a_success_for_another_amount_is_not_believed(): void
    {
        $payment = $this->checkoutSession();
        Event::fake([AmountMismatch::class, PaymentSucceeded::class]);

        $this->stripeWebhook([
            'id' => 'evt_2',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_1', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 100, 'currency' => 'usd', 'metadata' => ['billing_reference' => $payment->reference]]],
        ])->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('amount_mismatch', $payment->failure_code);
        Event::assertDispatched(AmountMismatch::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
        $this->assertTrue($payment->events()->where('type', 'amount_mismatch')->exists());
    }

    #[Test]
    public function it_refunds_part_of_a_payment_then_the_rest(): void
    {
        $payment = $this->checkoutSession();
        Billing::processor()->apply($payment, new Snapshot(PaymentStatus::Succeeded, data: ['payment_intent' => 'pi_1']), 'test');
        Event::fake([PaymentRefunded::class]);

        Http::fake(['api.stripe.com/v1/refunds' => Http::sequence()
            ->push(['id' => 're_1', 'status' => 'succeeded'])
            ->push(['id' => 're_2', 'status' => 'succeeded'])]);

        $refund = Billing::refund($payment, '10.00', 'Wrong size');

        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame('re_1', $refund->provider_reference);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->refresh()->status);
        $this->assertSame(1000, $payment->amount_refunded);
        $this->assertSame(1500, $payment->refundable()->amount);
        Http::assertSent(fn (Request $request) => $request['payment_intent'] === 'pi_1' && $request['amount'] == 1000);

        Billing::refund($payment);

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertSame(0, $payment->refundable()->amount);
        $this->assertTrue($payment->net()->isZero());
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
    }

    #[Test]
    public function it_cannot_refund_more_than_was_paid(): void
    {
        $payment = $this->checkoutSession();
        Billing::processor()->apply($payment, new Snapshot(PaymentStatus::Succeeded), 'test');

        $this->expectException(RefundNotAllowed::class);

        Billing::refund($payment, '30.00');
    }

    #[Test]
    public function intent_mode_returns_a_client_secret(): void
    {
        config(['billing.gateways.stripe.mode' => 'intent']);
        Billing::forgetGateways();
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_9', 'client_secret' => 'pi_9_secret_x', 'status' => 'requires_payment_method', 'amount' => 2500, 'currency' => 'usd'])]);

        $payment = Billing::amount(25, 'USD')->via('stripe')->charge();

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertSame('pi_9_secret_x', $payment->clientSecret());
        $this->assertArrayNotHasKey('gateway_data', $payment->toArray());
        $this->assertSame('[redacted]', $payment->events()->first()->data['provider']['client_secret']);
    }

    private function checkoutSession(): Payment
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/x', 'status' => 'open'])]);

        return Billing::amount(25, 'USD')->via('stripe')->charge();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function stripeWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        return $this->call('POST', '/billing/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }
}

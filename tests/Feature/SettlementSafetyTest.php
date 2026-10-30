<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Feature;

use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Events\PaymentInitiated;
use Atannex\Billing\Events\PaymentStatusChanged;
use Atannex\Billing\Events\PaymentSucceeded;
use Atannex\Billing\Exceptions\BillingException;
use Atannex\Billing\Facades\Billing;
use Atannex\Billing\Gateways\FakeGateway;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\WebhookCall;
use Atannex\Billing\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class SettlementSafetyTest extends TestCase
{
    /** @var array<string, mixed>|null what MTN reports from now on, in place of fakeMtn()'s */
    private ?array $mtnStatus = null;

    // Expiry and late successes (reconciliation) -------------------------

    #[Test]
    public function a_failed_check_never_expires_a_payment(): void
    {
        $fake = Billing::fake()->process();
        $payment = Billing::amount(5000)->phone('677123456')->charge();

        $this->travel(11)->minutes();
        $fake->unavailable();
        $this->artisan('billing:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Processing, $payment->refresh()->status, 'Nothing was learned, so nothing is closed.');

        $fake->unavailable(false);
        $this->travel(3)->minutes();
        $this->artisan('billing:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Expired, $payment->refresh()->status);
    }

    #[Test]
    public function a_payment_that_cannot_be_called_off_is_checked_again_before_it_expires(): void
    {
        $session = ['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/x', 'status' => 'open'];
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response($session),
            'api.stripe.com/v1/checkout/sessions/cs_1/expire' => Http::response(['error' => ['message' => 'Only open sessions can be expired.']], 400),
            'api.stripe.com/v1/checkout/sessions/cs_1' => Http::sequence()
                ->push($session)
                ->push([...$session, 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 2500, 'currency' => 'usd']),
        ]);

        $payment = Billing::amount(25, 'USD')->via('stripe')->charge();
        $this->travel(1441)->minutes();
        $this->artisan('billing:reconcile')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertTrue($payment->events()->where('type', 'cancel_failed')->exists());
    }

    #[Test]
    public function a_locally_expired_payment_is_watched_for_a_late_success(): void
    {
        $fake = Billing::fake()->process();
        $payment = Billing::amount(5000)->phone('677123456')->charge();
        $this->travel(11)->minutes();
        $this->artisan('billing:reconcile');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertNotNull($payment->next_check_at, 'Still checked, slowly.');
        $this->assertTrue($payment->next_check_at->between(now()->addMinutes(14), now()->addMinutes(16)));

        // The payer approved the prompt after all.
        $fake->provider[$payment->reference] = PaymentStatus::Succeeded;
        Event::fake([PaymentSucceeded::class]);
        $this->travel(16)->minutes();
        $this->artisan('billing:reconcile');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNull($payment->next_check_at);
        $this->assertTrue($payment->events()->where('type', 'late_success')->exists());
        Event::assertDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function the_watch_stops_when_the_provider_confirms_or_the_window_ends(): void
    {
        $fake = Billing::fake()->process();
        $confirmed = Billing::amount(5000)->phone('677123456')->charge();
        $silent = Billing::amount(4000)->phone('677123457')->charge();
        $this->travel(11)->minutes();
        $this->artisan('billing:reconcile');

        // The fake took the cancel; the provider now says so itself.
        $this->travel(16)->minutes();
        $fake->provider[$silent->reference] = PaymentStatus::Processing;
        $this->artisan('billing:reconcile');

        $this->assertSame(PaymentStatus::Expired, $confirmed->refresh()->status);
        $this->assertNull($confirmed->next_check_at);
        $this->assertNotNull($silent->refresh()->next_check_at);
        $this->assertFalse($silent->events()->where('type', 'stale')->exists(), 'Still pending at the provider is not news.');

        $this->travel(1441)->minutes();
        $this->artisan('billing:reconcile');

        $this->assertSame(PaymentStatus::Expired, $silent->refresh()->status);
        $this->assertNull($silent->next_check_at);
    }

    // Unverified amounts -------------------------------------------------

    #[Test]
    public function a_success_without_an_amount_is_not_believed(): void
    {
        $this->fakeMtn(['status' => 'SUCCESSFUL']);
        Event::fake([PaymentSucceeded::class]);

        $payment = Billing::refresh(Billing::amount(5000)->phone('677123456')->charge());

        $this->assertSame(PaymentStatus::Processing, $payment->status);
        $this->assertTrue($payment->events()->where('type', 'amount_unverified')->exists());
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    #[Test]
    public function provider_amounts_are_parsed_exactly(): void
    {
        $this->fakeMtn(['status' => 'SUCCESSFUL', 'amount' => '5000.4', 'currency' => 'XAF']);
        $payment = Billing::refresh(Billing::amount(5000)->phone('677123456')->charge());

        // 5000.4 XAF is no XAF amount: not rounded into a match.
        $this->assertSame(PaymentStatus::Processing, $payment->status);

        $this->mtnStatus = ['status' => 'SUCCESSFUL', 'amount' => '5000.00', 'currency' => 'XAF'];

        $this->assertSame(PaymentStatus::Succeeded, Billing::refresh($payment)->status);
    }

    #[Test]
    public function a_completed_paypal_order_without_a_capture_is_checked_again(): void
    {
        Http::fake([
            'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'pp-token', 'expires_in' => 32400]),
            'paypal.test/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/checkoutnow?token=ORDER-1']]], 201),
            'paypal.test/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'COMPLETED']),
        ]);

        $payment = Billing::refresh(Billing::amount('49.90', 'EUR')->via('paypal')->charge());

        $this->assertSame(PaymentStatus::Processing, $payment->status);
    }

    #[Test]
    public function a_capture_paypal_refuses_fails_the_payment(): void
    {
        Http::fake([
            'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'pp-token', 'expires_in' => 32400]),
            'paypal.test/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/checkoutnow?token=ORDER-1']]], 201),
            'paypal.test/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            'paypal.test/v2/checkout/orders/ORDER-1/capture' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'The instrument presented was declined.']]], 422),
        ]);

        $payment = Billing::refresh(Billing::amount('49.90', 'EUR')->via('paypal')->charge());

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('instrument_declined', $payment->failure_code);
    }

    #[Test]
    public function a_mapped_currency_in_a_stripe_webhook_is_read_back(): void
    {
        config(['billing.gateways.stripe.currency_map' => ['XAF' => 'EUR']]);
        Billing::forgetGateways();
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/x', 'status' => 'open'])]);
        $payment = Billing::amount(5000, 'XAF')->via('stripe')->charge();

        $payload = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_1', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 5000, 'currency' => 'eur',
            'metadata' => ['billing_reference' => $payment->reference],
        ]]]);
        $timestamp = time();

        $this->call('POST', '/billing/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test'),
        ], $payload)->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
    }

    // Initiation ---------------------------------------------------------

    #[Test]
    public function an_orange_push_that_timed_out_is_checked_not_failed(): void
    {
        config(['billing.gateways.orange_money.mode' => 'merchant', 'billing.routing.failover' => true]);
        Billing::forgetGateways();
        Http::fake([
            'omcm.test/token' => Http::response(['access_token' => 'om-merchant', 'expires_in' => 3600]),
            'omcm.test/omcoreapis/1.0.2/mp/init' => Http::response(['data' => ['payToken' => 'MP2201']]),
            'omcm.test/omcoreapis/1.0.2/mp/pay' => Http::response('gateway timeout', 504),
            'omcm.test/omcoreapis/1.0.2/mp/paymentstatus/MP2201' => Http::response(['data' => ['status' => 'SUCCESSFULL', 'amount' => '1500']]),
        ]);

        $payment = Billing::amount(1500)->phone('699123456')->charge();

        // The prompt may be on the phone: no failover, no failure.
        $this->assertSame('orange_money', $payment->gateway);
        $this->assertSame(PaymentStatus::Processing, $payment->status);
        $this->assertSame('MP2201', $payment->gatewayValue('pay_token'));
        $this->assertTrue($payment->events()->where('type', 'unconfirmed')->exists());

        $this->assertSame(PaymentStatus::Succeeded, Billing::refresh($payment)->status);
    }

    #[Test]
    public function payment_initiated_waits_for_the_commit(): void
    {
        Billing::fake();
        Event::fake([PaymentInitiated::class]);

        DB::transaction(function () {
            Billing::amount(5000)->charge();
            Event::assertNotDispatched(PaymentInitiated::class);
        });

        Event::assertDispatchedTimes(PaymentInitiated::class, 1);
    }

    #[Test]
    public function events_of_a_rolled_back_change_never_reach_the_next_one(): void
    {
        $fake = Billing::fake()->process();
        $first = Billing::amount(5000)->phone('677123456')->charge();
        $second = Billing::amount(4000)->phone('677123457')->charge();
        $processor = Billing::processor();

        $broken = true;
        Payment::saving(function (Payment $payment) use (&$broken, $first) {
            if ($broken && $payment->id === $first->id && $payment->status === PaymentStatus::Succeeded) {
                throw new RuntimeException('database went away');
            }
        });

        $fake->provider[$first->reference] = PaymentStatus::Succeeded;

        try {
            $processor->refresh($first, 'reconcile');
            $this->fail('The save was meant to fail.');
        } catch (RuntimeException) {
        }

        $broken = false;
        $seen = [];
        Event::listen(PaymentStatusChanged::class, function (PaymentStatusChanged $event) use (&$seen) {
            $seen[] = $event->payment->reference;
        });

        $fake->provider[$second->reference] = PaymentStatus::Failed;
        $processor->refresh($second, 'reconcile');

        $this->assertSame([$second->reference], $seen);
        $this->assertSame(PaymentStatus::Processing, $first->refresh()->status);
    }

    // Return page, status route, configuration ---------------------------

    #[Test]
    public function a_cancel_link_does_not_close_a_payment_the_provider_cannot_call_off(): void
    {
        Http::fake([
            'flw.test/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/x']]),
            'flw.test/v3/transactions/verify_by_reference*' => Http::response(['status' => 'error', 'message' => 'No transaction was found for this id'], 400),
        ]);
        $payment = Billing::amount(10000)->via('flutterwave')->email('a@b.c')->charge();

        $this->get('/billing/return/'.$payment->reference.'?outcome=cancel')->assertRedirect();

        $this->assertSame(PaymentStatus::RequiresAction, $payment->refresh()->status);
    }

    #[Test]
    public function a_cancel_link_still_cancels_where_the_provider_can(): void
    {
        Billing::fake()->requireAction();
        $payment = Billing::amount(5000)->charge();

        $this->get('/billing/return/'.$payment->reference.'?outcome=cancel')->assertRedirect();

        $this->assertSame(PaymentStatus::Cancelled, $payment->refresh()->status);
    }

    #[Test]
    public function the_status_route_shows_billing_words_not_the_providers(): void
    {
        Billing::fake()->fail('gateway_error', '[stripe] Invalid API key sk_live_*** (HTTP 401)');
        $payment = Billing::amount(5000)->charge();

        $this->getJson('/billing/payments/'.$payment->reference.'/status')
            ->assertOk()
            ->assertJson(['status' => 'failed', 'failure_message' => 'The payment did not go through.']);

        $this->assertStringContainsString('Invalid API key', (string) $payment->refresh()->failure_message, 'Kept for staff.');
    }

    #[Test]
    public function the_fake_driver_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        Billing::forgetGateways();

        try {
            try {
                Billing::gateway('fake');
                $this->fail('The fake driver must not run in production.');
            } catch (BillingException $e) {
                $this->assertStringContainsString('allow_fake_in_production', $e->getMessage());
            }

            config(['billing.allow_fake_in_production' => true]);
            $this->assertInstanceOf(FakeGateway::class, Billing::gateway('fake'));
        } finally {
            $this->app['env'] = 'testing';     // so teardown's migrations do not ask
        }
    }

    #[Test]
    public function the_flutterwave_secret_hash_is_not_stored(): void
    {
        $this->postJson('/billing/webhooks/flutterwave', ['event' => 'transfer.completed', 'data' => []], ['verif-hash' => 'flw-hash'])->assertOk();

        $headers = WebhookCall::query()->sole()->headers;
        $this->assertArrayNotHasKey('verif-hash', $headers);
        $this->assertStringNotContainsString('flw-hash', (string) json_encode($headers));
    }

    #[Test]
    public function flutterwave_verifies_by_transaction_id_once_known(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                str_ends_with($request->url(), '/v3/payments') => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/x']]),
                str_contains($request->url(), 'verify_by_reference') => Http::response(['status' => 'success', 'data' => ['id' => 77, 'tx_ref' => $request['tx_ref'], 'status' => 'pending']]),
                str_ends_with($request->url(), '/v3/transactions/77/verify') => Http::response(['status' => 'success', 'data' => [
                    'id' => 77, 'tx_ref' => Payment::query()->value('reference'), 'status' => 'successful', 'amount' => 10000, 'currency' => 'XAF',
                ]]),
                default => Http::response(['status' => 'error'], 404),
            };
        });

        $payment = Billing::refresh(Billing::amount(10000)->via('flutterwave')->email('a@b.c')->charge());
        $this->assertSame(PaymentStatus::Processing, $payment->status);

        $this->assertSame(PaymentStatus::Succeeded, Billing::refresh($payment)->status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v3/transactions/77/verify'));
    }

    #[Test]
    public function stripe_checkout_stays_inside_its_24_hour_limit(): void
    {
        $this->freezeSecond();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_2', 'url' => 'https://checkout.stripe.com/x'])]);

        Billing::amount(25, 'USD')->via('stripe')->charge();

        Http::assertSent(fn (Request $request) => (int) $request['expires_at'] === now()->addMinutes(1435)->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function fakeMtn(array $status): void
    {
        Http::fake([
            'momo.test/collection/token/' => Http::response(['access_token' => 'momo-token', 'expires_in' => 3600]),
            'momo.test/collection/v1_0/requesttopay' => Http::response(null, 202),
            'momo.test/collection/v1_0/requesttopay/*' => fn () => Http::response($this->mtnStatus ?? $status),
        ]);
    }
}

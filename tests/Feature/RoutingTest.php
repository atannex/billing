<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Feature;

use Atannex\Billing\Exceptions\GatewayUnavailable;
use Atannex\Billing\Exceptions\NoEligibleGateway;
use Atannex\Billing\Facades\Billing;
use Atannex\Billing\Support\Msisdn;
use Atannex\Billing\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class RoutingTest extends TestCase
{
    #[Test]
    public function the_phone_operator_picks_the_mobile_money_gateway(): void
    {
        $router = Billing::router();

        $this->assertSame('mtn_momo', $router->candidates('XAF', null, Msisdn::parse('677123456'))[0]);
        $this->assertSame('orange_money', $router->candidates('XAF', null, Msisdn::parse('699123456'))[0]);
        $this->assertSame('orange_money', $router->candidates('XAF', 'mobile_money', Msisdn::parse('655123456'))[0]);
    }

    #[Test]
    public function without_a_phone_mobile_money_is_skipped(): void
    {
        $this->assertSame(['stripe', 'flutterwave'], Billing::router()->candidates('XAF'));
        $this->assertSame(['stripe', 'flutterwave', 'paypal'], Billing::router()->candidates('USD', 'card'));
    }

    #[Test]
    public function the_method_narrows_the_choice(): void
    {
        $this->assertSame(['stripe', 'flutterwave'], Billing::router()->candidates('XAF', 'card', Msisdn::parse('677123456')));
        $this->assertSame(['flutterwave'], Billing::router()->candidates('XAF', 'mobile_money', Msisdn::parse('661234567')));
    }

    #[Test]
    public function disabled_gateways_are_not_routed(): void
    {
        config(['billing.gateways.mtn_momo.enabled' => false]);

        $this->assertNotContains('mtn_momo', Billing::router()->candidates('XAF', null, Msisdn::parse('677123456')));
    }

    #[Test]
    public function nothing_eligible_is_an_error(): void
    {
        $this->expectException(NoEligibleGateway::class);

        config(['billing.gateways.flutterwave.enabled' => false]);
        Billing::router()->candidates('XAF', 'mobile_money', Msisdn::parse('661234567'));
    }

    #[Test]
    public function an_unreachable_gateway_fails_over_when_allowed(): void
    {
        config(['billing.routing.failover' => true]);
        Http::fake([
            'api.stripe.com/*' => Http::response('Service Unavailable', 503),
            'flw.test/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.test/x']]),
        ]);

        $payment = Billing::amount(25, 'USD')->method('card')->email('a@b.c')->charge();

        $this->assertSame('flutterwave', $payment->gateway);
        $this->assertSame('https://checkout.flutterwave.test/x', $payment->redirectUrl());
        $this->assertTrue($payment->events()->where('type', 'failover')->exists());
    }

    #[Test]
    public function without_failover_the_payment_fails_and_says_why(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response('Service Unavailable', 503)]);

        try {
            Billing::amount(25, 'USD')->method('card')->charge();
            $this->fail('Expected GatewayUnavailable.');
        } catch (GatewayUnavailable $e) {
            $this->assertNotNull($e->payment);
            $this->assertSame('failed', $e->payment->status->value);
            $this->assertSame('gateway_unavailable', $e->payment->failure_code);
        }
    }

    #[Test]
    public function routing_can_be_switched_off(): void
    {
        config(['billing.routing.enabled' => false, 'billing.gateway' => 'flutterwave']);
        Http::fake(['flw.test/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://x.test']])]);

        $this->assertSame('flutterwave', Billing::amount(100)->phone('677123456')->charge()->gateway);
    }
}

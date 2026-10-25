# atannex/billing

[![Tests](https://github.com/atannex/billing/actions/workflows/tests.yml/badge.svg)](https://github.com/atannex/billing/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-2ea44f.svg)](LICENSE)

Payments for Laravel across **Stripe, MTN Mobile Money, Orange Money, PayPal and Flutterwave**, behind one API. It was built for Cameroon first (XAF, MTN and Orange numbers, mobile money that settles minutes later) and works the same for card payments anywhere.

```php
// An MTN number goes to MTN MoMo, an Orange number to Orange Money. Nothing else to choose.
$payment = Billing::amount(5000)->phone('677 12 34 56')->for($order)->charge();

$payment->status;          // PaymentStatus::Processing: the payer has a prompt on their phone

// A USD card payment goes to Stripe Checkout.
$payment = Billing::amount('49.90', 'USD')->method('card')->returnTo(route('orders.show', $order))->charge();

return redirect($payment->redirectUrl());
```

What you get:

- **One fluent API** for five providers, plus your own drivers through `Billing::extend()`. Every provider is called with Laravel's HTTP client, so there are no vendor SDKs to install.
- **Smart routing.** Each payment goes to the first enabled gateway that takes its currency and method and serves the payer's phone operator. Cameroonian prefixes are built in (MTN 67x/650–654/680–684, Orange 69x/655–659/640/685–689) and configurable. Optional **failover** moves to the next gateway when one is down, but never once a charge may have started.
- **A guarded state machine.** `pending → processing / requires_action → succeeded → partially_refunded → refunded`. A stale webhook can't reopen a settled payment. A late provider success can still land after a local expiry, because the money really arrived.
- **Amount verification.** A "success" for a different amount or currency is not believed. The payment fails with `amount_mismatch` and `AmountMismatch` fires.
- **Race-proof processing.** Webhooks, the return page, polling and reconciliation all go through one processor that locks the row. Events fire exactly once, after the commit.
- **Verified webhooks.** Stripe HMAC signatures with a timestamp tolerance, PayPal's verification API, Flutterwave's secret hash, and Orange's per-payment `notif_token`. MTN callbacks are unsigned, so they only trigger a status check with MTN. Duplicate deliveries are recorded once and answered without being reprocessed.
- **Automatic reconciliation** for asynchronous payments. Open payments are re-checked on a back-off (10s, 20s, 30s, 1m … 1h) until they settle or expire, so a lost callback never strands a payment. A request that timed out on its way to MTN is looked up instead of being charged twice.
- **Refunds**, full or partial, with over-refund protection that counts refunds still in flight. MTN refunds go through its Disbursement product.
- **Idempotency keys.** A double click or a retried job returns the first payment instead of charging again.
- **An audit ledger.** Every transition, failover, mismatch and refund is recorded with its source and the provider's answer, with secrets redacted.
- **Exact money.** Integer minor units with ISO 4217 decimals. `Money::of('19.99', 'USD')` is exactly 1999, and XAF has no cents.
- **A test fake.** `Billing::fake()` scripts provider outcomes, keeps real routing, and gives you `assertCharged()`, `settle()` and friends.
- **Tooling.** `billing:reconcile`, `billing:payment`, `billing:gateways`, `billing:prune`, and `billing:mtn-sandbox-user`.

## Install

```bash
composer require atannex/billing
php artisan vendor:publish --tag=billing-config
php artisan migrate
```

The provider is auto-discovered. It registers its tables (`billing_payments`, `billing_refunds`, `billing_payment_events`, `billing_webhook_calls`), three routes and a scheduled `billing:reconcile` every minute. Make sure the scheduler runs (`php artisan schedule:work` locally, or cron in production).

## Configure

Each gateway turns on when its credentials are present. `php artisan billing:gateways` shows what is enabled and in which routing order.

```dotenv
BILLING_CURRENCY=XAF
BILLING_COUNTRY=CM            # how local numbers like "677123456" are read
BILLING_PUBLIC_URL=           # e.g. an ngrok URL while developing, so providers can reach your callbacks

# Stripe
STRIPE_SECRET=sk_live_…
STRIPE_WEBHOOK_SECRET=whsec_…
STRIPE_MODE=checkout          # or "intent" to confirm with Stripe.js on your own page

# MTN Mobile Money (MoMo Open API)
MTN_MOMO_ENVIRONMENT=mtncameroon            # "sandbox" while testing
MTN_MOMO_BASE_URL=https://proxy.momoapi.mtn.com
MTN_MOMO_SUBSCRIPTION_KEY=…
MTN_MOMO_API_USER=…
MTN_MOMO_API_KEY=…
MTN_MOMO_DISBURSEMENT_KEY=                  # optional: enables refunds
MTN_MOMO_DISBURSEMENT_USER=
MTN_MOMO_DISBURSEMENT_API_KEY=

# Orange Money: "webpay" (redirect to Orange) or "merchant" (USSD push, Orange Cameroon API)
ORANGE_MONEY_MODE=webpay
ORANGE_MONEY_CLIENT_ID=…
ORANGE_MONEY_CLIENT_SECRET=…
ORANGE_MONEY_MERCHANT_KEY=…
ORANGE_MONEY_COUNTRY=cm                     # "dev" in the Orange sandbox

# PayPal
PAYPAL_CLIENT_ID=…
PAYPAL_CLIENT_SECRET=…
PAYPAL_WEBHOOK_ID=…
PAYPAL_BASE_URL=https://api-m.paypal.com

# Flutterwave
FLUTTERWAVE_SECRET=FLWSECK-…
FLUTTERWAVE_WEBHOOK_HASH=…
```

In the MTN sandbox, API users are created through the API rather than a dashboard:

```bash
php artisan billing:mtn-sandbox-user --host=your-tunnel.ngrok.app
```

The sandbox only accepts EUR, so `currency_map` sends `XAF` as `EUR` there and reads it back as `XAF`. The same mechanism maps `XAF → OUV` in the Orange sandbox.

### Callback URLs to give each provider

| Provider | URL |
|---|---|
| Stripe (dashboard → Webhooks) | `https://your-site/billing/webhooks/stripe` |
| PayPal (app → Webhooks) | `https://your-site/billing/webhooks/paypal` |
| Flutterwave (settings → Webhooks) | `https://your-site/billing/webhooks/flutterwave` |
| MTN, Orange | Sent with each payment (`…/webhooks/mtn_momo/PAY-…`); nothing to set up |

## Taking payments

```php
use Atannex\Billing\Facades\Billing;

$payment = Billing::amount(5000)               // major units, default currency (XAF)
    ->for($order)                              // what is paid for (morph)
    ->by($user)                                // who pays; Billable models fill name/email/phone
    ->phone('677 12 34 56')                    // any common format; drives operator routing
    ->description('Annual subscription')
    ->returnTo(route('orders.show', $order), route('cart'))   // after hosted pages
    ->idempotencyKey("order-{$order->id}")
    ->metadata(['plan' => 'annual'])
    ->charge();
```

Narrow the choice with `->method('card' | 'mobile_money' | 'wallet' | 'bank')`, or force a gateway with `->via('stripe')`. A forced gateway skips routing and failover. `Billing::minor(1250, 'USD')` takes cents.

What to do next depends on the status:

```php
match (true) {
    $payment->requiresAction() && $payment->redirectUrl() => redirect($payment->redirectUrl()),  // Stripe Checkout, Orange webpay, PayPal, Flutterwave
    $payment->requiresAction() => view('pay.card', ['secret' => $payment->clientSecret()]),     // Stripe intent mode
    $payment->isOpen() => view('pay.waiting', ['payment' => $payment]),                         // MTN / Orange push: approve on the phone
    $payment->succeeded() => redirect()->route('orders.show', $order),
    default => back()->withErrors($payment->failure_message),
};
```

For the "approve the prompt on your phone" screen, poll the status route. It checks with the provider when the payment hasn't been updated for a few seconds, so the page learns the outcome even when the callback never arrives:

```js
const poll = setInterval(async () => {
  const res = await fetch(`/billing/payments/${reference}/status`, { headers: { Accept: 'application/json' } });
  const { status, paid, open, failure_message } = await res.json();
  if (!open) { clearInterval(poll); paid ? location.assign(successUrl) : showError(failure_message); }
}, 3000);
```

Hosted pages send the payer back to `GET /billing/return/{reference}`. Billing asks the provider for the status (it never trusts the query string), captures PayPal orders, then redirects to your `returnTo()` URL with `?payment=PAY-…&status=succeeded`.

### Models

```php
use Atannex\Billing\Concerns\Billable;   // on User
use Atannex\Billing\Concerns\Payable;    // on Order, Invoice, Ticket…

$user->charge(5000)->for($order)->charge();
$user->payments()->paid()->get();

$order->pay(5000)->phone($phone)->charge();
$order->isPaid();                 // a successful payment, not fully refunded
$order->amountPaid('XAF');        // Money, net of refunds
$order->latestPayment;
```

Override `billingName()`, `billingEmail()` or `billingPhone()` on a Billable model when its attributes have other names. Payable and Billable both define `payments()`, so don't use both on one model.

### Payment API

```php
$payment->money();           // Money (5000 XAF)
$payment->refundable();      // what can still be refunded
$payment->net();             // kept after refunds
$payment->status;            // PaymentStatus enum
$payment->isOpen(); $payment->isPaid(); $payment->succeeded(); $payment->failed();
$payment->failure_code;      // approval_rejected, not_enough_funds, expired, amount_mismatch, …
$payment->failure_message;   // readable: "The payer declined the payment."
$payment->events;            // the audit ledger

Payment::open()->get();  Payment::paid()->gateway('mtn_momo')->get();  Payment::status('failed')->get();

Billing::find('PAY-01J…');
Billing::refresh($payment);  // ask the provider now
Billing::cancel($payment);   // open payments only; expires the Stripe session / cancels the intent
```

## Refunds

```php
Billing::refund($payment);                          // everything that is left
Billing::refund($payment, 2000, 'Damaged item');    // part, in major units of the payment's currency
Billing::refund($payment, Money::of('9.90', 'EUR'));
```

Refunds lock the payment and count refunds still in flight, so two concurrent refunds can't exceed the amount paid. Providers that settle refunds later (MTN, sometimes Stripe and PayPal) are followed up by `billing:reconcile` and webhooks. Orange Money has no public refund API, so `refund()` throws `UnsupportedCapability` for it.

## Events

All payment events fire once, after the database commit:

| Event | When |
|---|---|
| `PaymentInitiated` | The provider accepted the payment |
| `PaymentStatusChanged` | Any transition (`$from`, `$to`, `$source`: api, webhook, return, poll, reconcile) |
| `PaymentSucceeded` | Money confirmed, amount checked. **Fulfil orders here.** |
| `PaymentFailed` | Failed, cancelled or expired |
| `PaymentRequiresAction` | The payer must act |
| `PaymentRefunded` / `RefundFailed` | A refund settled |
| `AmountMismatch` | The provider reported another amount. Investigate. |
| `WebhookHandled` | Any verified notification, including types billing ignores |

```php
Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    $event->payment->payable?->markPaid();
});
```

## Routing

```php
'routing' => [
    'enabled' => true,
    'order' => ['mtn_momo', 'orange_money', 'stripe', 'flutterwave', 'paypal'],
    'failover' => false,
],
```

A gateway is eligible when it is enabled, its `currencies` include the payment's currency (or the list is empty), its `methods` include the requested method, and, if it lists `operators`, the payer's number belongs to one of them. Push gateways (MTN, Orange merchant) also need a phone number. `Billing::router()->candidates('XAF', null, Msisdn::parse('699…'))` shows the decision, and `billing:gateways` shows the table.

Phone numbers are normalized by `Atannex\Billing\Support\Msisdn` ("677 12 34 56", "+237677123456", "00237…" all become `237677123456`). Operator prefixes and calling codes live in `config/billing.php`; adjust them when a regulator reassigns a range.

## Reconciliation

Mobile money is asynchronous, and callbacks get lost or arrive late. Each open payment carries `next_check_at` and `expires_at` (from the gateway's `expires_after`). `billing:reconcile` runs every minute and checks due payments on the `reconcile.backoff` schedule, with the last check placed exactly at expiry. A payment still unanswered at expiry becomes `expired`. If the provider later reports success through a callback or a manual refresh, it moves to `succeeded` and the ledger records a `late_success`.

Set `BILLING_QUEUE=payments` to check payments through queued `ReconcilePayment` jobs instead of inline. Set `reconcile.schedule` to `false` if you schedule the command yourself.

## Testing your app

```php
use Atannex\Billing\Facades\Billing;
use Atannex\Billing\Enums\PaymentStatus;

$fake = Billing::fake()->process();          // succeed() · fail('code', 'message') · process() · requireAction() · unavailable()

$this->post('/checkout', ['phone' => '677123456'])->assertOk();

$fake->assertCharged(fn (Payment $p) => $p->gateway === 'mtn_momo' && $p->amount === 5000);
$payment = Payment::latest('id')->first();
$fake->settle($payment, PaymentStatus::Succeeded);   // the provider confirms, through the real processor
$this->assertTrue($order->fresh()->isPaid());

$fake->assertChargedTimes(1);  $fake->assertNothingCharged();  $fake->assertRefunded();
```

For local development without credentials, enable the `fake` gateway (`'enabled' => true`, `'outcome' => 'requires_action'`). Its redirect goes straight to the return page and the payment succeeds.

## Writing a driver

```php
use Atannex\Billing\Gateways\AbstractGateway;

class CinetPayGateway extends AbstractGateway
{
    protected array $capabilities = [Capability::Redirect, Capability::Webhooks];

    public function initiate(Payment $payment): Snapshot { /* call the API, return a Snapshot */ }
    public function fetch(Payment $payment): Snapshot { /* map the provider's status */ }
    public function verifyWebhook(Request $request, ?Payment $payment): bool { /* … */ }
    public function parseWebhook(Request $request): WebhookEvent { /* … refresh: true */ }
}

Billing::extend('cinetpay', fn (string $name, array $config, $app) => new CinetPayGateway(
    $name, $config, $app->make(\Illuminate\Http\Client\Factory::class), $app['cache']->store(),
));
```

Then add it under `gateways` with `'driver' => 'cinetpay'`. Drivers only translate. `send()` applies the error policy (unreachable or 5xx → `GatewayUnavailable`, other failures → `GatewayException` with the provider's message), `token()` caches OAuth tokens, and the processor handles state, amounts, locking, the ledger and events. Return the provider's charged amount in `Snapshot::$amount` to get mismatch protection.

## Commands

```bash
php artisan billing:gateways                  # what is configured, enabled, routed
php artisan billing:payment PAY-01J… --refresh   # one payment, its refunds and ledger
php artisan billing:reconcile [--sync] [--gateway=mtn_momo]
php artisan billing:prune [--days=90]         # old webhook calls (scheduled daily)
php artisan billing:mtn-sandbox-user [--product=disbursement] [--host=…]
```

## Notes

- **Orange Money endpoints.** Orange runs two APIs: Web Payment on `api.orange.com`, and the Cameroon merchant API, whose host and version path (`/omcoreapis/1.0.2`) depend on your contract. Every URL is in config, so check them against the documents Orange gives you.
- **PayPal doesn't settle XAF**, so it is limited to the currencies in its `currencies` list and routing never sends XAF there.
- **Stripe and zero-decimal currencies.** Amounts are sent in minor units, and XAF, XOF and JPY have none. Stripe treats a few currencies (HUF, TWD, UGX) specially; check its docs before charging in those.
- **Unsigned callbacks are never trusted.** MTN, Orange, PayPal and Flutterwave notifications trigger a status check with the provider. Only Stripe's signed events are applied as received.
- **Routes** (`billing.webhook`, `billing.return`, `billing.status`) can be renamed through `routes.prefix` or disabled with `routes.enabled`. If you disable them, register equivalents with the same names, because callback URLs are built from them.

## Testing the package

```bash
composer install
vendor/bin/phpunit
```

## License

MIT

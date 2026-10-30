# Atannex Billing

Takes payments in a Laravel app. One simple API works with Stripe, MTN Mobile Money, Orange Money, PayPal and Flutterwave, and the package picks the right gateway from the currency and the customer's phone network. It keeps track of each payment from start to finish, checks the webhooks the gateways send back, confirms mobile-money payments that are still pending, handles refunds and keeps a record of every change. Tests can use a fake gateway, so no real money moves.

## Install

```bash
composer require atannex/billing
```

## Documentation

The full guide is in [docs/README.md](docs/README.md).

## License

MIT.

# Changelog

## v1.1.0 (2026-10-30)

### Fixed

- Refunds: a refund whose request timed out or got a 5xx stays `pending` (its amount still reserved) instead of being marked failed, which freed the amount and allowed a second refund while the provider may already have paid the first. Only a refusal (4xx) or a refund that was never sent fails it. `Billing::refund()` then returns the pending refund rather than throwing, and the ledger records `refund_unconfirmed`.
- MTN MoMo: the disbursement `X-Reference-Id` is stored on the refund before the call (new optional `Contracts\PreparesRefunds`), so reconciliation can look a timed-out refund up. A refund MTN still has no record of after 10 minutes is failed.
- Stripe: a refund with no provider id (lost answer) is found by its `billing_refund` metadata; if Stripe has no record an hour later it is failed. PayPal and Flutterwave leave such refunds pending and log `billing.refund.unresolved`.
- Orange Money merchant mode: `mp/init` runs in `prepare()`, so the payToken is stored before `mp/pay`. A timed-out `mp/pay` now leaves the payment `processing` for reconciliation instead of failing it or failing over while the USSD prompt is live.
- Reconciliation never expires a payment right after a failed provider check; it needs a successful check showing the payment still open. When cancelling at expiry fails, the payment is checked again first (it has often completed).
- Payments closed locally (expired, or cancelled at a gateway that cannot cancel at the provider) are re-checked every `reconcile.grace_every` seconds (900) for `reconcile.grace` minutes (1440), so a late success lands. The watch ends early when the provider itself reports the payment closed.
- Events queued inside a transaction that rolled back are discarded instead of being fired with the next payment handled by the same processor.
- A success reported without an amount is no longer believed: the payment stays `processing` and the ledger records `amount_unverified`, unless the driver marks the amount as implied (`Snapshot::$amountImplied`, used by Orange Web Payment whose lookup is keyed by amount). PayPal no longer treats a `COMPLETED` order without a capture as paid.
- Provider decimal amounts are parsed exactly as strings; an amount with more decimals than the currency allows (e.g. `5000.4` XAF) is no longer rounded into a match.
- Amounts reported in a mapped currency (`currency_map`, e.g. XAF sent as EUR) are read back as the payment's currency for every source, including Stripe webhooks.
- The return page's `?outcome=cancel` only cancels at gateways that can cancel at the provider (Stripe, the fake); elsewhere it refreshes, and the payment stays open until the provider settles it or it expires.
- The status route's `failure_message` is a payer-safe sentence chosen by billing, never the provider's text.
- The `fake` driver refuses to build when the app environment is `production`, unless `billing.allow_fake_in_production` is true. (`Billing::fake()` in tests is unaffected.)
- Flutterwave's `verif-hash` header (the shared webhook secret) is no longer stored with webhook calls.
- Flutterwave verifies by transaction id (`/v3/transactions/{id}/verify`) once one is known and checks the answer's `tx_ref`, falling back to `verify_by_reference`.
- Stripe Checkout sessions expire after at most 1435 minutes, clear of Stripe's 24-hour limit.
- `PaymentInitiated` is fired after the commit, like the other events.
- A second partial refund no longer records a `partially_refunded → partially_refunded` status change or fires `PaymentStatusChanged`.
- PayPal: a capture refused with HTTP 422 (`INSTRUMENT_DECLINED` and the like) fails the payment instead of erroring on every check; `ORDER_ALREADY_CAPTURED` reads the order back.

### Changed

- `Payment` and `Refund` guard their state, money and provider fields (`status`, `amount`, `currency`, `amount_refunded`, `gateway_data`, `provider_reference`, `paid_at`, …) against mass assignment. Use `forceFill()` / `forceCreate()` to write them directly (for example in test fixtures).
- `Payment::scopeDue()` also returns failed, cancelled and expired payments that still have a `next_check_at` (the watched ones); `ReconcilePayment` checks them too.
- New config keys: `reconcile.grace`, `reconcile.grace_every`, `allow_fake_in_production`.

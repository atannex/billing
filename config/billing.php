<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | `gateway` is used when a payment names none and routing is off, or when
    | routing finds no better match. `currency` is used when a payment names
    | none. `country` decides how local phone numbers are read ("677 12 34 56"
    | → +237 677 12 34 56).
    |
    */

    'gateway' => env('BILLING_GATEWAY', 'stripe'),

    'currency' => env('BILLING_CURRENCY', 'XAF'),

    'country' => env('BILLING_COUNTRY', 'CM'),

    /*
    |--------------------------------------------------------------------------
    | Gateway routing
    |--------------------------------------------------------------------------
    |
    | Without ->via('…'), a payment goes to the first gateway in `order` that
    | is enabled, supports its currency and method, and accepts the payer's
    | phone operator: an MTN number goes to MTN MoMo, an Orange number to
    | Orange Money, a card payment in USD to Stripe.
    |
    | `failover` retries the next eligible gateway when one is unreachable
    | (network error or 5xx) before it accepted anything. A payment never
    | fails over once the first gateway may have started charging.
    |
    */

    'routing' => [
        'enabled' => true,
        'order' => ['mtn_momo', 'orange_money', 'stripe', 'flutterwave', 'paypal'],
        'failover' => env('BILLING_FAILOVER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | Each entry names a `driver` (stripe, mtn_momo, orange_money, paypal,
    | flutterwave, fake or one added with Billing::extend()) and that driver's
    | credentials. Common keys for every gateway:
    |
    |   enabled        false hides it from routing (->via() still works)
    |   currencies     ISO codes it accepts; [] accepts any
    |   methods        what it can take: card, mobile_money, wallet, bank
    |   operators      mobile operators it serves (mtn, orange, …); [] any
    |   currency_map   send another code to the provider, e.g. XAF → EUR in
    |                  the MTN sandbox, and read it back as the original
    |   expires_after  minutes an unanswered payment stays open before it is
    |                  expired by reconciliation
    |   timeout        HTTP timeout in seconds
    |
    */

    // The `fake` driver settles payments without any provider. It refuses
    // to run in production unless this is set.
    'allow_fake_in_production' => env('BILLING_ALLOW_FAKE_IN_PRODUCTION', false),

    'gateways' => [

        'stripe' => [
            'driver' => 'stripe',
            'enabled' => env('STRIPE_SECRET') !== null,
            'secret' => env('STRIPE_SECRET'),
            'publishable' => env('STRIPE_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'webhook_tolerance' => 300,
            // checkout: redirect to a hosted Stripe Checkout page.
            // intent:   create a PaymentIntent and confirm it on your page with
            //           Stripe.js using $payment->clientSecret().
            'mode' => env('STRIPE_MODE', 'checkout'),
            'payment_method_types' => [],   // [] lets Stripe pick from your dashboard
            'base_url' => 'https://api.stripe.com',
            'api_version' => null,
            'currencies' => [],
            'methods' => ['card', 'wallet', 'bank'],
            'expires_after' => 1440,
            'timeout' => 20,
        ],

        'mtn_momo' => [
            'driver' => 'mtn_momo',
            'enabled' => env('MTN_MOMO_SUBSCRIPTION_KEY') !== null,
            // sandbox | mtncameroon (production for MTN Cameroon)
            'environment' => env('MTN_MOMO_ENVIRONMENT', 'sandbox'),
            'base_url' => env('MTN_MOMO_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'),
            'collection' => [
                'subscription_key' => env('MTN_MOMO_SUBSCRIPTION_KEY'),
                'api_user' => env('MTN_MOMO_API_USER'),
                'api_key' => env('MTN_MOMO_API_KEY'),
            ],
            // Optional: refunds go through MTN's Disbursement product, which
            // has its own subscription and API user. Leave empty to disable.
            'disbursement' => [
                'subscription_key' => env('MTN_MOMO_DISBURSEMENT_KEY'),
                'api_user' => env('MTN_MOMO_DISBURSEMENT_USER'),
                'api_key' => env('MTN_MOMO_DISBURSEMENT_API_KEY'),
            ],
            // Ask MTN whether the number has an active wallet before pushing.
            'validate_account' => false,
            'payer_message' => null,        // shown on the payer's phone; defaults to the description
            'currencies' => ['XAF', 'EUR'],
            'currency_map' => env('MTN_MOMO_ENVIRONMENT', 'sandbox') === 'sandbox' ? ['XAF' => 'EUR'] : [],
            'methods' => ['mobile_money'],
            'operators' => ['mtn'],
            'expires_after' => 10,
            'timeout' => 30,
        ],

        'orange_money' => [
            'driver' => 'orange_money',
            'enabled' => env('ORANGE_MONEY_CLIENT_ID') !== null || env('ORANGE_MONEY_CONSUMER_KEY') !== null,
            // webpay:   Orange Developer "Web Payment" — redirect to an Orange
            //           page where the payer confirms (api.orange.com).
            // merchant: Orange Cameroon merchant payment API — push a USSD
            //           prompt to the payer's phone (no redirect).
            'mode' => env('ORANGE_MONEY_MODE', 'webpay'),

            'webpay' => [
                'base_url' => env('ORANGE_MONEY_BASE_URL', 'https://api.orange.com'),
                'client_id' => env('ORANGE_MONEY_CLIENT_ID'),
                'client_secret' => env('ORANGE_MONEY_CLIENT_SECRET'),
                'merchant_key' => env('ORANGE_MONEY_MERCHANT_KEY'),
                // "dev" in the sandbox, "cm" in production
                'country' => env('ORANGE_MONEY_COUNTRY', 'cm'),
                'version' => 'v1',
                'lang' => 'fr',
            ],

            'merchant' => [
                'base_url' => env('ORANGE_MONEY_MERCHANT_URL', 'https://api-s1.orange.cm'),
                'token_path' => '/token',
                'api_path' => '/omcoreapis/1.0.2',
                'consumer_key' => env('ORANGE_MONEY_CONSUMER_KEY'),
                'consumer_secret' => env('ORANGE_MONEY_CONSUMER_SECRET'),
                'username' => env('ORANGE_MONEY_USERNAME'),     // X-AUTH-TOKEN user
                'password' => env('ORANGE_MONEY_PASSWORD'),
                'channel_msisdn' => env('ORANGE_MONEY_CHANNEL_MSISDN'),
                'pin' => env('ORANGE_MONEY_PIN'),
            ],

            'currencies' => ['XAF'],
            'currency_map' => [],          // ['XAF' => 'OUV'] in the webpay sandbox
            'methods' => ['mobile_money'],
            'operators' => ['orange'],
            'expires_after' => 15,
            'timeout' => 30,
        ],

        'paypal' => [
            'driver' => 'paypal',
            'enabled' => env('PAYPAL_CLIENT_ID') !== null,
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'base_url' => env('PAYPAL_BASE_URL', 'https://api-m.sandbox.paypal.com'),
            'brand_name' => env('APP_NAME'),
            // PayPal does not settle XAF; keep it to currencies it supports.
            'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY'],
            'methods' => ['wallet', 'card'],
            'expires_after' => 180,
            'timeout' => 20,
        ],

        'flutterwave' => [
            'driver' => 'flutterwave',
            'enabled' => env('FLUTTERWAVE_SECRET') !== null,
            'secret' => env('FLUTTERWAVE_SECRET'),
            'public' => env('FLUTTERWAVE_PUBLIC'),
            'webhook_hash' => env('FLUTTERWAVE_WEBHOOK_HASH'),
            'base_url' => 'https://api.flutterwave.com',
            'payment_options' => 'card,mobilemoneyfranco,ussd',
            'title' => env('APP_NAME'),
            'logo' => null,
            'currencies' => [],
            'methods' => ['card', 'mobile_money', 'bank'],
            'expires_after' => 180,
            'timeout' => 20,
        ],

        'fake' => [
            'driver' => 'fake',
            'enabled' => false,
            // succeeded | failed | processing | requires_action
            'outcome' => 'succeeded',
            'currencies' => [],
            'methods' => ['card', 'mobile_money', 'wallet', 'bank'],
            'expires_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | Mobile money is asynchronous and callbacks get lost. Every open payment
    | is re-checked with its gateway on this back-off (seconds after it was
    | sent, then after each check, the last step repeating) until it settles
    | or passes its gateway's `expires_after`. `schedule` runs
    | billing:reconcile every minute from the package; turn it off if you
    | schedule the command yourself.
    |
    | A payment closed here rather than by the provider (expired, or
    | cancelled at a gateway that cannot call it off) may still be approved
    | on the payer's phone. It is checked every `grace_every` seconds for
    | `grace` minutes more, so a late success still lands. 0 turns it off.
    |
    */

    'reconcile' => [
        'schedule' => true,
        'backoff' => [10, 20, 30, 60, 120, 300, 600, 1800, 3600],
        'grace' => 1440,
        'grace_every' => 900,
        'queue' => env('BILLING_QUEUE'),     // null runs checks inline
        'batch' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP routes
    |--------------------------------------------------------------------------
    |
    | Webhooks:  POST {prefix}/webhooks/{gateway}/{reference?}
    | Return:    GET  {prefix}/return/{reference}
    | Status:    GET  {prefix}/payments/{reference}/status   (JSON, for polling)
    |
    | `public_url` replaces APP_URL in callback URLs sent to providers (an
    | ngrok tunnel while developing). `redirects` are used after the return
    | page when the payment carries no URL of its own.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'billing',
        'webhook_middleware' => ['api', 'throttle:120,1'],
        'return_middleware' => ['web', 'throttle:60,1'],
        'status_middleware' => ['api', 'throttle:60,1'],
        // An open payment polled through the status route is re-checked with
        // its provider when it was last updated longer ago than this (seconds).
        'status_refresh_after' => 5,
        'public_url' => env('BILLING_PUBLIC_URL'),
    ],

    'redirects' => [
        'success' => '/',
        'failure' => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'table_prefix' => 'billing_',

    'run_migrations' => true,

    // Cache store for OAuth tokens (MTN, Orange, PayPal). null: default store.
    'cache_store' => env('BILLING_CACHE_STORE'),

    // Prefix and length of generated payment references (PAY-01JB…).
    'reference_prefix' => 'PAY-',

    // Webhook calls older than this many days are removed by billing:prune.
    'prune_webhooks_after' => 90,

    /*
    |--------------------------------------------------------------------------
    | Money and phone numbers
    |--------------------------------------------------------------------------
    |
    | `decimals` adds or overrides minor-unit sizes (ISO 4217 is built in).
    | `operators` maps national mobile prefixes to operators per country;
    | the longest matching prefix wins. Adjust when a regulator reassigns a
    | range.
    |
    */

    'decimals' => [
        'OUV' => 0,
    ],

    'operators' => [
        'CM' => [
            'mtn' => ['67', '650', '651', '652', '653', '654', '680', '681', '682', '683', '684'],
            'orange' => ['69', '655', '656', '657', '658', '659', '640', '685', '686', '687', '688', '689'],
            'nexttel' => ['66'],
            'camtel' => ['62', '242', '243'],
        ],
    ],

    'calling_codes' => [
        'CM' => ['code' => '237', 'length' => 9],
        'NG' => ['code' => '234', 'length' => 10],
        'GH' => ['code' => '233', 'length' => 9],
        'CI' => ['code' => '225', 'length' => 10],
        'SN' => ['code' => '221', 'length' => 9],
        'GA' => ['code' => '241', 'length' => 8],
        'TD' => ['code' => '235', 'length' => 8],
        'CG' => ['code' => '242', 'length' => 9],
        'CF' => ['code' => '236', 'length' => 8],
        'GQ' => ['code' => '240', 'length' => 9],
        'FR' => ['code' => '33', 'length' => 9],
        'US' => ['code' => '1', 'length' => 10],
        'GB' => ['code' => '44', 'length' => 10],
    ],

];

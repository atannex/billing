<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests;

use Atannex\Billing\BillingServiceProvider;
use Atannex\Billing\Facades\Billing;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var array<string, mixed> config overrides for the next app */
    protected array $billing = [];

    protected function getPackageProviders($app): array
    {
        return [BillingServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Billing' => Billing::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('database.default', 'testing');
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('app.url', 'https://shop.test');
        $config->set('cache.default', 'array');

        $config->set('billing.gateways.stripe', [...$config->get('billing.gateways.stripe'), 'enabled' => true, 'secret' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);

        $config->set('billing.gateways.mtn_momo', [...$config->get('billing.gateways.mtn_momo'),
            'enabled' => true,
            'environment' => 'mtncameroon',
            'base_url' => 'https://momo.test',
            'currency_map' => [],
            'collection' => ['subscription_key' => 'sub-key', 'api_user' => 'api-user', 'api_key' => 'api-key'],
            'disbursement' => ['subscription_key' => 'dis-key', 'api_user' => 'dis-user', 'api_key' => 'dis-api-key'],
        ]);

        $config->set('billing.gateways.orange_money', [...$config->get('billing.gateways.orange_money'),
            'enabled' => true,
            'webpay' => [...$config->get('billing.gateways.orange_money.webpay'), 'base_url' => 'https://orange.test', 'client_id' => 'om-id', 'client_secret' => 'om-secret', 'merchant_key' => 'mk-1'],
            'merchant' => [...$config->get('billing.gateways.orange_money.merchant'), 'base_url' => 'https://omcm.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'username' => 'u', 'password' => 'p', 'channel_msisdn' => '691000000', 'pin' => '1234'],
        ]);

        $config->set('billing.gateways.paypal', [...$config->get('billing.gateways.paypal'), 'enabled' => true, 'client_id' => 'pp-id', 'client_secret' => 'pp-secret', 'base_url' => 'https://paypal.test', 'webhook_id' => 'WH-1']);

        $config->set('billing.gateways.flutterwave', [...$config->get('billing.gateways.flutterwave'), 'enabled' => true, 'secret' => 'FLWSECK_TEST', 'webhook_hash' => 'flw-hash', 'base_url' => 'https://flw.test']);

        foreach ($this->billing as $key => $value) {
            $config->set("billing.{$key}", $value);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('total')->default(0);
            $table->timestamps();
        });
    }
}

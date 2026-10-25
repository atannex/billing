<?php

declare(strict_types=1);

namespace Atannex\Billing\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Str;

/**
 * MTN's sandbox has no dashboard for API users: they are created through
 * the API with the subscription key. This does it and prints the .env lines.
 */
class MtnSandboxUserCommand extends Command
{
    protected $signature = 'billing:mtn-sandbox-user
        {--product=collection : collection or disbursement}
        {--host= : Callback host registered for the user (default: APP_URL host)}
        {--gateway=mtn_momo : Gateway whose subscription key to use}';

    protected $description = 'Create an MTN MoMo sandbox API user and key';

    public function handle(Http $http): int
    {
        $product = (string) $this->option('product');
        $config = (array) config('billing.gateways.'.$this->option('gateway'), []);
        $key = (string) data_get($config, "{$product}.subscription_key", '');
        $base = rtrim((string) ($config['base_url'] ?? 'https://sandbox.momodeveloper.mtn.com'), '/');

        if ($key === '') {
            $this->components->error("Set the {$product} subscription key first (MTN_MOMO_SUBSCRIPTION_KEY).");

            return self::FAILURE;
        }

        if (! str_contains($base, 'sandbox')) {
            $this->components->error('API users can only be created this way in the sandbox; production users come from the MTN partner portal.');

            return self::FAILURE;
        }

        $user = (string) Str::uuid();
        $host = (string) ($this->option('host') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $client = $http->baseUrl($base)->withHeaders(['Ocp-Apim-Subscription-Key' => $key])->acceptJson();

        $created = $client->withHeaders(['X-Reference-Id' => $user])->post('/v1_0/apiuser', ['providerCallbackHost' => $host]);

        if (! $created->successful()) {
            $this->components->error('MTN refused the user: HTTP '.$created->status().' '.$created->body());

            return self::FAILURE;
        }

        $apiKey = $http->baseUrl($base)->withHeaders(['Ocp-Apim-Subscription-Key' => $key])->acceptJson()->post("/v1_0/apiuser/{$user}/apikey");

        if (! $apiKey->successful()) {
            $this->components->error('MTN refused the key: HTTP '.$apiKey->status().' '.$apiKey->body());

            return self::FAILURE;
        }

        $prefix = $product === 'disbursement' ? 'MTN_MOMO_DISBURSEMENT' : 'MTN_MOMO';

        $this->components->info("Sandbox {$product} user created (callback host {$host}). Add to .env:");
        $this->line("{$prefix}_".($product === 'disbursement' ? 'USER' : 'API_USER')."={$user}");
        $this->line("{$prefix}_API_KEY=".$apiKey->json('apiKey'));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Atannex\Billing;

use Atannex\Billing\Console\GatewaysCommand;
use Atannex\Billing\Console\MtnSandboxUserCommand;
use Atannex\Billing\Console\PaymentCommand;
use Atannex\Billing\Console\PruneCommand;
use Atannex\Billing\Console\ReconcileCommand;
use Atannex\Billing\Http\Controllers\ReturnController;
use Atannex\Billing\Http\Controllers\StatusController;
use Atannex\Billing\Http\Controllers\WebhookController;
use Atannex\Billing\Money\Currency;
use Atannex\Billing\Processing\PaymentProcessor;
use Atannex\Billing\Support\Msisdn;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing.php', 'billing');

        $this->app->singleton(BillingManager::class, fn (Application $app) => new BillingManager($app));
        $this->app->alias(BillingManager::class, 'billing');

        $this->app->bind(PaymentProcessor::class, fn (Application $app) => new PaymentProcessor(
            $app->make(BillingManager::class),
            $app['events'],
            (array) $app['config']['billing'],
        ));
    }

    public function boot(): void
    {
        $config = (array) $this->app['config']['billing'];

        Currency::configure((array) ($config['decimals'] ?? []));
        Msisdn::configure((array) ($config['calling_codes'] ?? []), (array) ($config['operators'] ?? []));

        if ($config['run_migrations'] ?? true) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->registerRoutes($config);

        if ($config['reconcile']['schedule'] ?? true) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('billing:reconcile')->everyMinute()->withoutOverlapping(5)->name('billing:reconcile');
                $schedule->command('billing:prune')->daily()->name('billing:prune');
            });
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/billing.php' => $this->app->configPath('billing.php')], 'billing-config');
            $this->publishes([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'billing-migrations');

            $this->commands([
                ReconcileCommand::class,
                GatewaysCommand::class,
                PaymentCommand::class,
                PruneCommand::class,
                MtnSandboxUserCommand::class,
            ]);

            AboutCommand::add('Billing', fn () => [
                'Default gateway' => (string) ($config['gateway'] ?? ''),
                'Enabled' => implode(', ', $this->app->make(BillingManager::class)->enabled()) ?: 'none',
                'Routing' => ($config['routing']['enabled'] ?? true) ? implode(' → ', (array) ($config['routing']['order'] ?? [])) : 'off',
                'Currency' => (string) ($config['currency'] ?? ''),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function registerRoutes(array $config): void
    {
        if (! ($config['routes']['enabled'] ?? true) || $this->app->routesAreCached()) {
            return;
        }

        $prefix = trim((string) ($config['routes']['prefix'] ?? 'billing'), '/');

        Route::middleware((array) ($config['routes']['webhook_middleware'] ?? ['api']))
            ->match(['POST', 'PUT'], "{$prefix}/webhooks/{gateway}/{reference?}", WebhookController::class)
            ->where(['gateway' => '[A-Za-z0-9_\-]+', 'reference' => '[A-Za-z0-9_\-]+'])
            ->name('billing.webhook');

        Route::middleware((array) ($config['routes']['return_middleware'] ?? ['web']))
            ->get("{$prefix}/return/{reference}", ReturnController::class)
            ->where('reference', '[A-Za-z0-9_\-]+')
            ->name('billing.return');

        Route::middleware((array) ($config['routes']['status_middleware'] ?? ['api', 'throttle:60,1']))
            ->get("{$prefix}/payments/{reference}/status", StatusController::class)
            ->where('reference', '[A-Za-z0-9_\-]+')
            ->name('billing.status');
    }
}

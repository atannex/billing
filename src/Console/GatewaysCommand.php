<?php

declare(strict_types=1);

namespace Atannex\Billing\Console;

use Atannex\Billing\BillingManager;
use Atannex\Billing\Enums\Capability;
use Illuminate\Console\Command;
use Throwable;

class GatewaysCommand extends Command
{
    protected $signature = 'billing:gateways';

    protected $description = 'List configured payment gateways, what they accept and in which routing order';

    public function handle(BillingManager $billing): int
    {
        $order = (array) config('billing.routing.order', []);
        $rows = [];

        foreach ($billing->gateways() as $name) {
            $config = (array) $billing->gatewayConfig($name);

            try {
                $capabilities = implode(', ', array_map(fn (Capability $c) => $c->value, $billing->gateway($name)->capabilities()));
            } catch (Throwable $e) {
                $capabilities = '<error>'.$e->getMessage().'</error>';
            }

            $position = array_search($name, $order, true);

            $rows[] = [
                $name.($name === config('billing.gateway') ? ' <fg=gray>(default)</>' : ''),
                $config['driver'] ?? $name,
                ($config['enabled'] ?? true) ? '<fg=green>yes</>' : '<fg=gray>no</>',
                $position === false ? '–' : (string) ($position + 1),
                implode(', ', (array) ($config['currencies'] ?? [])) ?: 'any',
                implode(', ', (array) ($config['methods'] ?? [])) ?: 'any',
                implode(', ', (array) ($config['operators'] ?? [])) ?: '–',
                $capabilities,
            ];
        }

        $this->table(['Gateway', 'Driver', 'Enabled', 'Route', 'Currencies', 'Methods', 'Operators', 'Capabilities'], $rows);

        return self::SUCCESS;
    }
}

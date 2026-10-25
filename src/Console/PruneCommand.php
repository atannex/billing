<?php

declare(strict_types=1);

namespace Atannex\Billing\Console;

use Atannex\Billing\Models\WebhookCall;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'billing:prune {--days= : Keep this many days (default prune_webhooks_after)}';

    protected $description = 'Delete old webhook calls';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('billing.prune_webhooks_after', 90));
        $deleted = WebhookCall::query()->where('created_at', '<', now()->subDays($days))->delete();

        $this->components->info("Deleted {$deleted} webhook calls older than {$days} days.");

        return self::SUCCESS;
    }
}

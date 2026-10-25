<?php

declare(strict_types=1);

namespace Atannex\Billing\Events;

use Atannex\Billing\Data\WebhookEvent;
use Atannex\Billing\Models\WebhookCall;

/**
 * A verified notification was processed (or ignored); listen here to react
 * to provider events billing does not track itself.
 */
class WebhookHandled
{
    public function __construct(public readonly WebhookCall $call, public readonly WebhookEvent $event) {}
}

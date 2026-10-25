<?php

declare(strict_types=1);

namespace Atannex\Billing\Enums;

enum Capability: string
{
    /** Sends the payer to a hosted page (redirectUrl()). */
    case Redirect = 'redirect';

    /** Pushes a prompt to the payer's phone; needs a phone number. */
    case Push = 'push';

    /** Returns a client secret to confirm on your own page. */
    case Embedded = 'embedded';

    case Refunds = 'refunds';
    case PartialRefunds = 'partial_refunds';
    case Cancel = 'cancel';
    case Webhooks = 'webhooks';
}

<?php

declare(strict_types=1);

namespace Atannex\Billing\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}

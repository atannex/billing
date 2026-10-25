<?php

declare(strict_types=1);

namespace Atannex\Billing\Enums;

enum PaymentMethod: string
{
    case Card = 'card';
    case MobileMoney = 'mobile_money';
    case Wallet = 'wallet';
    case Bank = 'bank';
}

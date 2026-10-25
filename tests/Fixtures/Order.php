<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Fixtures;

use Atannex\Billing\Concerns\Payable;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use Payable;

    protected $guarded = [];
}

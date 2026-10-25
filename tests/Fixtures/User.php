<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Fixtures;

use Atannex\Billing\Concerns\Billable;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use Billable;

    protected $guarded = [];
}

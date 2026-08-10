<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use ByRcsc\LaravelAssignment\Concerns\Assignable;
use Illuminate\Database\Eloquent\Model;

class ProfileAssignableRecord extends Model
{
    use Assignable;

    public $timestamps = false;

    protected $table = 'test_profile_assignables';

    protected $guarded = [];
}

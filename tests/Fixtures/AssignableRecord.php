<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use ByRcsc\LaravelAssignment\Concerns\Assignable;
use Illuminate\Database\Eloquent\Model;

final class AssignableRecord extends Model
{
    use Assignable;

    public $timestamps = false;

    protected $table = 'test_assignables';

    protected $guarded = [];
}

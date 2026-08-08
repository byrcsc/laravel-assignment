<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class AssigneeRecord extends Model
{
    public $timestamps = false;

    protected $table = 'test_assignees';

    protected $guarded = [];
}

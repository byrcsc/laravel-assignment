<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use ByRcsc\LaravelAssignment\Concerns\Assignee;
use Illuminate\Database\Eloquent\Model;

final class AssigneeRecord extends Model
{
    use Assignee;

    public $timestamps = false;

    protected $table = 'test_assignees';

    protected $guarded = [];
}

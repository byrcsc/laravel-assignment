<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use ByRcsc\LaravelAssignment\Concerns\Assignee;
use Illuminate\Database\Eloquent\Model;

final class UlidAssigneeRecord extends Model
{
    use Assignee;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'test_ulid_assignees';

    protected $keyType = 'string';

    protected $guarded = [];
}

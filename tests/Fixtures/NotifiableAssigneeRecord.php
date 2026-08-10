<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use ByRcsc\LaravelAssignment\Concerns\Assignee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

final class NotifiableAssigneeRecord extends Model
{
    use Assignee;
    use Notifiable;

    public $timestamps = false;

    protected $table = 'test_assignees';

    protected $guarded = [];
}

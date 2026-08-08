<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use ByRcsc\LaravelAssignment\Concerns\Assignable;
use Illuminate\Database\Eloquent\Model;

final class UuidAssignableRecord extends Model
{
    use Assignable;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'test_uuid_assignables';

    protected $keyType = 'string';

    protected $guarded = [];
}

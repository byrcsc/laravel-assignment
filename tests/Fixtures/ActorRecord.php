<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class ActorRecord extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'test_actors';

    protected $keyType = 'string';

    protected $guarded = [];
}

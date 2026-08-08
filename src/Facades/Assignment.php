<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Facades;

use ByRcsc\LaravelAssignment\AssignmentManager;
use ByRcsc\LaravelAssignment\PendingAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PendingAssignment for(Model $assignable)
 * @method static int flushQueue(?string $assignableType = null)
 */
final class Assignment extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AssignmentManager::class;
    }
}

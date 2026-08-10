<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment;

use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Enums\Mode;
use Closure;
use Illuminate\Database\Eloquent\Model;

abstract class AssignmentProfile
{
    /** @return iterable<mixed> */
    abstract public function candidates(Model $assignable, ?string $role): iterable;

    abstract public function policy(Model $assignable, ?string $role): string|SelectionPolicy|Closure;

    public function scope(Model $assignable, ?string $role): ?string
    {
        return null;
    }

    public function mode(Model $assignable, ?string $role): Mode
    {
        return Mode::Assign;
    }

    public function offerTtl(Model $assignable, ?string $role): ?int
    {
        return null;
    }

    public function queueWhenEmpty(Model $assignable, ?string $role): bool
    {
        return true;
    }

    public function queuePriority(Model $assignable, ?string $role): int
    {
        return 0;
    }
}

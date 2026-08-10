<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Contracts;

use ByRcsc\LaravelAssignment\ScopeState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface SelectionPolicy
{
    /** @param Collection<int, Model> $candidates */
    public function select(Model $assignable, Collection $candidates, ScopeState $state): ?Model;
}

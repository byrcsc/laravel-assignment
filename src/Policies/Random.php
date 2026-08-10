<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Policies;

use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\ScopeState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class Random implements SelectionPolicy
{
    /** @param Collection<int, Model> $candidates */
    public function select(Model $assignable, Collection $candidates, ScopeState $state): ?Model
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->get(random_int(0, $candidates->count() - 1));
    }
}

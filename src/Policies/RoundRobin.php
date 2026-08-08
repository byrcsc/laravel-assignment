<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Policies;

use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\ScopeState;
use ByRcsc\LaravelAssignment\Support\ModelIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class RoundRobin implements SelectionPolicy
{
    private const string CURSOR = 'roundRobin.cursor';

    /** @param Collection<int, Model> $candidates */
    public function select(Model $assignable, Collection $candidates, ScopeState $state): ?Model
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $cursor = $state->get(self::CURSOR);
        $lastIndex = $candidates->search(
            fn (Model $candidate): bool => ModelIdentity::for($candidate) === $cursor,
        );
        $nextIndex = $lastIndex === false
            ? 0
            : ($lastIndex + 1) % $candidates->count();
        $selected = $candidates->get($nextIndex);

        if ($selected === null) {
            return null;
        }

        $state->put(self::CURSOR, ModelIdentity::for($selected));

        return $selected;
    }
}

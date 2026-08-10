<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\ScopeState;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use UnexpectedValueException;

final readonly class ClosureSelectionPolicy implements SelectionPolicy
{
    public function __construct(private Closure $callback) {}

    /** @param Collection<int, Model> $candidates */
    public function select(Model $assignable, Collection $candidates, ScopeState $state): ?Model
    {
        $selected = ($this->callback)($assignable, $candidates);

        if ($selected === null) {
            return null;
        }

        if (! $selected instanceof Model) {
            throw new UnexpectedValueException('An assignment policy must return an Eloquent model or null.');
        }

        return $selected;
    }
}

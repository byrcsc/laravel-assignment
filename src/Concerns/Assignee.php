<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Concerns;

use ByRcsc\LaravelAssignment\Models\Assignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Adds assignment history and workload readers to an assignee model.
 * Laravel morph maps are honored when assignment type columns are written.
 */
trait Assignee
{
    /** @return MorphMany<Assignment, $this> */
    public function assignments(): MorphMany
    {
        return $this->morphMany(Assignment::class, 'assignee');
    }

    /** @return MorphMany<Assignment, $this> */
    public function activeAssignments(): MorphMany
    {
        return $this->assignments()->active();
    }

    /** @return MorphMany<Assignment, $this> */
    public function openAssignments(): MorphMany
    {
        return $this->assignments()->open();
    }

    public function workload(?string $assignableType = null): int
    {
        $assignments = $this->openAssignments();

        if ($assignableType !== null) {
            $assignments->where('assignable_type', $this->assignmentMorphType($assignableType));
        }

        return $assignments->count();
    }

    private function assignmentMorphType(string $assignableType): string|int
    {
        if (! is_a($assignableType, Model::class, true)) {
            return $assignableType;
        }

        return Relation::getMorphAlias($assignableType);
    }
}

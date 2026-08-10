<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Concerns;

use ByRcsc\LaravelAssignment\Actions\AssignmentWriter;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Support\AssignmentMorphType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Adds assignment history and workload readers to an assignee model.
 * Laravel morph maps are honored when assignment type columns are written.
 */
trait Assignee
{
    public function accept(Assignment $assignment): Assignment
    {
        return app(AssignmentWriter::class)->accept($this, $assignment);
    }

    public function decline(Assignment $assignment): Assignment
    {
        return app(AssignmentWriter::class)->decline($this, $assignment);
    }

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
            $assignments->where('assignable_type', AssignmentMorphType::for($assignableType));
        }

        return $assignments->count();
    }
}

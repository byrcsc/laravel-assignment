<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Concerns;

use ByRcsc\LaravelAssignment\Actions\AssignmentWriter;
use ByRcsc\LaravelAssignment\AssignmentManager;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Models\QueuedAssignment;
use ByRcsc\LaravelAssignment\Support\AssignmentSlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Adds assignment history and current-state readers to an assignable model.
 * Laravel morph maps are honored when assignment type columns are written.
 */
trait Assignable
{
    public function autoAssign(?string $role = null): ?Assignment
    {
        return app(AssignmentManager::class)->autoAssign($this, $role);
    }

    public function assign(
        Model $assignee,
        ?string $role = null,
        ?Model $by = null,
    ): Assignment {
        return app(AssignmentWriter::class)->assign($this, $assignee, $role, $by);
    }

    public function unassign(?string $role = null, ?Model $by = null): Assignment
    {
        return app(AssignmentWriter::class)->unassign($this, $role, $by);
    }

    public function offer(
        Model $assignee,
        ?string $role = null,
        ?Model $by = null,
        ?int $ttlSeconds = null,
    ): Assignment {
        return app(AssignmentWriter::class)->offer($this, $assignee, $role, $by, $ttlSeconds);
    }

    public function reassign(
        Model $newAssignee,
        ?string $role = null,
        ?Model $by = null,
    ): Assignment {
        return app(AssignmentWriter::class)->reassign($this, $newAssignee, $role, $by);
    }

    /** @return MorphMany<Assignment, $this> */
    public function assignments(): MorphMany
    {
        return $this->morphMany(Assignment::class, 'assignable')
            ->oldest('created_at')
            ->oldest('id');
    }

    public function openAssignment(?string $role = null): ?Assignment
    {
        return $this->assignmentQueryForRole($role)->open()->first();
    }

    public function assignment(?string $role = null): ?Assignment
    {
        return $this->openAssignment($role);
    }

    public function assignee(?string $role = null): ?Model
    {
        return $this->openAssignment($role)?->assignee;
    }

    public function isAssigned(?string $role = null): bool
    {
        return $this->assignmentQueryForRole($role)->open()->exists();
    }

    public function queuedAssignment(?string $role = null): ?QueuedAssignment
    {
        return QueuedAssignment::query()
            ->where('assignable_type', $this->getMorphClass())
            ->where('assignable_id', $this->getKey())
            ->where('slot', AssignmentSlot::for($role))
            ->first();
    }

    /** @return MorphMany<Assignment, $this> */
    private function assignmentQueryForRole(?string $role): MorphMany
    {
        $assignments = $this->assignments();

        if ($role === null) {
            return $assignments->whereNull('role');
        }

        return $assignments->where('role', $role);
    }
}

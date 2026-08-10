<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Actions;

use ByRcsc\LaravelAssignment\Events\AssignableQueued;
use ByRcsc\LaravelAssignment\Exceptions\AlreadyAssigned;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Models\QueuedAssignment;
use ByRcsc\LaravelAssignment\Support\AssignmentMorphType;
use ByRcsc\LaravelAssignment\Support\AssignmentSlot;
use ByRcsc\LaravelAssignment\Support\RowClaimer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/** @internal */
final class AssignmentQueue
{
    public function __construct(private readonly RowClaimer $claimer) {}

    public function enqueue(
        Model $assignable,
        ?string $role = null,
        int $priority = 0,
    ): QueuedAssignment {
        return (new QueuedAssignment)->getConnection()->transaction(function () use (
            $assignable,
            $role,
            $priority,
        ): QueuedAssignment {
            if ($this->assignmentSlot($assignable, $role)->open()->exists()) {
                throw new AlreadyAssigned;
            }

            $entry = QueuedAssignment::query()->firstOrCreate(
                [
                    'assignable_type' => $assignable->getMorphClass(),
                    'assignable_id' => $assignable->getKey(),
                    'slot' => AssignmentSlot::for($role),
                ],
                [
                    'role' => $role,
                    'priority' => $priority,
                    'queued_at' => now(),
                ],
            );

            if ($entry->wasRecentlyCreated) {
                Event::dispatch(new AssignableQueued($entry));
            }

            return $entry;
        }, 5);
    }

    /** @param callable(Model, ?string): mixed $assign */
    public function flush(callable $assign, ?string $assignableType = null): int
    {
        $entryIds = $this->orderedEntries($assignableType)->pluck('id');
        $assigned = 0;

        foreach ($entryIds as $entryId) {
            $processed = (new QueuedAssignment)->getConnection()->transaction(
                function () use ($assign, $entryId): bool {
                    $entry = $this->claim($entryId);

                    if ($entry === null) {
                        return false;
                    }

                    $assignable = $entry->assignable;

                    if (! $assignable instanceof Model) {
                        return false;
                    }

                    if ($this->assignmentSlot($assignable, $entry->role)->open()->exists()) {
                        $entry->delete();

                        return false;
                    }

                    $assignment = $assign($assignable, $entry->role);

                    if (! $assignment instanceof Assignment) {
                        return false;
                    }

                    $entry->delete();

                    return true;
                },
                5,
            );

            if ($processed) {
                $assigned++;
            }
        }

        return $assigned;
    }

    /** @return Builder<QueuedAssignment> */
    private function orderedEntries(?string $assignableType): Builder
    {
        $query = QueuedAssignment::query()
            ->orderByDesc('priority')
            ->oldest('queued_at')
            ->oldest('id');

        if ($assignableType !== null) {
            $query->where('assignable_type', AssignmentMorphType::for($assignableType));
        }

        return $query;
    }

    private function claim(mixed $entryId): ?QueuedAssignment
    {
        return $this->claimer->claim(
            QueuedAssignment::query()->whereKey($entryId),
        );
    }

    /** @return Builder<Assignment> */
    private function assignmentSlot(Model $assignable, ?string $role): Builder
    {
        return Assignment::query()
            ->where('assignable_type', $assignable->getMorphClass())
            ->where('assignable_id', $assignable->getKey())
            ->where('slot', AssignmentSlot::for($role));
    }
}

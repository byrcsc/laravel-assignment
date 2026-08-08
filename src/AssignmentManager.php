<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment;

use ByRcsc\LaravelAssignment\Actions\AssignmentQueue;
use ByRcsc\LaravelAssignment\Actions\AssignmentWriter;
use ByRcsc\LaravelAssignment\Enums\Mode;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Support\AssignmentProfileResolver;
use ByRcsc\LaravelAssignment\Support\ScopeStateRepository;
use ByRcsc\LaravelAssignment\Support\SelectionPolicyResolver;
use Illuminate\Database\Eloquent\Model;

final readonly class AssignmentManager
{
    public function __construct(
        private AssignmentWriter $writer,
        private AssignmentQueue $queue,
        private ScopeStateRepository $states,
        private SelectionPolicyResolver $policies,
        private AssignmentProfileResolver $profiles,
    ) {}

    public function for(Model $assignable): PendingAssignment
    {
        return new PendingAssignment(
            $assignable,
            $this->writer,
            $this->queue,
            $this->states,
            $this->policies,
        );
    }

    public function autoAssign(Model $assignable, ?string $role = null): ?Assignment
    {
        $profile = $this->profiles->resolve($assignable);
        $pending = $this->for($assignable)
            ->among($profile->candidates($assignable, $role))
            ->role($role)
            ->scope($profile->scope($assignable, $role))
            ->using($profile->policy($assignable, $role));
        $mode = $profile->mode($assignable, $role);

        $assignment = $mode === Mode::Assign
            ? $pending->assign()
            : $pending
                ->expiresIn($profile->offerTtl($assignable, $role))
                ->offer();

        if ($assignment !== null) {
            return $assignment;
        }

        if ($profile->queueWhenEmpty($assignable, $role)) {
            $this->queue->enqueue(
                $assignable,
                $role,
                $profile->queuePriority($assignable, $role),
            );
        }

        return null;
    }

    public function flushQueue(?string $assignableType = null): int
    {
        return $this->queue->flush(
            fn (Model $assignable, ?string $role): ?Assignment => $this->autoAssign($assignable, $role),
            $assignableType,
        );
    }
}

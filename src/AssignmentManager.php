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
use Illuminate\Support\Str;

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
        return $this->pending($assignable);
    }

    private function pending(Model $assignable, ?string $cascadeId = null): PendingAssignment
    {
        return new PendingAssignment(
            $assignable,
            $this->writer,
            $this->queue,
            $this->states,
            $this->policies,
            $cascadeId,
        );
    }

    public function autoAssign(Model $assignable, ?string $role = null): ?Assignment
    {
        $profile = $this->profiles->resolve($assignable);
        $candidates = $profile->candidates($assignable, $role);
        $scope = $profile->scope($assignable, $role);
        $policy = $profile->policy($assignable, $role);
        $mode = $profile->mode($assignable, $role);
        $cascadeId = $mode === Mode::Offer ? (string) Str::ulid() : null;
        $pending = $this->pending($assignable, $cascadeId)
            ->among($candidates)
            ->role($role)
            ->scope($scope)
            ->using($policy);
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

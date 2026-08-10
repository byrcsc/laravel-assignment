<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Actions;

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Events\OffersExhausted;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\PendingAssignment;
use ByRcsc\LaravelAssignment\Support\AssignmentProfileResolver;
use ByRcsc\LaravelAssignment\Support\AssignmentSlot;
use ByRcsc\LaravelAssignment\Support\ModelIdentity;
use ByRcsc\LaravelAssignment\Support\ScopeStateRepository;
use ByRcsc\LaravelAssignment\Support\SelectionPolicyResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/** @internal */
final readonly class OfferCascade
{
    public function __construct(
        private AssignmentProfileResolver $profiles,
        private AssignmentQueue $queue,
        private AssignmentWriter $writer,
        private ScopeStateRepository $states,
        private SelectionPolicyResolver $policies,
    ) {}

    public function advance(Assignment $lastOffer): ?Assignment
    {
        if ($lastOffer->cascade_id === null) {
            return null;
        }

        if ($lastOffer->status !== AssignmentStatus::Ended) {
            return null;
        }

        if (! in_array($lastOffer->ended_reason, [EndReason::Declined, EndReason::Expired], true)) {
            return null;
        }

        $assignable = $lastOffer->assignable;

        if (! $assignable instanceof Model) {
            return null;
        }

        if ($this->slotIsOccupied($assignable, $lastOffer->role)) {
            return null;
        }

        $profile = $this->profiles->resolve($assignable);
        $candidates = $this->remainingCandidates(
            $profile->candidates($assignable, $lastOffer->role),
            $lastOffer->cascade_id,
        );
        $offer = (new PendingAssignment(
            $assignable,
            $this->writer,
            $this->queue,
            $this->states,
            $this->policies,
            $lastOffer->cascade_id,
        ))
            ->among($candidates)
            ->role($lastOffer->role)
            ->scope($profile->scope($assignable, $lastOffer->role))
            ->using($profile->policy($assignable, $lastOffer->role))
            ->expiresIn($profile->offerTtl($assignable, $lastOffer->role))
            ->offer();

        if ($offer !== null) {
            return $offer;
        }

        if ($profile->queueWhenEmpty($assignable, $lastOffer->role)) {
            $this->queue->enqueue(
                $assignable,
                $lastOffer->role,
                $profile->queuePriority($assignable, $lastOffer->role),
            );
        }

        Event::dispatch(new OffersExhausted($lastOffer));

        return null;
    }

    public function expire(int|string $assignmentId): ?Assignment
    {
        $expired = $this->writer->expire($assignmentId);

        if ($expired === null) {
            return null;
        }

        $this->advance($expired);

        return $expired;
    }

    public function expireOverdue(): int
    {
        $offers = Assignment::query()
            ->where('status', AssignmentStatus::Offered->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->lazyById(100);
        $expired = 0;

        foreach ($offers as $offer) {
            if ($this->expire(ModelIdentity::key($offer)) !== null) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * @param  iterable<mixed>  $candidates
     * @return Collection<int, mixed>
     */
    private function remainingCandidates(iterable $candidates, string $cascadeId): Collection
    {
        $excluded = Assignment::query()
            ->where('cascade_id', $cascadeId)
            ->where('status', AssignmentStatus::Ended->value)
            ->whereIn('ended_reason', [EndReason::Declined->value, EndReason::Expired->value])
            ->get(['assignee_type', 'assignee_id'])
            ->map(fn (Assignment $assignment): string => ModelIdentity::from(
                $assignment->assignee_type,
                $assignment->assignee_id,
            ))
            ->all();

        $remaining = [];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof Model) {
                if (in_array(ModelIdentity::for($candidate), $excluded, true)) {
                    continue;
                }
            }

            $remaining[] = $candidate;
        }

        return new Collection($remaining);
    }

    private function slotIsOccupied(Model $assignable, ?string $role): bool
    {
        return Assignment::query()
            ->where('assignable_type', $assignable->getMorphClass())
            ->where('assignable_id', $assignable->getKey())
            ->where('slot', AssignmentSlot::for($role))
            ->open()
            ->exists();
    }
}

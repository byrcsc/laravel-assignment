<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Policies;

use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\ScopeState;
use ByRcsc\LaravelAssignment\Support\AssignmentMorphType;
use ByRcsc\LaravelAssignment\Support\ModelIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use UnexpectedValueException;

final readonly class LeastWorkload implements SelectionPolicy
{
    public function __construct(private ?string $assignableType = null) {}

    public static function of(string $assignableType): self
    {
        return new self($assignableType);
    }

    /** @param Collection<int, Model> $candidates */
    public function select(Model $assignable, Collection $candidates, ScopeState $state): ?Model
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $workloads = $this->workloads($candidates);
        $lastAssignments = $this->lastAssignments($candidates);
        $selected = null;

        foreach ($candidates as $candidate) {
            if ($selected === null) {
                $selected = $candidate;

                continue;
            }

            if ($this->isBetter($candidate, $selected, $workloads, $lastAssignments)) {
                $selected = $candidate;
            }
        }

        return $selected;
    }

    /**
     * @param  Collection<int, Model>  $candidates
     * @return array<string, int>
     */
    private function workloads(Collection $candidates): array
    {
        $query = Assignment::query()
            ->select(['assignee_type', 'assignee_id'])
            ->selectRaw('COUNT(*) AS aggregate')
            ->open();

        if ($this->assignableType !== null) {
            $query->where('assignable_type', AssignmentMorphType::for($this->assignableType));
        }

        $this->constrainCandidates($query, $candidates);
        $rows = $query
            ->groupBy(['assignee_type', 'assignee_id'])
            ->get();
        $workloads = [];

        foreach ($rows as $row) {
            $aggregate = $row->getAttribute('aggregate');

            if (! is_int($aggregate)) {
                if (! is_string($aggregate)) {
                    throw new UnexpectedValueException('The workload aggregate must be numeric.');
                }

                if (! is_numeric($aggregate)) {
                    throw new UnexpectedValueException('The workload aggregate must be numeric.');
                }
            }

            $workloads[$this->rowIdentity($row)] = (int) $aggregate;
        }

        return $workloads;
    }

    /**
     * @param  Collection<int, Model>  $candidates
     * @return array<string, string>
     */
    private function lastAssignments(Collection $candidates): array
    {
        $query = Assignment::query()
            ->select(['assignee_type', 'assignee_id'])
            ->selectRaw('MAX(created_at) AS last_assigned_at');

        $this->constrainCandidates($query, $candidates);
        $rows = $query
            ->groupBy(['assignee_type', 'assignee_id'])
            ->get();
        $lastAssignments = [];

        foreach ($rows as $row) {
            $lastAssignedAt = $row->getAttribute('last_assigned_at');

            if ($lastAssignedAt === null) {
                continue;
            }

            if (! is_string($lastAssignedAt)) {
                throw new UnexpectedValueException('The latest assignment timestamp must be a string.');
            }

            $lastAssignments[$this->rowIdentity($row)] = $lastAssignedAt;
        }

        return $lastAssignments;
    }

    /**
     * @param  Builder<Assignment>  $query
     * @param  Collection<int, Model>  $candidates
     */
    private function constrainCandidates(Builder $query, Collection $candidates): void
    {
        $groups = [];

        foreach ($candidates as $candidate) {
            $groups[$candidate->getMorphClass()][] = ModelIdentity::key($candidate);
        }

        $query->where(function (Builder $query) use ($groups): void {
            foreach ($groups as $type => $keys) {
                $query->orWhere(function (Builder $query) use ($type, $keys): void {
                    $query
                        ->where('assignee_type', $type)
                        ->whereIn('assignee_id', $keys);
                });
            }
        });
    }

    /**
     * @param  array<string, int>  $workloads
     * @param  array<string, string>  $lastAssignments
     */
    private function isBetter(
        Model $candidate,
        Model $selected,
        array $workloads,
        array $lastAssignments,
    ): bool {
        $candidateIdentity = ModelIdentity::for($candidate);
        $selectedIdentity = ModelIdentity::for($selected);
        $candidateWorkload = $workloads[$candidateIdentity] ?? 0;
        $selectedWorkload = $workloads[$selectedIdentity] ?? 0;

        if ($candidateWorkload !== $selectedWorkload) {
            return $candidateWorkload < $selectedWorkload;
        }

        $candidateLast = $lastAssignments[$candidateIdentity] ?? null;
        $selectedLast = $lastAssignments[$selectedIdentity] ?? null;

        if ($candidateLast === null) {
            return $selectedLast !== null;
        }

        if ($selectedLast === null) {
            return false;
        }

        return $candidateLast < $selectedLast;
    }

    private function rowIdentity(Assignment $assignment): string
    {
        return ModelIdentity::from($assignment->assignee_type, $assignment->assignee_id);
    }
}

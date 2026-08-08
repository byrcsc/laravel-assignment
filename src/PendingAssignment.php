<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment;

use ByRcsc\LaravelAssignment\Actions\AssignmentQueue;
use ByRcsc\LaravelAssignment\Actions\AssignmentWriter;
use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Models\AssignmentScope;
use ByRcsc\LaravelAssignment\Models\QueuedAssignment;
use ByRcsc\LaravelAssignment\Policies\Random;
use ByRcsc\LaravelAssignment\Support\ModelIdentity;
use ByRcsc\LaravelAssignment\Support\ScopeStateRepository;
use ByRcsc\LaravelAssignment\Support\SelectionPolicyResolver;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use UnexpectedValueException;

final class PendingAssignment
{
    /** @var iterable<mixed>|Closure */
    private iterable|Closure $candidates = [];

    private ?string $role = null;

    private ?string $scope = null;

    private string|SelectionPolicy|Closure $policy = Random::class;

    private ?Model $actor = null;

    private ?int $ttlSeconds = null;

    public function __construct(
        private readonly Model $assignable,
        private readonly AssignmentWriter $writer,
        private readonly AssignmentQueue $queue,
        private readonly ScopeStateRepository $states,
        private readonly SelectionPolicyResolver $policies,
    ) {}

    /** @param iterable<mixed>|Closure $candidates */
    public function among(iterable|Closure $candidates): self
    {
        $this->candidates = $candidates;

        return $this;
    }

    public function role(?string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function scope(?string $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function using(string|SelectionPolicy|Closure $policy): self
    {
        $this->policy = $policy;

        return $this;
    }

    public function by(?Model $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function expiresIn(?int $seconds): self
    {
        $this->ttlSeconds = $seconds;

        return $this;
    }

    public function assign(): ?Assignment
    {
        return $this->write(offer: false);
    }

    public function offer(): ?Assignment
    {
        return $this->write(offer: true);
    }

    public function queue(int $priority = 0): QueuedAssignment
    {
        return $this->queue->enqueue($this->assignable, $this->role, $priority);
    }

    private function write(bool $offer): ?Assignment
    {
        $connection = (new AssignmentScope)->getConnection();

        return $connection->transaction(function () use ($offer): ?Assignment {
            $scope = $this->resolvedScope();
            $state = $this->states->lock($scope);
            $candidates = $this->resolveCandidates();

            if ($candidates->isEmpty()) {
                return null;
            }

            $policy = $this->policies->resolve($this->policy);
            $selected = $policy->select($this->assignable, $candidates, $state);

            if ($selected === null) {
                return null;
            }

            if (! $this->contains($candidates, $selected)) {
                throw new UnexpectedValueException('An assignment policy must select from the candidate collection.');
            }

            $assignment = $offer
                ? $this->writer->offer(
                    $this->assignable,
                    $selected,
                    $this->role,
                    $this->actor,
                    $this->ttlSeconds,
                    $scope,
                )
                : $this->writer->assign(
                    $this->assignable,
                    $selected,
                    $this->role,
                    $this->actor,
                    $scope,
                );

            $state->persist();

            return $assignment;
        }, 5);
    }

    /** @return Collection<int, Model> */
    private function resolveCandidates(): Collection
    {
        $resolved = $this->candidates instanceof Closure
            ? ($this->candidates)($this->assignable)
            : $this->candidates;

        if (! is_iterable($resolved)) {
            throw new UnexpectedValueException('Assignment candidates must be iterable.');
        }

        $models = [];

        foreach ($resolved as $candidate) {
            if (! $candidate instanceof Model || ! $candidate->exists) {
                throw new UnexpectedValueException('Assignment candidates must be persisted Eloquent models.');
            }

            ModelIdentity::for($candidate);
            $models[] = $candidate;
        }

        return new Collection($models);
    }

    /** @param Collection<int, Model> $candidates */
    private function contains(Collection $candidates, Model $selected): bool
    {
        return $candidates->contains(
            fn (Model $candidate): bool => ModelIdentity::matches($candidate, $selected),
        );
    }

    private function resolvedScope(): string
    {
        if ($this->scope !== null) {
            return $this->scope;
        }

        $assignableClass = $this->assignable::class;

        return "{$assignableClass}:{$this->role}";
    }
}

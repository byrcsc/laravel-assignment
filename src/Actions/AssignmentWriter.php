<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Actions;

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Events\Assigned;
use ByRcsc\LaravelAssignment\Events\AssignmentAccepted;
use ByRcsc\LaravelAssignment\Events\AssignmentDeclined;
use ByRcsc\LaravelAssignment\Events\AssignmentOffered;
use ByRcsc\LaravelAssignment\Events\Unassigned;
use ByRcsc\LaravelAssignment\Exceptions\InvalidTransition;
use ByRcsc\LaravelAssignment\Exceptions\NotAssigned;
use ByRcsc\LaravelAssignment\Exceptions\NotTheAssignee;
use ByRcsc\LaravelAssignment\Exceptions\OfferNotOpen;
use ByRcsc\LaravelAssignment\Exceptions\SlotOccupied;
use ByRcsc\LaravelAssignment\Models\Assignment;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

final class AssignmentWriter
{
    public function assign(
        Model $assignable,
        Model $assignee,
        ?string $role = null,
        ?Model $by = null,
        ?string $scope = null,
    ): Assignment {
        return $this->createAssignment(
            $assignable,
            $assignee,
            AssignmentStatus::Active,
            $role,
            $by,
            $scope,
        );
    }

    public function offer(
        Model $assignable,
        Model $assignee,
        ?string $role = null,
        ?Model $by = null,
        ?int $ttlSeconds = null,
        ?string $scope = null,
    ): Assignment {
        return $this->createAssignment(
            $assignable,
            $assignee,
            AssignmentStatus::Offered,
            $role,
            $by,
            $scope,
            $ttlSeconds,
        );
    }

    public function accept(Model $assignee, Assignment $assignment): Assignment
    {
        return $this->transaction(function () use ($assignee, $assignment): Assignment {
            $current = $this->lockedAssignment($assignment);

            $this->ensureTargetedAssignee($current, $assignee);
            $this->ensureOpenOffer($current);

            $current->forceFill([
                'status' => AssignmentStatus::Active,
                'accepted_at' => now(),
            ])->save();

            $this->syncInstance($assignment, $current);
            Event::dispatch(new AssignmentAccepted($assignment));
            Event::dispatch(new Assigned($assignment));

            return $assignment;
        });
    }

    public function decline(Model $assignee, Assignment $assignment): Assignment
    {
        return $this->transaction(function () use ($assignee, $assignment): Assignment {
            $current = $this->lockedAssignment($assignment);

            $this->ensureTargetedAssignee($current, $assignee);
            $this->ensureOpenOffer($current);
            $this->end($current, EndReason::Declined);
            $this->syncInstance($assignment, $current);

            Event::dispatch(new AssignmentDeclined($assignment));

            return $assignment;
        });
    }

    private function createAssignment(
        Model $assignable,
        Model $assignee,
        AssignmentStatus $status,
        ?string $role,
        ?Model $by,
        ?string $scope,
        ?int $ttlSeconds = null,
    ): Assignment {
        try {
            return $this->transaction(function () use (
                $assignable,
                $assignee,
                $status,
                $role,
                $by,
                $scope,
                $ttlSeconds,
            ): Assignment {
                $this->releaseExpiredOffer($assignable, $role);

                $attributes = [
                    'assignable_type' => $assignable->getMorphClass(),
                    'assignable_id' => $assignable->getKey(),
                    'assignee_type' => $assignee->getMorphClass(),
                    'assignee_id' => $assignee->getKey(),
                    'role' => $role,
                    'slot' => $this->slot($role),
                    'status' => $status,
                    'scope' => $scope,
                    'assigned_by_type' => $by?->getMorphClass(),
                    'assigned_by_id' => $by?->getKey(),
                ];

                if ($status === AssignmentStatus::Offered) {
                    $offeredAt = now();
                    $attributes['offered_at'] = $offeredAt;

                    if ($ttlSeconds !== null) {
                        $attributes['expires_at'] = $offeredAt->copy()->addSeconds($ttlSeconds);
                    }
                }

                $assignment = Assignment::query()->create($attributes);

                if ($status === AssignmentStatus::Active) {
                    Event::dispatch(new Assigned($assignment));

                    return $assignment;
                }

                Event::dispatch(new AssignmentOffered($assignment));

                return $assignment;
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            throw new SlotOccupied($exception);
        }
    }

    public function unassign(Model $assignable, ?string $role = null, ?Model $by = null): Assignment
    {
        return $this->transaction(function () use ($assignable, $role): Assignment {
            $assignment = $this->slotQuery($assignable, $role)
                ->where('status', AssignmentStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                throw new NotAssigned;
            }

            $this->end($assignment, EndReason::Unassigned);
            Event::dispatch(new Unassigned($assignment));

            return $assignment;
        });
    }

    public function complete(Assignment $assignment, ?Model $by = null): Assignment
    {
        return $this->transaction(function () use ($assignment): Assignment {
            $current = Assignment::query()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw new InvalidTransition;
            }

            if (! $current->isActive()) {
                throw new InvalidTransition;
            }

            $this->end($current, EndReason::Completed);

            $this->syncInstance($assignment, $current);

            return $assignment;
        });
    }

    private function lockedAssignment(Assignment $assignment): Assignment
    {
        $current = Assignment::query()
            ->whereKey($assignment->getKey())
            ->lockForUpdate()
            ->first();

        if ($current === null) {
            throw new OfferNotOpen;
        }

        return $current;
    }

    private function ensureTargetedAssignee(Assignment $assignment, Model $assignee): void
    {
        if ($assignment->assignee_type !== $assignee->getMorphClass()) {
            throw new NotTheAssignee;
        }

        $assignmentKey = $this->modelKeyString($assignment->assignee_id);
        $assigneeKey = $this->modelKeyString($assignee->getKey());

        if ($assignmentKey === null) {
            throw new NotTheAssignee;
        }

        if ($assignmentKey !== $assigneeKey) {
            throw new NotTheAssignee;
        }
    }

    private function ensureOpenOffer(Assignment $assignment): void
    {
        if (! $assignment->isOffered()) {
            throw new OfferNotOpen;
        }

        if ($assignment->isExpired()) {
            throw new OfferNotOpen;
        }
    }

    private function modelKeyString(mixed $key): ?string
    {
        if (is_string($key)) {
            return $key;
        }

        if (is_int($key)) {
            return (string) $key;
        }

        return null;
    }

    private function syncInstance(Assignment $assignment, Assignment $current): void
    {
        $assignment->setRawAttributes($current->getAttributes(), true);
        $assignment->setRelations([]);
    }

    private function releaseExpiredOffer(Model $assignable, ?string $role): void
    {
        $assignment = $this->slotQuery($assignable, $role)
            ->lockForUpdate()
            ->first();

        if ($assignment === null) {
            return;
        }

        if (! $assignment->isExpired()) {
            throw new SlotOccupied;
        }

        $this->end($assignment, EndReason::Expired);
    }

    private function end(Assignment $assignment, EndReason $reason): Assignment
    {
        $assignment->forceFill([
            'status' => AssignmentStatus::Ended,
            'slot' => null,
            'ended_at' => now(),
            'ended_reason' => $reason,
        ])->save();

        return $assignment;
    }

    /** @return Builder<Assignment> */
    private function slotQuery(Model $assignable, ?string $role): Builder
    {
        return Assignment::query()
            ->where('assignable_type', $assignable->getMorphClass())
            ->where('assignable_id', $assignable->getKey())
            ->where('slot', $this->slot($role));
    }

    private function slot(?string $role): string
    {
        return $role ?? '';
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $this->sqlState($exception);
        $driver = (new Assignment)->getConnection()->getDriverName();

        return match ($driver) {
            'mysql' => $this->isMySqlSlotViolation($exception, $sqlState),
            'pgsql' => $this->isPostgreSqlSlotViolation($exception, $sqlState),
            'sqlite' => $this->isSqliteSlotViolation($exception, $sqlState),
            default => false,
        };
    }

    private function isMySqlSlotViolation(QueryException $exception, ?string $sqlState): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        if (! is_int($driverCode) && ! is_string($driverCode)) {
            return false;
        }

        if ($sqlState !== '23000') {
            return false;
        }

        if ((string) $driverCode !== '1062') {
            return false;
        }

        return str_contains($exception->getMessage(), 'assignments_open_slot_unique');
    }

    private function isPostgreSqlSlotViolation(QueryException $exception, ?string $sqlState): bool
    {
        if ($sqlState !== '23505') {
            return false;
        }

        return str_contains($exception->getMessage(), 'assignments_open_slot_unique');
    }

    private function isSqliteSlotViolation(QueryException $exception, ?string $sqlState): bool
    {
        if ($sqlState !== '23000') {
            return false;
        }

        $message = $exception->getMessage();
        $table = (new Assignment)->getTable();

        foreach (['assignable_type', 'assignable_id', 'slot'] as $column) {
            if (! str_contains($message, "{$table}.{$column}")) {
                return false;
            }
        }

        return true;
    }

    private function sqlState(QueryException $exception): ?string
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        if (is_string($sqlState)) {
            return $sqlState;
        }

        if (is_int($sqlState)) {
            return (string) $sqlState;
        }

        return null;
    }

    /**
     * @template TransactionResult
     *
     * @param  callable(): TransactionResult  $callback
     * @return TransactionResult
     */
    private function transaction(callable $callback): mixed
    {
        return (new Assignment)->getConnection()->transaction(
            fn (Connection $connection): mixed => $callback(),
            5,
        );
    }
}

<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Actions;

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Events\Assigned;
use ByRcsc\LaravelAssignment\Events\Unassigned;
use ByRcsc\LaravelAssignment\Exceptions\InvalidTransition;
use ByRcsc\LaravelAssignment\Exceptions\NotAssigned;
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
        try {
            return $this->transaction(function () use ($assignable, $assignee, $role, $by, $scope): Assignment {
                $this->releaseExpiredOffer($assignable, $role);

                $assignment = Assignment::query()->create([
                    'assignable_type' => $assignable->getMorphClass(),
                    'assignable_id' => $assignable->getKey(),
                    'assignee_type' => $assignee->getMorphClass(),
                    'assignee_id' => $assignee->getKey(),
                    'role' => $role,
                    'slot' => $this->slot($role),
                    'status' => AssignmentStatus::Active,
                    'scope' => $scope,
                    'assigned_by_type' => $by?->getMorphClass(),
                    'assigned_by_id' => $by?->getKey(),
                ]);

                Event::dispatch(new Assigned($assignment));

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

            $assignment->setRawAttributes($current->getAttributes(), true);
            $assignment->setRelations([]);

            return $assignment;
        });
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

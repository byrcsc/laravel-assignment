<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Actions;

use ByRcsc\LaravelAssignment\Data\AssignmentData;
use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Events\Assigned;
use ByRcsc\LaravelAssignment\Events\AssignmentAccepted;
use ByRcsc\LaravelAssignment\Events\AssignmentDeclined;
use ByRcsc\LaravelAssignment\Events\AssignmentOffered;
use ByRcsc\LaravelAssignment\Events\OfferExpired;
use ByRcsc\LaravelAssignment\Events\Reassigned;
use ByRcsc\LaravelAssignment\Events\Unassigned;
use ByRcsc\LaravelAssignment\Exceptions\AlreadyAssigned;
use ByRcsc\LaravelAssignment\Exceptions\InvalidTransition;
use ByRcsc\LaravelAssignment\Exceptions\NotAssigned;
use ByRcsc\LaravelAssignment\Exceptions\NotTheAssignee;
use ByRcsc\LaravelAssignment\Exceptions\OfferNotOpen;
use ByRcsc\LaravelAssignment\Exceptions\SlotOccupied;
use ByRcsc\LaravelAssignment\Jobs\AdvanceExpiredOffer;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Models\QueuedAssignment;
use ByRcsc\LaravelAssignment\Support\AssignmentSlot;
use ByRcsc\LaravelAssignment\Support\ModelIdentity;
use ByRcsc\LaravelAssignment\Support\RowClaimer;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

final class AssignmentWriter
{
    public function __construct(private readonly RowClaimer $claimer) {}

    public function assign(
        Model $assignable,
        Model $assignee,
        ?string $role = null,
        ?Model $by = null,
        ?string $scope = null,
    ): Assignment {
        return $this->createAssignment(new AssignmentData(
            assignable: $assignable,
            assignee: $assignee,
            status: AssignmentStatus::Active,
            role: $role,
            by: $by,
            scope: $scope,
        ));
    }

    public function offer(
        Model $assignable,
        Model $assignee,
        ?string $role = null,
        ?Model $by = null,
        ?int $ttlSeconds = null,
        ?string $scope = null,
        ?string $cascadeId = null,
    ): Assignment {
        return $this->createAssignment(new AssignmentData(
            assignable: $assignable,
            assignee: $assignee,
            status: AssignmentStatus::Offered,
            role: $role,
            by: $by,
            scope: $scope,
            ttlSeconds: $ttlSeconds,
            cascadeId: $cascadeId,
        ));
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
        $declined = $this->transaction(function () use ($assignee, $assignment): Assignment {
            $current = $this->lockedAssignment($assignment);

            $this->ensureTargetedAssignee($current, $assignee);
            $this->ensureOpenOffer($current);
            $this->end($current, EndReason::Declined);
            $this->syncInstance($assignment, $current);

            Event::dispatch(new AssignmentDeclined($assignment));

            return $assignment;
        });

        if ($declined->cascade_id !== null) {
            app(OfferCascade::class)->advance($declined);
        }

        return $declined;
    }

    public function reassign(
        Model $assignable,
        Model $newAssignee,
        ?string $role = null,
        ?Model $by = null,
    ): Assignment {
        $observed = $this->slotQuery($assignable, $role)->open()->first();

        if ($observed === null) {
            throw new NotAssigned;
        }

        return $this->writeSlot(function () use ($assignable, $newAssignee, $role, $by, $observed): Assignment {
            return $this->transaction(function () use (
                $assignable,
                $newAssignee,
                $role,
                $by,
                $observed,
            ): Assignment {
                $current = $this->slotQuery($assignable, $role)
                    ->open()
                    ->lockForUpdate()
                    ->first();

                if ($current === null || ! $current->is($observed)) {
                    throw new SlotOccupied;
                }

                if ($this->isAssignedTo($current, $newAssignee)) {
                    throw new AlreadyAssigned;
                }

                $this->end($current, EndReason::Reassigned);
                $replacement = $this->insertAssignment(new AssignmentData(
                    assignable: $assignable,
                    assignee: $newAssignee,
                    status: AssignmentStatus::Active,
                    role: $role,
                    by: $by,
                ));

                Event::dispatch(new Unassigned($current));
                Event::dispatch(new Assigned($replacement));
                Event::dispatch(new Reassigned($current, $replacement));

                return $replacement;
            });
        });
    }

    private function createAssignment(AssignmentData $data): Assignment
    {
        return $this->writeSlot(function () use ($data): Assignment {
            return $this->transaction(function () use ($data): Assignment {
                $this->releaseExpiredOffer($data->assignable, $data->role);
                $assignment = $this->insertAssignment($data);

                if ($data->status === AssignmentStatus::Active) {
                    Event::dispatch(new Assigned($assignment));

                    return $assignment;
                }

                Event::dispatch(new AssignmentOffered($assignment));

                return $assignment;
            });
        });
    }

    public function unassign(Model $assignable, ?string $role = null, ?Model $by = null): Assignment
    {
        return $this->transaction(function () use ($assignable, $role): Assignment {
            $assignment = $this->slotQuery($assignable, $role)
                ->open()
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

    public function expire(int|string $assignmentId): ?Assignment
    {
        return $this->transaction(function () use ($assignmentId): ?Assignment {
            $query = Assignment::query()
                ->whereKey($assignmentId)
                ->where('status', AssignmentStatus::Offered->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now());
            $assignment = $this->claimer->claim($query);

            if ($assignment === null) {
                return null;
            }

            $this->end($assignment, EndReason::Expired);
            Event::dispatch(new OfferExpired($assignment));

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
        if (! $this->isAssignedTo($assignment, $assignee)) {
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

    private function isAssignedTo(Assignment $assignment, Model $assignee): bool
    {
        if ($assignment->assignee_type !== $assignee->getMorphClass()) {
            return false;
        }

        $assignmentKey = $this->modelKeyString($assignment->assignee_id);
        $assigneeKey = $this->modelKeyString($assignee->getKey());

        return $assignmentKey !== null && $assignmentKey === $assigneeKey;
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

    private function insertAssignment(AssignmentData $data): Assignment
    {
        $attributes = [
            'assignable_type' => $data->assignable->getMorphClass(),
            'assignable_id' => $data->assignable->getKey(),
            'assignee_type' => $data->assignee->getMorphClass(),
            'assignee_id' => $data->assignee->getKey(),
            'role' => $data->role,
            'slot' => AssignmentSlot::for($data->role),
            'status' => $data->status,
            'scope' => $data->scope,
            'cascade_id' => $data->cascadeId,
            'assigned_by_type' => $data->by?->getMorphClass(),
            'assigned_by_id' => $data->by?->getKey(),
        ];

        if ($data->status === AssignmentStatus::Offered) {
            $offeredAt = now();
            $attributes['offered_at'] = $offeredAt;

            if ($data->ttlSeconds !== null) {
                $attributes['expires_at'] = $offeredAt->copy()->addSeconds($data->ttlSeconds);
            }
        }

        $assignment = Assignment::query()->create($attributes);

        QueuedAssignment::query()
            ->where('assignable_type', $data->assignable->getMorphClass())
            ->where('assignable_id', $data->assignable->getKey())
            ->where('slot', AssignmentSlot::for($data->role))
            ->delete();

        if ($assignment->cascade_id !== null && $assignment->expires_at !== null) {
            if (config('assignment.dispatch_expiry_jobs') === true) {
                Bus::dispatch(
                    (new AdvanceExpiredOffer(ModelIdentity::key($assignment)))
                        ->delay($assignment->expires_at),
                );
            }
        }

        return $assignment;
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
            ->where('slot', AssignmentSlot::for($role));
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

    /** @param callable(): Assignment $callback */
    private function writeSlot(callable $callback): Assignment
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            throw new SlotOccupied($exception);
        }
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

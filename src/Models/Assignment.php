<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Models;

use ByRcsc\LaravelAssignment\Actions\AssignmentWriter;
use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $assignable_type
 * @property int|string $assignable_id
 * @property string $assignee_type
 * @property int|string $assignee_id
 * @property ?string $role
 * @property ?string $cascade_id
 * @property AssignmentStatus $status
 * @property ?EndReason $ended_reason
 * @property ?CarbonImmutable $offered_at
 * @property ?CarbonImmutable $accepted_at
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $ended_at
 */
final class Assignment extends Model
{
    /** @var array<int, string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['slot'];

    public function getTable(): string
    {
        $table = config('assignment.tables.assignments');

        if (! is_string($table)) {
            return 'assignments';
        }

        return $table;
    }

    /** @return MorphTo<Model, $this> */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function assignee(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function assignedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        if ($this->isActive()) {
            return true;
        }

        return $this->isOffered() && ! $this->isExpired();
    }

    public function isActive(): bool
    {
        return $this->status === AssignmentStatus::Active;
    }

    public function isOffered(): bool
    {
        return $this->status === AssignmentStatus::Offered;
    }

    public function isEnded(): bool
    {
        return $this->status === AssignmentStatus::Ended;
    }

    public function isExpired(): bool
    {
        if (! $this->isOffered()) {
            return false;
        }

        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo(now());
    }

    public function complete(?Model $by = null): self
    {
        return app(AssignmentWriter::class)->complete($this, $by);
    }

    /**
     * @param  Builder<Assignment>  $query
     * @return Builder<Assignment>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('status', AssignmentStatus::Active->value)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('status', AssignmentStatus::Offered->value)
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                        });
                });
        });
    }

    /**
     * @param  Builder<Assignment>  $query
     * @return Builder<Assignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::Active->value);
    }

    /**
     * @param  Builder<Assignment>  $query
     * @return Builder<Assignment>
     */
    public function scopeOffered(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::Offered->value);
    }

    /**
     * @param  Builder<Assignment>  $query
     * @return Builder<Assignment>
     */
    public function scopeEnded(Builder $query): Builder
    {
        return $query->where('status', AssignmentStatus::Ended->value);
    }

    /**
     * @param  Builder<Assignment>  $query
     * @return Builder<Assignment>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->where('status', AssignmentStatus::Offered->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'ended_reason' => EndReason::class,
            'offered_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}

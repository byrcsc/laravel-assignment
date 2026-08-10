<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $assignable_type
 * @property int|string $assignable_id
 * @property ?string $role
 * @property int $priority
 * @property CarbonImmutable $queued_at
 */
final class QueuedAssignment extends Model
{
    /** @var array<int, string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['slot'];

    public function getTable(): string
    {
        $table = config('assignment.tables.queue');

        if (! is_string($table)) {
            return 'assignment_queue';
        }

        return $table;
    }

    /** @return MorphTo<Model, $this> */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'queued_at' => 'immutable_datetime',
        ];
    }
}

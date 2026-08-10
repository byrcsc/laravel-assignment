<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Models;

use ByRcsc\LaravelAssignment\ScopeState;
use Illuminate\Database\Eloquent\Model;

/**
 * Bookkeeping for stateful policies. Applications reach the state through
 * {@see ScopeState}, never through this row.
 *
 * @property array<string, mixed> $state
 *
 * @internal
 */
final class AssignmentScope extends Model
{
    /** @var array<int, string> */
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('assignment.tables.scopes');

        if (! is_string($table)) {
            return 'assignment_scopes';
        }

        return $table;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['state' => 'array'];
    }
}

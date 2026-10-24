<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use ByRcsc\LaravelAssignment\Models\AssignmentScope;
use ByRcsc\LaravelAssignment\ScopeState;

/** @internal */
final class ScopeStateRepository
{
    /**
     * Creates the scope row if it is missing. Call this before opening the
     * transaction that calls lock().
     *
     * InnoDB takes a shared lock on the row that `insert ... ignore` collides
     * with. Inside the locking transaction, every caller then upgrades that
     * lock to exclusive, and two callers deadlock. A committed row also keeps
     * the later `for update` on that row. Without the row, InnoDB locks the
     * gap where the row would go, and unrelated scopes in the same gap block
     * each other.
     */
    public function ensure(string $scope): void
    {
        $now = now();

        AssignmentScope::query()->insertOrIgnore([
            'scope' => $scope,
            'state' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function lock(string $scope): ScopeState
    {
        $model = AssignmentScope::query()
            ->where('scope', $scope)
            ->lockForUpdate()
            ->first();

        if ($model === null) {
            $this->ensure($scope);

            $model = AssignmentScope::query()
                ->where('scope', $scope)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return new ScopeState($model);
    }
}

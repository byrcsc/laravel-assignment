<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use ByRcsc\LaravelAssignment\Models\AssignmentScope;
use ByRcsc\LaravelAssignment\ScopeState;

final class ScopeStateRepository
{
    /**
     * Create the scope row if it is missing, outside any transaction the
     * caller is about to open.
     *
     * InnoDB gives `insert ... ignore` a shared lock on the row it collides
     * with, so seeding inside the locking transaction makes every caller
     * upgrade that shared lock to exclusive, and two of them deadlock. Seeding
     * here commits immediately and releases the lock, which also keeps the
     * later `for update` on an existing row rather than on a gap: without a
     * row to point at, InnoDB locks the gap where it would go, and two
     * unrelated scopes that fall in the same gap block each other.
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

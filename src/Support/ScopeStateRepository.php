<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use ByRcsc\LaravelAssignment\Models\AssignmentScope;
use ByRcsc\LaravelAssignment\ScopeState;

/** @internal */
final class ScopeStateRepository
{
    public function lock(string $scope): ScopeState
    {
        $now = now();

        AssignmentScope::query()->insertOrIgnore([
            'scope' => $scope,
            'state' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $model = AssignmentScope::query()
            ->where('scope', $scope)
            ->lockForUpdate()
            ->firstOrFail();

        return new ScopeState($model);
    }
}

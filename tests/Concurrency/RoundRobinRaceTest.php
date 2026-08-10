<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Facades\Assignment as AssignmentFacade;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Models\AssignmentScope;
use ByRcsc\LaravelAssignment\Policies\RoundRobin;
use ByRcsc\LaravelAssignment\Tests\Concurrency\ParallelRun;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (! ParallelRun::isSupported()) {
        $this->markTestSkipped(ParallelRun::UNSUPPORTED_REASON);
    }

    $this->useForkableDatabase();

    Schema::create('test_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
    });
});

afterEach(function (): void {
    $this->discardForkableDatabase();
});

it('serializes first-use round robin selection within one scope', function (): void {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite takes one writer at a time, so eight of them measure busy timeout, not rotation.');
    }

    $assignableIds = collect(range(1, 8))
        ->map(fn (): mixed => AssignableRecord::query()->create()->getKey())
        ->all();
    $assigneeIds = collect(range(1, 4))
        ->map(fn (): mixed => AssigneeRecord::query()->create()->getKey())
        ->all();

    $outcomes = ParallelRun::run(8, function (int $child) use ($assignableIds, $assigneeIds): string {
        $assignment = AssignmentFacade::for(AssignableRecord::query()->findOrFail($assignableIds[$child]))
            ->among(AssigneeRecord::query()->whereKey($assigneeIds)->orderBy('id')->get())
            ->scope('shared-round-robin')
            ->using(RoundRobin::class)
            ->assign();

        return (string) $assignment?->assignee_id;
    });

    $counts = array_count_values($outcomes);
    ksort($counts);
    $ordered = Assignment::query()->orderBy('id')->pluck('assignee_id')->all();

    expect(AssignmentScope::query()->where('scope', 'shared-round-robin')->count())->toBe(1)
        ->and($counts)->toBe([
            (string) $assigneeIds[0] => 2,
            (string) $assigneeIds[1] => 2,
            (string) $assigneeIds[2] => 2,
            (string) $assigneeIds[3] => 2,
        ])->and($ordered)->toBe([
            $assigneeIds[0],
            $assigneeIds[1],
            $assigneeIds[2],
            $assigneeIds[3],
            $assigneeIds[0],
            $assigneeIds[1],
            $assigneeIds[2],
            $assigneeIds[3],
        ]);
});

it('keeps round robin state independent across scopes', function (): void {
    $assignableIds = collect(range(1, 4))
        ->map(fn (): mixed => AssignableRecord::query()->create()->getKey())
        ->all();
    $assigneeIds = collect(range(1, 2))
        ->map(fn (): mixed => AssigneeRecord::query()->create()->getKey())
        ->all();

    ParallelRun::run(4, function (int $child) use ($assignableIds, $assigneeIds): string {
        $scope = $child % 2 === 0 ? 'scope:a' : 'scope:b';
        $assignment = AssignmentFacade::for(AssignableRecord::query()->findOrFail($assignableIds[$child]))
            ->among(AssigneeRecord::query()->whereKey($assigneeIds)->orderBy('id')->get())
            ->scope($scope)
            ->using(RoundRobin::class)
            ->assign();

        return "{$scope}:{$assignment?->assignee_id}";
    });

    $byScope = Assignment::query()
        ->orderBy('id')
        ->get()
        ->groupBy('scope')
        ->map(fn ($assignments) => $assignments->pluck('assignee_id')->all())
        ->all();

    expect(AssignmentScope::query()->count())->toBe(2)
        ->and($byScope['scope:a'])->toBe($assigneeIds)
        ->and($byScope['scope:b'])->toBe($assigneeIds);
});

it('allows different scope policies to overlap on server databases', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'SQLite serializes database writes across scope rows, and InnoDB gap locks reach past the row a scope matches.',
        );
    }

    $assignableIds = collect(range(1, 4))
        ->map(fn (): mixed => AssignableRecord::query()->create()->getKey())
        ->all();
    $assigneeId = AssigneeRecord::query()->create()->getKey();

    $run = function (array $selectedAssignables, array $scopes) use ($assigneeId): Collection {
        $outcomes = ParallelRun::run(2, function (int $child) use ($selectedAssignables, $scopes, $assigneeId): string {
            $enteredAt = 0.0;
            $leftAt = 0.0;
            $assignable = AssignableRecord::query()->findOrFail($selectedAssignables[$child]);
            $assignee = AssigneeRecord::query()->findOrFail($assigneeId);

            AssignmentFacade::for($assignable)
                ->among([$assignee])
                ->scope($scopes[$child])
                ->using(function ($assignable, Collection $candidates) use (&$enteredAt, &$leftAt) {
                    $enteredAt = microtime(true);
                    usleep(1_000_000);
                    $leftAt = microtime(true);

                    return $candidates->first();
                })
                ->assign();

            return "{$enteredAt},{$leftAt}";
        });

        return collect($outcomes)->map(
            fn (string $outcome): array => array_map(floatval(...), explode(',', $outcome)),
        );
    };

    $sameScope = $run(array_slice($assignableIds, 0, 2), ['serialized', 'serialized']);
    $differentScopes = $run(array_slice($assignableIds, 2, 2), ['independent:a', 'independent:b']);
    $sameScopeOverlaps = $sameScope->pluck(0)->max() < $sameScope->pluck(1)->min();
    $differentScopesOverlap = $differentScopes->pluck(0)->max() < $differentScopes->pluck(1)->min();

    expect($sameScopeOverlaps)->toBeFalse()
        ->and($differentScopesOverlap)->toBeTrue();
});

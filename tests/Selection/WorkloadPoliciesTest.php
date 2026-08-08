<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Facades\Assignment as AssignmentFacade;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Policies\FirstAvailable;
use ByRcsc\LaravelAssignment\Policies\LeastWorkload;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('test_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
        $table->unsignedInteger('seniority')->default(0);
    });
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Relation::morphMap([], false);
});

it('selects by the same open workload definition as the assignee reader', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $target = AssignableRecord::query()->create();
    $busy = AssigneeRecord::query()->create();
    $available = AssigneeRecord::query()->create();

    collect([
        [$busy, AssignmentStatus::Active, null, null],
        [$busy, AssignmentStatus::Offered, now()->addMinute(), null],
        [$busy, AssignmentStatus::Offered, now(), null],
        [$busy, AssignmentStatus::Ended, null, EndReason::Completed],
        [$available, AssignmentStatus::Active, null, null],
    ])->each(function (array $row, int $index): void {
        [$assignee, $status, $expiresAt, $endedReason] = $row;

        Assignment::query()->create([
            'assignable_type' => 'workload-item',
            'assignable_id' => 100 + $index,
            'assignee_type' => $assignee->getMorphClass(),
            'assignee_id' => $assignee->getKey(),
            'slot' => $status === AssignmentStatus::Ended ? null : '',
            'status' => $status,
            'expires_at' => $expiresAt,
            'ended_at' => $endedReason === null ? null : now(),
            'ended_reason' => $endedReason,
            'created_at' => now()->subMinutes(10 - $index),
            'updated_at' => now(),
        ]);
    });

    expect($busy->workload())->toBe(2)
        ->and($available->workload())->toBe(1);

    $assignment = AssignmentFacade::for($target)
        ->among([$busy, $available])
        ->using(LeastWorkload::class)
        ->assign();

    expect($assignment?->assignee->is($available))->toBeTrue();
});

it('constrains workload counts to one assignable type', function (): void {
    Relation::morphMap(['lead' => AssignableRecord::class]);

    $constrainedTarget = AssignableRecord::query()->create();
    $unconstrainedTarget = AssignableRecord::query()->create();
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();

    foreach ([
        [$first, 'lead', 101],
        [$first, 'other', 102],
        [$first, 'other', 103],
        [$first, 'other', 104],
        [$second, 'lead', 105],
        [$second, 'lead', 106],
    ] as $index => [$assignee, $type, $assignableId]) {
        Assignment::query()->create([
            'assignable_type' => $type,
            'assignable_id' => $assignableId,
            'assignee_type' => $assignee->getMorphClass(),
            'assignee_id' => $assignee->getKey(),
            'slot' => '',
            'status' => AssignmentStatus::Active,
            'created_at' => now()->subMinutes(10 - $index),
            'updated_at' => now(),
        ]);
    }

    $constrained = AssignmentFacade::for($constrainedTarget)
        ->among([$first, $second])
        ->using(LeastWorkload::of(AssignableRecord::class))
        ->assign();
    $unconstrained = AssignmentFacade::for($unconstrainedTarget)
        ->among([$first, $second])
        ->using(LeastWorkload::class)
        ->assign();

    expect($constrained?->assignee->is($first))->toBeTrue()
        ->and($unconstrained?->assignee->is($second))->toBeTrue();
});

it('breaks workload ties by least recent assignment with never assigned first', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $firstTarget = AssignableRecord::query()->create();
    $secondTarget = AssignableRecord::query()->create();
    $recent = AssigneeRecord::query()->create();
    $old = AssigneeRecord::query()->create();
    $never = AssigneeRecord::query()->create();

    foreach ([[$recent, 1, 1], [$old, 2, 20]] as [$assignee, $assignableId, $minutesAgo]) {
        Assignment::query()->create([
            'assignable_type' => 'historical-item',
            'assignable_id' => $assignableId,
            'assignee_type' => $assignee->getMorphClass(),
            'assignee_id' => $assignee->getKey(),
            'slot' => null,
            'status' => AssignmentStatus::Ended,
            'ended_at' => now()->subMinutes($minutesAgo),
            'ended_reason' => EndReason::Completed,
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now(),
        ]);
    }

    $first = AssignmentFacade::for($firstTarget)
        ->among([$recent, $old, $never])
        ->using(LeastWorkload::class)
        ->assign();
    $second = AssignmentFacade::for($secondTarget)
        ->among([$recent, $old])
        ->using(LeastWorkload::class)
        ->assign();

    expect($first?->assignee->is($never))->toBeTrue()
        ->and($second?->assignee->is($old))->toBeTrue();
});

it('spreads repeated offers instead of piling them onto one idle assignee', function (): void {
    $assignables = collect(range(1, 6))
        ->map(fn (): AssignableRecord => AssignableRecord::query()->create());
    $candidates = collect(range(1, 3))
        ->map(fn (): AssigneeRecord => AssigneeRecord::query()->create());

    foreach ($assignables as $assignable) {
        AssignmentFacade::for($assignable)
            ->among($candidates)
            ->using(LeastWorkload::class)
            ->offer();
    }

    $counts = Assignment::query()
        ->offered()
        ->selectRaw('assignee_id, COUNT(*) AS aggregate')
        ->groupBy('assignee_id')
        ->pluck('aggregate', 'assignee_id')
        ->map(fn (mixed $count): int => (int) $count)
        ->all();

    expect(array_values($counts))->toBe([2, 2, 2]);
});

it('honors candidate order with first available', function (): void {
    $assignable = AssignableRecord::query()->create();
    AssigneeRecord::query()->create(['seniority' => 2]);
    $senior = AssigneeRecord::query()->create(['seniority' => 10]);
    AssigneeRecord::query()->create(['seniority' => 5]);

    $assignment = AssignmentFacade::for($assignable)
        ->among(AssigneeRecord::query()->orderByDesc('seniority')->get())
        ->using(FirstAvailable::class)
        ->assign();

    expect($assignment?->assignee->is($senior))->toBeTrue();
});

it('lets closure policies filter then delegate and return null', function (): void {
    $selectedTarget = AssignableRecord::query()->create();
    $emptyTarget = AssignableRecord::query()->create();
    $junior = AssigneeRecord::query()->create(['seniority' => 2]);
    $senior = AssigneeRecord::query()->create(['seniority' => 10]);
    $firstEligible = fn (Collection $candidates): ?Model => $candidates->first();

    $selected = AssignmentFacade::for($selectedTarget)
        ->among([$junior, $senior])
        ->using(fn (Model $assignable, Collection $candidates): ?Model => $firstEligible(
            $candidates->filter(fn (AssigneeRecord $candidate): bool => $candidate->seniority >= 5),
        ))
        ->assign();
    $empty = AssignmentFacade::for($emptyTarget)
        ->among([$junior])
        ->using(fn (Model $assignable, Collection $candidates): ?Model => $firstEligible(
            $candidates->filter(fn (AssigneeRecord $candidate): bool => $candidate->seniority >= 5),
        ))
        ->assign();

    expect($selected?->assignee->is($senior))->toBeTrue()
        ->and($empty)->toBeNull();
});

it('keeps selection query count constant as the candidate set grows', function (): void {
    $smallTarget = AssignableRecord::query()->create();
    $largeTarget = AssignableRecord::query()->create();
    $smallCandidates = collect(range(1, 2))
        ->map(fn (): AssigneeRecord => AssigneeRecord::query()->create());
    $largeCandidates = collect(range(1, 20))
        ->map(fn (): AssigneeRecord => AssigneeRecord::query()->create());

    DB::enableQueryLog();
    DB::flushQueryLog();
    AssignmentFacade::for($smallTarget)
        ->among($smallCandidates)
        ->scope('query-count:small')
        ->using(LeastWorkload::class)
        ->assign();
    $smallCount = count(DB::getQueryLog());

    DB::flushQueryLog();
    AssignmentFacade::for($largeTarget)
        ->among($largeCandidates)
        ->scope('query-count:large')
        ->using(LeastWorkload::class)
        ->assign();
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($largeCount)->toBe($smallCount);
});

<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Facades\Assignment as AssignmentFacade;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Models\QueuedAssignment;
use ByRcsc\LaravelAssignment\Tests\Concurrency\ParallelRun;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\InjectedAssignmentProfile;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileAssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (! ParallelRun::isSupported()) {
        $this->markTestSkipped(ParallelRun::UNSUPPORTED_REASON);
    }

    if (ParallelRun::needsServerDatabase()) {
        $this->markTestSkipped(ParallelRun::SINGLE_WRITER_REASON);
    }

    $this->useForkableDatabase('DEFERRED');

    Schema::create('test_profile_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
    });

    $assignee = AssigneeRecord::query()->create();
    $settings = new ProfileSettings;
    $settings->candidates = [$assignee];
    app()->instance(ProfileSettings::class, $settings);
    config()->set('assignment.profiles', [
        ProfileAssignableRecord::class => InjectedAssignmentProfile::class,
    ]);
});

afterEach(function (): void {
    $this->discardForkableDatabase();
});

it('assigns every queued entry at most once across parallel flushes', function (): void {
    foreach (range(1, 6) as $priority) {
        $assignable = ProfileAssignableRecord::query()->create();
        AssignmentFacade::for($assignable)->queue(priority: $priority);
    }

    $outcomes = ParallelRun::run(
        2,
        fn (): string => 'assigned:'.AssignmentFacade::flushQueue(),
    );

    expect($outcomes)->each->toStartWith('assigned:')
        ->and(array_sum(array_map(
            fn (string $outcome): int => (int) str($outcome)->after('assigned:')->toString(),
            $outcomes,
        )))->toBe(6)
        ->and(Assignment::query()->count())->toBe(6)
        ->and(Assignment::query()->distinct()->count('assignable_id'))->toBe(6)
        ->and(QueuedAssignment::query()->count())->toBe(0);
});

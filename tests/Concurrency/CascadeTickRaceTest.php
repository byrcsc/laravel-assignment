<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Actions\OfferCascade;
use ByRcsc\LaravelAssignment\Enums\Mode;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Tests\Concurrency\ParallelRun;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\InjectedAssignmentProfile;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileAssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (! ParallelRun::isSupported()) {
        $this->markTestSkipped(ParallelRun::UNSUPPORTED_REASON);
    }

    $this->useForkableDatabase('DEFERRED');
    Schema::create('test_profile_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
    });

    $settings = new ProfileSettings;
    $settings->mode = Mode::Offer;
    $settings->ttl = 1;
    app()->instance(ProfileSettings::class, $settings);
    config()->set('assignment.profiles', [
        ProfileAssignableRecord::class => InjectedAssignmentProfile::class,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    $this->discardForkableDatabase();
});

it('advances an expired cascade once across overlapping ticks', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 10:00:00');
    $assignable = ProfileAssignableRecord::query()->create();
    $firstDriver = AssigneeRecord::query()->create();
    $secondDriver = AssigneeRecord::query()->create();
    app(ProfileSettings::class)->candidates = [$firstDriver, $secondDriver];
    $assignable->autoAssign();
    CarbonImmutable::setTestNow('2026-08-08 10:00:02');

    $outcomes = ParallelRun::run(
        2,
        fn (): string => 'expired:'.app(OfferCascade::class)->expireOverdue(),
    );

    expect($outcomes)->each->toStartWith('expired:')
        ->and(array_sum(array_map(
            fn (string $outcome): int => (int) str($outcome)->after('expired:')->toString(),
            $outcomes,
        )))->toBe(1)
        ->and(Assignment::query()->count())->toBe(2)
        ->and($assignable->assignment()?->assignee_id)->toBe($secondDriver->getKey());
});

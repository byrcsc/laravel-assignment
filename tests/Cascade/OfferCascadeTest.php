<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Actions\OfferCascade;
use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Enums\Mode;
use ByRcsc\LaravelAssignment\Events\OfferExpired;
use ByRcsc\LaravelAssignment\Events\OffersExhausted;
use ByRcsc\LaravelAssignment\Facades\Assignment as AssignmentFacade;
use ByRcsc\LaravelAssignment\Jobs\AdvanceExpiredOffer;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Support\ModelIdentity;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\InjectedAssignmentProfile;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileAssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('test_profile_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
    });

    $settings = new ProfileSettings;
    $settings->mode = Mode::Offer;
    $settings->ttl = 60;
    app()->instance(ProfileSettings::class, $settings);
    config()->set('assignment.profiles', [
        ProfileAssignableRecord::class => InjectedAssignmentProfile::class,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('cascades from decline through tick expiry to acceptance', function (): void {
    Event::fake([OfferExpired::class]);
    CarbonImmutable::setTestNow('2026-08-08 10:00:00');
    $assignable = ProfileAssignableRecord::query()->create();
    $firstDriver = AssigneeRecord::query()->create();
    $secondDriver = AssigneeRecord::query()->create();
    $thirdDriver = AssigneeRecord::query()->create();
    app(ProfileSettings::class)->candidates = [$firstDriver, $secondDriver, $thirdDriver];

    $first = $assignable->autoAssign('driver');
    $firstDriver->decline($first);
    $second = $assignable->assignment('driver');

    CarbonImmutable::setTestNow('2026-08-08 10:01:01');
    $this->artisan('assignment:tick')->assertSuccessful();
    $third = $assignable->assignment('driver');
    $thirdDriver->accept($third);
    $history = $assignable->assignments()->get();

    expect($history)->toHaveCount(3)
        ->and($history->pluck('cascade_id')->unique()->all())->toBe([$first?->cascade_id])
        ->and($history[0]->ended_reason)->toBe(EndReason::Declined)
        ->and($history[1]->ended_reason)->toBe(EndReason::Expired)
        ->and($history[2]->status)->toBe(AssignmentStatus::Active)
        ->and($history[2]->assignee_id)->toBe($thirdDriver->getKey());

    Event::assertDispatchedTimes(OfferExpired::class, 1);
});

it('queues exhaustion and starts a fresh cascade on flush', function (): void {
    Event::fake([OffersExhausted::class]);
    $assignable = ProfileAssignableRecord::query()->create();
    $firstDriver = AssigneeRecord::query()->create();
    $secondDriver = AssigneeRecord::query()->create();
    app(ProfileSettings::class)->candidates = [$firstDriver, $secondDriver];

    $first = $assignable->autoAssign();
    $firstDriver->decline($first);
    $second = $assignable->assignment();
    $secondDriver->decline($second);

    expect($assignable->queuedAssignment())->not->toBeNull();
    Event::assertDispatchedTimes(OffersExhausted::class, 1);

    expect(AssignmentFacade::flushQueue())->toBe(1);

    $restarted = $assignable->assignment();

    expect($restarted?->assignee_id)->toBe($firstDriver->getKey())
        ->and($restarted?->cascade_id)->not->toBe($first?->cascade_id)
        ->and($assignable->queuedAssignment())->toBeNull();
});

it('keeps ad hoc offers outside cascades', function (): void {
    $assignable = ProfileAssignableRecord::query()->create();
    $firstDriver = AssigneeRecord::query()->create();
    $secondDriver = AssigneeRecord::query()->create();
    app(ProfileSettings::class)->candidates = [$firstDriver, $secondDriver];

    $offer = AssignmentFacade::for($assignable)->among([$firstDriver])->offer();
    $firstDriver->decline($offer);

    expect($offer?->cascade_id)->toBeNull()
        ->and($assignable->assignment())->toBeNull()
        ->and(Assignment::query()->count())->toBe(1);
});

it('dispatches delayed expiry jobs and makes answered jobs a no-op', function (): void {
    Bus::fake();
    CarbonImmutable::setTestNow('2026-08-08 10:00:00');
    config()->set('assignment.dispatch_expiry_jobs', true);
    $assignable = ProfileAssignableRecord::query()->create();
    $driver = AssigneeRecord::query()->create();
    app(ProfileSettings::class)->candidates = [$driver];

    $offer = $assignable->autoAssign();

    Bus::assertDispatched(AdvanceExpiredOffer::class, function (AdvanceExpiredOffer $job) use ($offer): bool {
        if ($offer === null) {
            return false;
        }

        if ($job->assignmentId !== ModelIdentity::key($offer)) {
            return false;
        }

        return $job->delay?->equalTo($offer->expires_at) === true;
    });

    $driver->accept($offer);
    CarbonImmutable::setTestNow('2026-08-08 10:01:01');
    (new AdvanceExpiredOffer(ModelIdentity::key($offer)))->handle(app(OfferCascade::class));

    expect($offer->fresh()?->status)->toBe(AssignmentStatus::Active)
        ->and(Assignment::query()->count())->toBe(1);
});

it('lets reassignment and unassignment kill an open cascade', function (): void {
    $first = ProfileAssignableRecord::query()->create();
    $second = ProfileAssignableRecord::query()->create();
    $offeredDriver = AssigneeRecord::query()->create();
    $replacement = AssigneeRecord::query()->create();
    app(ProfileSettings::class)->candidates = [$offeredDriver];
    $reassignedOffer = $first->autoAssign();
    $unassignedOffer = $second->autoAssign();

    $first->reassign($replacement);
    $second->unassign();

    CarbonImmutable::setTestNow(now()->addMinutes(2));
    app(OfferCascade::class)->expireOverdue();

    expect($first->assignment()?->assignee_id)->toBe($replacement->getKey())
        ->and($reassignedOffer?->fresh()?->ended_reason)->toBe(EndReason::Reassigned)
        ->and($second->assignment())->toBeNull()
        ->and($unassignedOffer?->fresh()?->ended_reason)->toBe(EndReason::Unassigned)
        ->and(Assignment::query()->count())->toBe(3);
});

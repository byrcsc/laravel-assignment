<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Events\AssignableQueued;
use ByRcsc\LaravelAssignment\Exceptions\AlreadyAssigned;
use ByRcsc\LaravelAssignment\Facades\Assignment as AssignmentFacade;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Models\QueuedAssignment;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ChildProfileAssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\InjectedAssignmentProfile;
use ByRcsc\LaravelAssignment\Tests\Fixtures\OfferAssignmentProfile;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileAssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
    app()->instance(ProfileSettings::class, $settings);
    config()->set('assignment.profiles', [
        ProfileAssignableRecord::class => InjectedAssignmentProfile::class,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Relation::morphMap([], false);
});

it('queues an empty auto assignment through its profile and dispatches once', function (): void {
    Event::fake([AssignableQueued::class]);
    CarbonImmutable::setTestNow('2026-08-08 10:00:00');

    $assignable = ProfileAssignableRecord::query()->create();
    $settings = app(ProfileSettings::class);
    $settings->queuePriority = 8;

    expect($assignable->autoAssign('seller'))->toBeNull();

    $entry = $assignable->queuedAssignment('seller');

    expect($entry)->not->toBeNull()
        ->and($entry?->priority)->toBe(8)
        ->and($entry?->role)->toBe('seller')
        ->and($entry?->queued_at->equalTo(now()))->toBeTrue();

    $same = AssignmentFacade::for($assignable)->role('seller')->queue(priority: 99);

    expect($same->is($entry))->toBeTrue()
        ->and($same->priority)->toBe(8)
        ->and(QueuedAssignment::query()->count())->toBe(1);

    Event::assertDispatchedTimes(AssignableQueued::class, 1);

    app(ProfileSettings::class)->candidates = [AssigneeRecord::query()->create()];

    expect(AssignmentFacade::flushQueue())->toBe(1)
        ->and($assignable->assignment('seller'))->not->toBeNull()
        ->and($assignable->queuedAssignment('seller'))->toBeNull();
});

it('dispatches the queued event only after the surrounding transaction commits', function (): void {
    Event::fake([AssignableQueued::class]);
    $assignable = ProfileAssignableRecord::query()->create();

    DB::beginTransaction();
    AssignmentFacade::for($assignable)->queue();
    Event::assertNotDispatched(AssignableQueued::class);
    DB::commit();

    Event::assertDispatchedTimes(AssignableQueued::class, 1);
});

it('does not queue an empty profile when its fallback is disabled', function (): void {
    $assignable = ProfileAssignableRecord::query()->create();
    app(ProfileSettings::class)->queueWhenEmpty = false;

    expect($assignable->autoAssign())->toBeNull()
        ->and($assignable->queuedAssignment())->toBeNull();
});

it('queues when a policy declines to select an available candidate', function (): void {
    $assignable = ProfileAssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $settings = app(ProfileSettings::class);
    $settings->candidates = [$assignee];
    $settings->policy = fn (Model $assignable, mixed $candidates): null => null;

    expect($assignable->autoAssign())->toBeNull()
        ->and($assignable->queuedAssignment())->not->toBeNull();
});

it('rejects queueing an occupied assignment slot', function (): void {
    $assignable = ProfileAssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $assignable->assign($assignee, role: 'seller');

    expect(
        fn () => AssignmentFacade::for($assignable)->role('seller')->queue(),
    )->toThrow(AlreadyAssigned::class);
});

it('clears a queued slot when a direct assignment wins before flush', function (): void {
    $stale = ProfileAssignableRecord::query()->create();
    $later = ProfileAssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    AssignmentFacade::for($stale)->queue(priority: 10);
    AssignmentFacade::for($later)->queue(priority: 1);

    $stale->assign($assignee);
    app(ProfileSettings::class)->candidates = [$assignee];

    expect($stale->queuedAssignment())->toBeNull()
        ->and(AssignmentFacade::flushQueue())->toBe(1)
        ->and($later->assignment())->not->toBeNull();
});

it('flushes by priority then fifo and removes successful entries', function (): void {
    $assignee = AssigneeRecord::query()->create();
    app(ProfileSettings::class)->candidates = [$assignee];
    $first = ProfileAssignableRecord::query()->create();
    $second = ProfileAssignableRecord::query()->create();
    $third = ProfileAssignableRecord::query()->create();

    CarbonImmutable::setTestNow('2026-08-08 10:00:00');
    AssignmentFacade::for($first)->queue(priority: 1);
    CarbonImmutable::setTestNow('2026-08-08 10:01:00');
    AssignmentFacade::for($second)->queue(priority: 10);
    CarbonImmutable::setTestNow('2026-08-08 10:02:00');
    AssignmentFacade::for($third)->queue(priority: 10);

    expect(AssignmentFacade::flushQueue())->toBe(3)
        ->and(QueuedAssignment::query()->count())->toBe(0)
        ->and(Assignment::query()->orderBy('id')->pluck('assignable_id')->all())
        ->toBe([$second->getKey(), $third->getKey(), $first->getKey()]);
});

it('keeps candidate-less entries without retrying them in the same flush', function (): void {
    $assignable = ProfileAssignableRecord::query()->create();
    AssignmentFacade::for($assignable)->queue(priority: 4);

    expect(AssignmentFacade::flushQueue())->toBe(0)
        ->and($assignable->queuedAssignment()?->priority)->toBe(4)
        ->and(QueuedAssignment::query()->count())->toBe(1);
});

it('flushes assign and offer profiles through their configured modes', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $assignee = AssigneeRecord::query()->create();
    $settings = app(ProfileSettings::class);
    $settings->candidates = [$assignee];
    $settings->ttl = 90;
    config()->set('assignment.profiles', [
        ProfileAssignableRecord::class => InjectedAssignmentProfile::class,
        ChildProfileAssignableRecord::class => OfferAssignmentProfile::class,
    ]);
    $direct = ProfileAssignableRecord::query()->create();
    $offered = ChildProfileAssignableRecord::query()->create();
    AssignmentFacade::for($direct)->queue();
    AssignmentFacade::for($offered)->role('driver')->queue();

    expect(AssignmentFacade::flushQueue())->toBe(2)
        ->and($direct->assignment()?->status)->toBe(AssignmentStatus::Active)
        ->and($offered->assignment('driver')?->status)->toBe(AssignmentStatus::Offered)
        ->and($offered->assignment('driver')?->expires_at?->equalTo(now()->addSeconds(90)))->toBeTrue();
});

it('filters flushes by concrete or morph-mapped assignable type', function (): void {
    Relation::morphMap(['profile-record' => ProfileAssignableRecord::class]);

    $assignee = AssigneeRecord::query()->create();
    app(ProfileSettings::class)->candidates = [$assignee];
    $assignable = ProfileAssignableRecord::query()->create();
    AssignmentFacade::for($assignable)->queue();

    expect(AssignmentFacade::flushQueue(ProfileAssignableRecord::class))->toBe(1);
});

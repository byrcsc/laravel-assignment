<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\AssignmentProfile;
use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Enums\Mode;
use ByRcsc\LaravelAssignment\Exceptions\NoProfile;
use ByRcsc\LaravelAssignment\Facades\Assignment as AssignmentFacade;
use ByRcsc\LaravelAssignment\Policies\FirstAvailable;
use ByRcsc\LaravelAssignment\Support\AssignmentProfileResolver;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ChildProfileAssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\InjectedAssignmentProfile;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileAssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('test_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_profile_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
    });
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('matches an equivalent fluent assignment from config alone', function (): void {
    $profiledAssignable = ProfileAssignableRecord::query()->create();
    $fluentAssignable = ProfileAssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $settings = new ProfileSettings;
    $settings->candidates = [$assignee];
    $settings->scope = 'profile:equivalent';
    app()->instance(ProfileSettings::class, $settings);
    config()->set('assignment.profiles', [
        ProfileAssignableRecord::class => InjectedAssignmentProfile::class,
    ]);

    $profiled = $profiledAssignable->autoAssign();
    $fluent = AssignmentFacade::for($fluentAssignable)
        ->among([$assignee])
        ->scope('profile:equivalent')
        ->using(FirstAvailable::class)
        ->assign();
    $comparable = [
        'assignee_type',
        'assignee_id',
        'assigned_by_type',
        'assigned_by_id',
        'role',
        'slot',
        'status',
        'scope',
    ];

    expect(Arr::only($profiled?->getAttributes() ?? [], $comparable))
        ->toBe(Arr::only($fluent?->getAttributes() ?? [], $comparable));
});

it('resolves a parent profile through the container and offers with role and ttl', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $assignable = ChildProfileAssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $settings = new ProfileSettings;
    $settings->candidates = [$assignee];
    $settings->scope = 'profile:drivers';
    $settings->mode = Mode::Offer;
    $settings->ttl = 120;
    $settings->queuePriority = 10;
    app()->instance(ProfileSettings::class, $settings);
    config()->set('assignment.profiles', [
        ProfileAssignableRecord::class => InjectedAssignmentProfile::class,
    ]);

    $offer = $assignable->autoAssign('driver');
    $profile = app(AssignmentProfileResolver::class)->resolve($assignable);
    $queueWhenEmpty = $profile->queueWhenEmpty($assignable, 'driver');
    $queuePriority = $profile->queuePriority($assignable, 'driver');

    expect($offer?->isOffered())->toBeTrue()
        ->and($offer?->role)->toBe('driver')
        ->and($offer?->scope)->toBe('profile:drivers')
        ->and($offer?->expires_at?->equalTo(now()->addSeconds(120)))->toBeTrue()
        ->and($queueWhenEmpty)->toBeTrue()
        ->and($queuePriority)->toBe(10)
        ->and($settings->calls)->toBe([
            ['method' => 'candidates', 'role' => 'driver'],
            ['method' => 'scope', 'role' => 'driver'],
            ['method' => 'policy', 'role' => 'driver'],
            ['method' => 'mode', 'role' => 'driver'],
            ['method' => 'offerTtl', 'role' => 'driver'],
            ['method' => 'queueWhenEmpty', 'role' => 'driver'],
            ['method' => 'queuePriority', 'role' => 'driver'],
        ]);
});

it('returns null when the resolved profile has no candidates', function (): void {
    $assignable = ProfileAssignableRecord::query()->create();
    $settings = new ProfileSettings;
    app()->instance(ProfileSettings::class, $settings);
    config()->set('assignment.profiles', [
        ProfileAssignableRecord::class => InjectedAssignmentProfile::class,
    ]);

    expect($assignable->autoAssign())->toBeNull();
});

it('ships the optional profile defaults', function (): void {
    $assignable = AssignableRecord::query()->create();
    $profile = new class extends AssignmentProfile
    {
        public function candidates(Model $assignable, ?string $role): iterable
        {
            return [];
        }

        public function policy(Model $assignable, ?string $role): string|SelectionPolicy|Closure
        {
            return FirstAvailable::class;
        }
    };

    expect($profile->scope($assignable, null))->toBeNull()
        ->and($profile->mode($assignable, null))->toBe(Mode::Assign)
        ->and($profile->offerTtl($assignable, null))->toBeNull()
        ->and($profile->queueWhenEmpty($assignable, null))->toBeTrue()
        ->and($profile->queuePriority($assignable, null))->toBe(0);
});

it('throws a typed exception when no profile is registered', function (): void {
    config()->set('assignment.profiles', []);

    $assignable = AssignableRecord::query()->create();

    expect(fn () => $assignable->autoAssign())->toThrow(NoProfile::class);
});

<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Facades\Assignment as AssignmentFacade;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Models\AssignmentScope;
use ByRcsc\LaravelAssignment\Policies\Random;
use ByRcsc\LaravelAssignment\ScopeState;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ActorRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('test_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_actors', function (Blueprint $table): void {
        $table->string('id')->primary();
    });
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Relation::morphMap([], false);
});

it('derives the default scope from the class when a morph map is active', function (): void {
    Relation::morphMap(['assignable-record' => AssignableRecord::class]);

    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $assignment = AssignmentFacade::for($assignable)
        ->among([$assignee])
        ->assign();

    expect($assignment?->assignable_type)->toBe('assignable-record')
        ->and($assignment?->scope)->toBe(AssignableRecord::class.':')
        ->and(AssignmentScope::query()->where('scope', AssignableRecord::class.':')->exists())->toBeTrue();
});

it('assigns through the fluent facade and records its resolved scope', function (): void {
    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $actor = ActorRecord::query()->create(['id' => 'router']);

    $assignment = AssignmentFacade::for($assignable)
        ->among([$assignee])
        ->role('driver')
        ->by($actor)
        ->assign();

    expect($assignment)->toBeInstanceOf(Assignment::class)
        ->and($assignment?->isActive())->toBeTrue()
        ->and($assignment?->assignee->is($assignee))->toBeTrue()
        ->and($assignment?->assignedBy->is($actor))->toBeTrue()
        ->and($assignment?->scope)->toBe(AssignableRecord::class.':driver')
        ->and(AssignmentScope::query()->where('scope', AssignableRecord::class.':driver')->exists())->toBeTrue();
});

it('offers with a ttl through a lazy candidate source and an explicit scope', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $resolvedFor = null;

    $offer = AssignmentFacade::for($assignable)
        ->among(function (Model $candidateFor) use ($assignee, &$resolvedFor): iterable {
            $resolvedFor = $candidateFor;

            return [$assignee];
        })
        ->scope('territory:brisbane')
        ->using(Random::class)
        ->expiresIn(90)
        ->offer();

    expect($resolvedFor)->toBe($assignable)
        ->and($offer?->isOffered())->toBeTrue()
        ->and($offer?->scope)->toBe('territory:brisbane')
        ->and($offer?->expires_at?->equalTo(now()->addSeconds(90)))->toBeTrue();
});

it('supports two-argument closure policies and policy instances', function (): void {
    $firstAssignable = AssignableRecord::query()->create();
    $secondAssignable = AssignableRecord::query()->create();
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();
    $candidates = collect([$first, $second]);

    $closureAssignment = AssignmentFacade::for($firstAssignable)
        ->among($candidates)
        ->using(function (Model $assignable, Collection $available) use ($firstAssignable): ?Model {
            expect($assignable->is($firstAssignable))->toBeTrue();

            return $available->last();
        })
        ->assign();

    $policy = new class implements SelectionPolicy
    {
        public function select(Model $assignable, Collection $candidates, ScopeState $state): ?Model
        {
            return $candidates->first();
        }
    };
    $instanceAssignment = AssignmentFacade::for($secondAssignable)
        ->among($candidates)
        ->using($policy)
        ->assign();

    expect($closureAssignment?->assignee->is($second))->toBeTrue()
        ->and($instanceAssignment?->assignee->is($first))->toBeTrue();
});

it('returns null for empty candidates or a null policy selection', function (): void {
    $emptyAssignable = AssignableRecord::query()->create();
    $nullAssignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();

    $empty = AssignmentFacade::for($emptyAssignable)
        ->among([])
        ->assign();
    $unselected = AssignmentFacade::for($nullAssignable)
        ->among([$assignee])
        ->using(fn (Model $assignable, Collection $candidates): null => null)
        ->assign();

    expect($empty)->toBeNull()
        ->and($unselected)->toBeNull()
        ->and(Assignment::query()->count())->toBe(0)
        ->and(AssignmentScope::query()->count())->toBe(1);
});

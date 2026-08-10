<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Events\Assigned;
use ByRcsc\LaravelAssignment\Events\Unassigned;
use ByRcsc\LaravelAssignment\Exceptions\InvalidTransition;
use ByRcsc\LaravelAssignment\Exceptions\NotAssigned;
use ByRcsc\LaravelAssignment\Exceptions\SlotOccupied;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ActorRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
});

it('assigns an assignee to a role and records the actor', function (): void {
    Event::fake([Assigned::class]);

    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $actor = ActorRecord::query()->create(['id' => 'dispatcher']);

    $assignment = $assignable->assign($assignee, 'driver', $actor);

    expect($assignment->isActive())->toBeTrue()
        ->and($assignment->role)->toBe('driver')
        ->and($assignment->getRawOriginal('slot'))->toBe('driver')
        ->and($assignment->assignable->is($assignable))->toBeTrue()
        ->and($assignment->assignee->is($assignee))->toBeTrue()
        ->and($assignment->assignedBy->is($actor))->toBeTrue();

    Event::assertDispatchedTimes(Assigned::class, 1);
});

it('rejects an occupied slot and isolates roles', function (): void {
    $assignable = AssignableRecord::query()->create();
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();

    $assignable->assign($first, 'driver');
    $assignable->assign($second, 'seller');

    expect(fn () => $assignable->assign($second, 'driver'))
        ->toThrow(SlotOccupied::class)
        ->and($assignable->openAssignment('driver')?->assignee->is($first))->toBeTrue()
        ->and($assignable->openAssignment('seller')?->assignee->is($second))->toBeTrue();
});

it('does not report unrelated integrity failures as occupied slots', function (): void {
    $assignable = AssignableRecord::query()->create();

    expect(fn () => $assignable->assign(new AssigneeRecord))
        ->toThrow(QueryException::class);
});

it('ends an expired offer before claiming its slot', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $assignable = AssignableRecord::query()->create();
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();

    $offer = Assignment::query()->create([
        'assignable_type' => $assignable->getMorphClass(),
        'assignable_id' => $assignable->getKey(),
        'assignee_type' => $first->getMorphClass(),
        'assignee_id' => $first->getKey(),
        'slot' => '',
        'status' => AssignmentStatus::Offered,
        'offered_at' => now()->subMinute(),
        'expires_at' => now(),
    ]);

    $assignment = $assignable->assign($second);

    expect($offer->refresh()->isEnded())->toBeTrue()
        ->and($offer->ended_reason)->toBe(EndReason::Expired)
        ->and($offer->getRawOriginal('slot'))->toBeNull()
        ->and($assignment->isActive())->toBeTrue()
        ->and($assignable->assignments()->count())->toBe(2);
});

it('unassigns the active row and retains history for a later claim', function (): void {
    Event::fake([Assigned::class, Unassigned::class]);

    $assignable = AssignableRecord::query()->create();
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();

    $firstAssignment = $assignable->assign($first);
    $ended = $assignable->unassign();
    $secondAssignment = $assignable->assign($second);

    expect($ended->is($firstAssignment))->toBeTrue()
        ->and($ended->isEnded())->toBeTrue()
        ->and($ended->ended_reason)->toBe(EndReason::Unassigned)
        ->and($ended->ended_at)->not->toBeNull()
        ->and($ended->getRawOriginal('slot'))->toBeNull()
        ->and($secondAssignment->isActive())->toBeTrue()
        ->and($assignable->assignments()->count())->toBe(2);

    Event::assertDispatchedTimes(Assigned::class, 2);
    Event::assertDispatchedTimes(Unassigned::class, 1);
});

it('throws when unassigning a slot without an active assignment', function (): void {
    $assignable = AssignableRecord::query()->create();

    expect(fn () => $assignable->unassign())->toThrow(NotAssigned::class);
});

it('completes only active assignments', function (): void {
    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();

    $assignment = $assignable->assign($assignee);
    $completed = $assignment->complete();

    expect($completed)->toBe($assignment)
        ->and($assignment->isEnded())->toBeTrue()
        ->and($completed->ended_reason)->toBe(EndReason::Completed)
        ->and($completed->ended_at)->not->toBeNull()
        ->and($completed->getRawOriginal('slot'))->toBeNull()
        ->and(fn () => $completed->complete())->toThrow(InvalidTransition::class);
});

it('dispatches lifecycle events after the surrounding transaction commits', function (): void {
    Event::fake([Assigned::class, Unassigned::class]);

    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();

    DB::beginTransaction();
    $assignable->assign($assignee);
    Event::assertNotDispatched(Assigned::class);
    DB::commit();
    Event::assertDispatchedTimes(Assigned::class, 1);

    DB::beginTransaction();
    $assignable->unassign();
    Event::assertNotDispatched(Unassigned::class);
    DB::commit();
    Event::assertDispatchedTimes(Unassigned::class, 1);
});

it('does not dispatch assignment events for rolled back work', function (): void {
    Event::fake([Assigned::class]);

    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();

    DB::beginTransaction();
    $assignable->assign($assignee);
    DB::rollBack();

    Event::assertNotDispatched(Assigned::class);
    expect(Assignment::query()->count())->toBe(0);
});

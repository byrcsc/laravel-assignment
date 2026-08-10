<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Events\Assigned;
use ByRcsc\LaravelAssignment\Events\Reassigned;
use ByRcsc\LaravelAssignment\Events\Unassigned;
use ByRcsc\LaravelAssignment\Exceptions\AlreadyAssigned;
use ByRcsc\LaravelAssignment\Exceptions\NotAssigned;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ActorRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
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

it('reassigns an active assignment and preserves its history', function (): void {
    $events = [];
    Event::listen(Unassigned::class, function () use (&$events): void {
        $events[] = 'unassigned';
    });
    Event::listen(Assigned::class, function () use (&$events): void {
        $events[] = 'assigned';
    });
    Event::listen(Reassigned::class, function (Reassigned $event) use (&$events): void {
        $events[] = 'reassigned';
        $events[] = $event->oldAssignment->isEnded() ? 'old-ended' : 'old-open';
        $events[] = $event->newAssignment->isActive() ? 'new-active' : 'new-closed';
    });

    $assignable = AssignableRecord::query()->create();
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();
    $actor = ActorRecord::query()->create(['id' => 'dispatcher']);
    $old = $assignable->assign($first, 'driver');
    $events = [];

    $replacement = $assignable->reassign($second, 'driver', $actor);
    $history = $assignable->assignments()->get();

    expect($old->refresh()->isEnded())->toBeTrue()
        ->and($old->ended_reason)->toBe(EndReason::Reassigned)
        ->and($old->getRawOriginal('slot'))->toBeNull()
        ->and($replacement->isActive())->toBeTrue()
        ->and($replacement->assignee->is($second))->toBeTrue()
        ->and($replacement->assignedBy->is($actor))->toBeTrue()
        ->and($replacement->role)->toBe('driver')
        ->and($history)->toHaveCount(2)
        ->and($history->first()?->is($old))->toBeTrue()
        ->and($history->last()?->is($replacement))->toBeTrue()
        ->and($events)->toBe([
            'unassigned',
            'assigned',
            'reassigned',
            'old-ended',
            'new-active',
        ]);
});

it('reassigns an open offer without requiring an answer', function (): void {
    $assignable = AssignableRecord::query()->create();
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();
    $offer = $assignable->offer($first);

    $replacement = $assignable->reassign($second);

    expect($offer->refresh()->isEnded())->toBeTrue()
        ->and($offer->ended_reason)->toBe(EndReason::Reassigned)
        ->and($replacement->isActive())->toBeTrue()
        ->and($replacement->assignee->is($second))->toBeTrue()
        ->and($assignable->assignments()->count())->toBe(2);
});

it('rejects missing and same-assignee replacements', function (): void {
    $empty = AssignableRecord::query()->create();
    $assigned = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $assignment = $assigned->assign($assignee);

    expect(fn () => $empty->reassign($assignee))->toThrow(NotAssigned::class)
        ->and(fn () => $assigned->reassign($assignee))->toThrow(AlreadyAssigned::class)
        ->and($assignment->refresh()->isActive())->toBeTrue()
        ->and(Assignment::query()->count())->toBe(1);
});

it('rolls back the old transition when the replacement cannot be stored', function (): void {
    $assignable = AssignableRecord::query()->create();
    $currentAssignee = AssigneeRecord::query()->create();
    $current = $assignable->assign($currentAssignee);

    expect(fn () => $assignable->reassign(new AssigneeRecord))->toThrow(QueryException::class)
        ->and($current->refresh()->isActive())->toBeTrue()
        ->and($current->getRawOriginal('slot'))->toBe('')
        ->and(Assignment::query()->count())->toBe(1);
});

it('defers all reassignment events until the surrounding transaction commits', function (): void {
    $assignable = AssignableRecord::query()->create();
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();
    $assignable->assign($first);
    Event::fake([Unassigned::class, Assigned::class, Reassigned::class]);

    DB::beginTransaction();
    $assignable->reassign($second);
    Event::assertNotDispatched(Unassigned::class);
    Event::assertNotDispatched(Assigned::class);
    Event::assertNotDispatched(Reassigned::class);
    DB::commit();

    Event::assertDispatchedTimes(Unassigned::class, 1);
    Event::assertDispatchedTimes(Assigned::class, 1);
    Event::assertDispatchedTimes(Reassigned::class, 1);
});

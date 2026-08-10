<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ActorRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
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

it('casts lifecycle values and resolves every polymorphic side', function (): void {
    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $actor = ActorRecord::query()->create(['id' => 'system-admin']);

    $assignment = Assignment::query()->create([
        'assignable_type' => $assignable->getMorphClass(),
        'assignable_id' => $assignable->getKey(),
        'assignee_type' => $assignee->getMorphClass(),
        'assignee_id' => $assignee->getKey(),
        'assigned_by_type' => $actor->getMorphClass(),
        'assigned_by_id' => $actor->getKey(),
        'slot' => '',
        'status' => AssignmentStatus::Active,
    ]);

    expect($assignment->status)->toBe(AssignmentStatus::Active)
        ->and($assignment->assignable->is($assignable))->toBeTrue()
        ->and($assignment->assignee->is($assignee))->toBeTrue()
        ->and($assignment->assignedBy->is($actor))->toBeTrue()
        ->and($assignment->toArray())->not->toHaveKey('slot');
});

it('reads lifecycle state at the expiry boundary without a command', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $futureOffer = Assignment::query()->create([
        'assignable_type' => 'lead',
        'assignable_id' => 1,
        'assignee_type' => 'seller',
        'assignee_id' => 1,
        'slot' => '',
        'status' => AssignmentStatus::Offered,
        'expires_at' => now()->addSecond(),
    ]);
    $boundaryOffer = Assignment::query()->create([
        'assignable_type' => 'lead',
        'assignable_id' => 2,
        'assignee_type' => 'seller',
        'assignee_id' => 2,
        'slot' => '',
        'status' => AssignmentStatus::Offered,
        'expires_at' => now(),
    ]);
    $standingOffer = Assignment::query()->create([
        'assignable_type' => 'lead',
        'assignable_id' => 3,
        'assignee_type' => 'seller',
        'assignee_id' => 3,
        'slot' => '',
        'status' => AssignmentStatus::Offered,
    ]);
    $active = Assignment::query()->create([
        'assignable_type' => 'lead',
        'assignable_id' => 4,
        'assignee_type' => 'seller',
        'assignee_id' => 4,
        'slot' => '',
        'status' => AssignmentStatus::Active,
    ]);
    $ended = Assignment::query()->create([
        'assignable_type' => 'lead',
        'assignable_id' => 5,
        'assignee_type' => 'seller',
        'assignee_id' => 5,
        'status' => AssignmentStatus::Ended,
        'ended_reason' => EndReason::Completed,
        'ended_at' => now(),
    ]);

    expect($futureOffer->isOpen())->toBeTrue()
        ->and($futureOffer->isOffered())->toBeTrue()
        ->and($futureOffer->isExpired())->toBeFalse()
        ->and($boundaryOffer->isOpen())->toBeFalse()
        ->and($boundaryOffer->isExpired())->toBeTrue()
        ->and($standingOffer->isOpen())->toBeTrue()
        ->and($active->isActive())->toBeTrue()
        ->and($active->isOpen())->toBeTrue()
        ->and($ended->isEnded())->toBeTrue()
        ->and($ended->ended_reason)->toBe(EndReason::Completed);
});

it('scopes lifecycle states with timestamp-authoritative expiry', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    collect([
        ['status' => AssignmentStatus::Active, 'slot' => 'active'],
        ['status' => AssignmentStatus::Offered, 'slot' => 'standing'],
        ['status' => AssignmentStatus::Offered, 'slot' => 'future', 'expires_at' => now()->addSecond()],
        ['status' => AssignmentStatus::Offered, 'slot' => 'expired', 'expires_at' => now()],
        ['status' => AssignmentStatus::Ended, 'slot' => null, 'ended_at' => now()],
    ])->each(fn (array $state, int $index): Assignment => Assignment::query()->create($state + [
        'assignable_type' => 'lead',
        'assignable_id' => $index + 1,
        'assignee_type' => 'seller',
        'assignee_id' => $index + 1,
    ]));

    expect(Assignment::query()->open()->count())->toBe(3)
        ->and(Assignment::query()->active()->count())->toBe(1)
        ->and(Assignment::query()->offered()->count())->toBe(3)
        ->and(Assignment::query()->ended()->count())->toBe(1)
        ->and(Assignment::query()->expired()->count())->toBe(1);
});

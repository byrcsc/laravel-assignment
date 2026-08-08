<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\UlidAssigneeRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\UuidAssignableRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Schema::create('test_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_uuid_assignables', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
    Schema::create('test_ulid_assignees', function (Blueprint $table): void {
        $table->ulid('id')->primary();
    });
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Relation::morphMap([], false);
});

it('exposes oldest-first history and isolates the default and named slots', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $assignable = AssignableRecord::query()->create();
    $defaultAssignee = AssigneeRecord::query()->create();
    $driver = AssigneeRecord::query()->create();
    $seller = AssigneeRecord::query()->create();

    $driverAssignment = Assignment::query()->create([
        'assignable_type' => $assignable->getMorphClass(),
        'assignable_id' => $assignable->getKey(),
        'assignee_type' => $driver->getMorphClass(),
        'assignee_id' => $driver->getKey(),
        'role' => 'driver',
        'slot' => 'driver',
        'status' => AssignmentStatus::Active,
        'created_at' => now()->subMinute(),
    ]);
    $defaultAssignment = Assignment::query()->create([
        'assignable_type' => $assignable->getMorphClass(),
        'assignable_id' => $assignable->getKey(),
        'assignee_type' => $defaultAssignee->getMorphClass(),
        'assignee_id' => $defaultAssignee->getKey(),
        'slot' => '',
        'status' => AssignmentStatus::Active,
        'created_at' => now(),
    ]);
    $expiredSellerOffer = Assignment::query()->create([
        'assignable_type' => $assignable->getMorphClass(),
        'assignable_id' => $assignable->getKey(),
        'assignee_type' => $seller->getMorphClass(),
        'assignee_id' => $seller->getKey(),
        'role' => 'seller',
        'slot' => 'seller',
        'status' => AssignmentStatus::Offered,
        'expires_at' => now(),
        'created_at' => now()->addMinute(),
    ]);

    expect($assignable->assignments->modelKeys())->toBe([
        $driverAssignment->getKey(),
        $defaultAssignment->getKey(),
        $expiredSellerOffer->getKey(),
    ])->and($assignable->openAssignment()?->is($defaultAssignment))->toBeTrue()
        ->and($assignable->assignment('driver')?->is($driverAssignment))->toBeTrue()
        ->and($assignable->assignee()?->is($defaultAssignee))->toBeTrue()
        ->and($assignable->assignee('driver')?->is($driver))->toBeTrue()
        ->and($assignable->openAssignment('seller'))->toBeNull()
        ->and($assignable->assignee('seller'))->toBeNull()
        ->and($assignable->isAssigned())->toBeTrue()
        ->and($assignable->isAssigned('driver'))->toBeTrue()
        ->and($assignable->isAssigned('seller'))->toBeFalse();
});

it('counts active and unexpired offered workload with an optional type constraint', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');
    Relation::morphMap(['lead' => AssignableRecord::class]);

    $assignee = AssigneeRecord::query()->create();

    collect([
        [
            'assignable_type' => 'lead',
            'assignable_id' => 1,
            'status' => AssignmentStatus::Active,
            'slot' => '',
        ],
        [
            'assignable_type' => UuidAssignableRecord::class,
            'assignable_id' => (string) Str::uuid(),
            'status' => AssignmentStatus::Offered,
            'slot' => '',
            'expires_at' => now()->addSecond(),
        ],
        [
            'assignable_type' => 'lead',
            'assignable_id' => 2,
            'status' => AssignmentStatus::Offered,
            'slot' => '',
            'expires_at' => now(),
        ],
        [
            'assignable_type' => 'lead',
            'assignable_id' => 3,
            'status' => AssignmentStatus::Ended,
            'slot' => null,
            'ended_reason' => EndReason::Completed,
            'ended_at' => now(),
        ],
    ])->each(fn (array $state): Assignment => Assignment::query()->create($state + [
        'assignee_type' => $assignee->getMorphClass(),
        'assignee_id' => $assignee->getKey(),
    ]));

    expect($assignee->assignments()->count())->toBe(4)
        ->and($assignee->activeAssignments()->count())->toBe(1)
        ->and($assignee->openAssignments()->count())->toBe(2)
        ->and($assignee->workload())->toBe(2)
        ->and($assignee->workload(AssignableRecord::class))->toBe(1)
        ->and($assignee->workload(UuidAssignableRecord::class))->toBe(1);
});

it('reads assignments through uuid assignables and ulid assignees', function (): void {
    $migration = require __DIR__.'/../../database/migrations/create_assignments_table.php.stub';
    $migration->down();
    config()->set('assignment.assignable_key_type', 'uuid');
    config()->set('assignment.assignee_key_type', 'ulid');
    $migration->up();

    $assignable = UuidAssignableRecord::query()->create(['id' => (string) Str::uuid()]);
    $assignee = UlidAssigneeRecord::query()->create(['id' => (string) Str::ulid()]);
    $assignment = Assignment::query()->create([
        'assignable_type' => $assignable->getMorphClass(),
        'assignable_id' => $assignable->getKey(),
        'assignee_type' => $assignee->getMorphClass(),
        'assignee_id' => $assignee->getKey(),
        'slot' => '',
        'status' => AssignmentStatus::Active,
    ]);

    expect($assignable->openAssignment()?->is($assignment))->toBeTrue()
        ->and($assignable->assignee()?->is($assignee))->toBeTrue()
        ->and($assignee->openAssignments()->first()?->is($assignment))->toBeTrue()
        ->and($assignee->workload())->toBe(1);
});

<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Exceptions\SlotOccupied;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Tests\Concurrency\ParallelRun;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (! ParallelRun::isSupported()) {
        $this->markTestSkipped(ParallelRun::UNSUPPORTED_REASON);
    }

    $this->useForkableDatabase();

    Schema::create('test_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
    });
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    $this->discardForkableDatabase();
});

it('allows exactly one concurrent assignment to claim a slot', function (): void {
    $assignable = AssignableRecord::query()->create();
    $assignableId = $assignable->getKey();

    $outcomes = ParallelRun::run(8, function (int $child) use ($assignableId): string {
        $assignable = AssignableRecord::query()->findOrFail($assignableId);
        $assignee = AssigneeRecord::query()->create();

        try {
            $assignable->assign($assignee);

            return 'won';
        } catch (SlotOccupied) {
            return 'lost';
        }
    });

    $counts = array_count_values($outcomes);

    expect(array_filter($outcomes, fn (string $outcome): bool => str_starts_with($outcome, 'failed:')))->toBe([])
        ->and($counts['won'] ?? 0)->toBe(1)
        ->and($counts['lost'] ?? 0)->toBe(7)
        ->and(Assignment::query()->active()->count())->toBe(1);
});

it('allows one concurrent assignment to displace an offer that expires during the race', function (): void {
    $assignable = AssignableRecord::query()->create();
    $incumbent = AssigneeRecord::query()->create();
    $assignableId = $assignable->getKey();

    $offer = Assignment::query()->create([
        'assignable_type' => $assignable->getMorphClass(),
        'assignable_id' => $assignableId,
        'assignee_type' => $incumbent->getMorphClass(),
        'assignee_id' => $incumbent->getKey(),
        'slot' => '',
        'status' => AssignmentStatus::Offered,
        'expires_at' => now()->addSecond(),
    ]);

    expect($offer->isExpired())->toBeFalse();

    $outcomes = ParallelRun::run(6, function (int $child) use ($assignableId): string {
        $assignable = AssignableRecord::query()->findOrFail($assignableId);
        $assignee = AssigneeRecord::query()->create();

        try {
            $assignable->assign($assignee);

            return 'won';
        } catch (SlotOccupied) {
            return 'lost';
        }
    }, 1.2);

    $counts = array_count_values($outcomes);

    expect(array_filter($outcomes, fn (string $outcome): bool => str_starts_with($outcome, 'failed:')))->toBe([])
        ->and($counts['won'] ?? 0)->toBe(1)
        ->and($counts['lost'] ?? 0)->toBe(5)
        ->and(Assignment::query()->active()->count())->toBe(1)
        ->and(Assignment::query()->ended()->count())->toBe(1);
});

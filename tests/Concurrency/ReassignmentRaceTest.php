<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Exceptions\SlotOccupied;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Tests\Concurrency\ParallelRun;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
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
    $this->discardForkableDatabase();
});

it('allows exactly one concurrent reassignment to replace an open row', function (): void {
    $assignable = AssignableRecord::query()->create();
    $current = AssigneeRecord::query()->create();
    $assignable->assign($current);
    $assignableId = $assignable->getKey();

    $outcomes = ParallelRun::run(2, function () use ($assignableId): string {
        $assignable = AssignableRecord::query()->findOrFail($assignableId);
        $replacement = AssigneeRecord::query()->create();
        $assignmentClass = Assignment::class;
        Event::listen("eloquent.updating: {$assignmentClass}", function (Assignment $assignment): void {
            if ($assignment->ended_reason === EndReason::Reassigned) {
                usleep(250_000);
            }
        });

        try {
            $assignable->reassign($replacement);

            return 'reassigned';
        } catch (SlotOccupied) {
            return 'slot-occupied';
        }
    });

    expect(array_count_values($outcomes))->toMatchArray([
        'reassigned' => 1,
        'slot-occupied' => 1,
    ])->and(Assignment::query()->open()->count())->toBe(1)
        ->and(Assignment::query()->active()->count())->toBe(1)
        ->and(Assignment::query()->ended()->count())->toBe(1)
        ->and(Assignment::query()->ended()->first()?->ended_reason)->toBe(EndReason::Reassigned)
        ->and(Assignment::query()->count())->toBe(2);
});

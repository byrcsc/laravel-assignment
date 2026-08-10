<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Exceptions\OfferNotOpen;
use ByRcsc\LaravelAssignment\Exceptions\SlotOccupied;
use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Tests\Concurrency\ParallelRun;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (! ParallelRun::isSupported()) {
        $this->markTestSkipped(ParallelRun::UNSUPPORTED_REASON);
    }

    if (ParallelRun::needsServerDatabase()) {
        $this->markTestSkipped(ParallelRun::SINGLE_WRITER_REASON);
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

it('allows exactly one answer when accept races decline', function (): void {
    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $offer = $assignable->offer($assignee);
    $offerId = $offer->getKey();
    $assigneeId = $assignee->getKey();

    $outcomes = ParallelRun::run(2, function (int $child) use ($offerId, $assigneeId): string {
        $offer = Assignment::query()->findOrFail($offerId);
        $assignee = AssigneeRecord::query()->findOrFail($assigneeId);

        try {
            if ($child === 0) {
                $assignee->accept($offer);

                return 'accepted';
            }

            $assignee->decline($offer);

            return 'declined';
        } catch (OfferNotOpen) {
            return 'lost';
        }
    });

    $final = $offer->refresh();

    expect(array_count_values($outcomes)['lost'] ?? 0)->toBe(1)
        ->and(in_array('accepted', $outcomes, true) || in_array('declined', $outcomes, true))->toBeTrue()
        ->and($final->isActive() || $final->ended_reason === EndReason::Declined)->toBeTrue()
        ->and(Assignment::query()->count())->toBe(1);
});

it('never double fills when accept races expired-offer displacement', function (): void {
    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $offer = $assignable->offer($assignee, ttlSeconds: 1);
    $assignableId = $assignable->getKey();
    $assigneeId = $assignee->getKey();
    $offerId = $offer->getKey();

    $outcomes = ParallelRun::run(2, function (int $child) use ($assignableId, $assigneeId, $offerId): string {
        if ($child === 0) {
            try {
                AssigneeRecord::query()
                    ->findOrFail($assigneeId)
                    ->accept(Assignment::query()->findOrFail($offerId));

                return 'accepted';
            } catch (OfferNotOpen) {
                return 'offer-closed';
            }
        }

        try {
            AssignableRecord::query()
                ->findOrFail($assignableId)
                ->assign(AssigneeRecord::query()->create());

            return 'assigned';
        } catch (SlotOccupied) {
            return 'slot-occupied';
        }
    }, 1.2);

    expect($outcomes)->toContain('assigned')
        ->and($outcomes)->toContain('offer-closed')
        ->and(Assignment::query()->active()->count())->toBe(1)
        ->and(Assignment::query()->open()->count())->toBe(1)
        ->and(Assignment::query()->where('status', AssignmentStatus::Ended->value)->count())->toBe(1);
});

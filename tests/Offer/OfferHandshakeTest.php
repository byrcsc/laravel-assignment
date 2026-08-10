<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Events\Assigned;
use ByRcsc\LaravelAssignment\Events\AssignmentAccepted;
use ByRcsc\LaravelAssignment\Events\AssignmentDeclined;
use ByRcsc\LaravelAssignment\Events\AssignmentOffered;
use ByRcsc\LaravelAssignment\Exceptions\NotTheAssignee;
use ByRcsc\LaravelAssignment\Exceptions\OfferNotOpen;
use ByRcsc\LaravelAssignment\Exceptions\SlotOccupied;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ActorRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use Carbon\CarbonImmutable;
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

it('creates a standing offer and an expiring offer through the shared slot writer', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');
    Event::fake([AssignmentOffered::class]);

    $standingAssignable = AssignableRecord::query()->create();
    $expiringAssignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $actor = ActorRecord::query()->create(['id' => 'dispatcher']);

    $standing = $standingAssignable->offer($assignee, by: $actor);
    $expiring = $expiringAssignable->offer($assignee, ttlSeconds: 120);

    expect($standing->isOffered())->toBeTrue()
        ->and($standing->offered_at?->equalTo(now()))->toBeTrue()
        ->and($standing->expires_at)->toBeNull()
        ->and($standing->assignedBy->is($actor))->toBeTrue()
        ->and($expiring->expires_at?->equalTo(now()->addSeconds(120)))->toBeTrue()
        ->and($assignee->workload())->toBe(2);

    Event::assertDispatchedTimes(AssignmentOffered::class, 2);
});

it('keeps an open offer exclusive and lets a later offer displace it after expiry', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $assignable = AssignableRecord::query()->create();
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();

    $firstOffer = $assignable->offer($first, ttlSeconds: 10);

    expect(fn () => $assignable->offer($second))->toThrow(SlotOccupied::class)
        ->and(fn () => $assignable->assign($second))->toThrow(SlotOccupied::class);

    CarbonImmutable::setTestNow(now()->addSeconds(11));
    $secondOffer = $assignable->offer($second);

    expect($firstOffer->refresh()->isEnded())->toBeTrue()
        ->and($firstOffer->ended_reason)->toBe(EndReason::Expired)
        ->and($secondOffer->isOffered())->toBeTrue();
});

it('accepts an open offer and dispatches acceptance before activation', function (): void {
    $events = [];
    Event::listen(AssignmentAccepted::class, function () use (&$events): void {
        $events[] = 'accepted';
    });
    Event::listen(Assigned::class, function () use (&$events): void {
        $events[] = 'assigned';
    });

    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $offer = $assignable->offer($assignee);

    $accepted = $assignee->accept($offer);

    expect($accepted)->toBe($offer)
        ->and($offer->isActive())->toBeTrue()
        ->and($offer->accepted_at)->not->toBeNull()
        ->and($offer->getRawOriginal('slot'))->toBe('')
        ->and($assignee->workload())->toBe(1)
        ->and($events)->toBe(['accepted', 'assigned']);
});

it('declines an open offer and releases its slot', function (): void {
    Event::fake([AssignmentDeclined::class]);

    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $offer = $assignable->offer($assignee);

    $declined = $assignee->decline($offer);

    expect($declined)->toBe($offer)
        ->and($offer->isEnded())->toBeTrue()
        ->and($offer->ended_reason)->toBe(EndReason::Declined)
        ->and($offer->ended_at)->not->toBeNull()
        ->and($offer->getRawOriginal('slot'))->toBeNull()
        ->and($assignee->workload())->toBe(0);

    Event::assertDispatchedTimes(AssignmentDeclined::class, 1);
});

it('allows only the targeted assignee to answer an offer', function (): void {
    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $other = AssigneeRecord::query()->create();
    $offer = $assignable->offer($assignee);

    expect(fn () => $other->accept($offer))->toThrow(NotTheAssignee::class)
        ->and(fn () => $other->decline($offer))->toThrow(NotTheAssignee::class)
        ->and($offer->refresh()->isOffered())->toBeTrue();
});

it('rejects expired and already answered offers', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $expiredAssignable = AssignableRecord::query()->create();
    $declinedAssignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();

    $expired = $expiredAssignable->offer($assignee, ttlSeconds: 10);
    $declined = $declinedAssignable->offer($assignee);
    $assignee->decline($declined);

    CarbonImmutable::setTestNow(now()->addSeconds(11));

    expect(fn () => $assignee->accept($expired))->toThrow(OfferNotOpen::class)
        ->and(fn () => $assignee->decline($expired))->toThrow(OfferNotOpen::class)
        ->and(fn () => $assignee->accept($declined))->toThrow(OfferNotOpen::class);
});

it('enforces the offer boundary without a tick', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 12:00:00');

    $beforeAssignable = AssignableRecord::query()->create();
    $afterAssignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();

    $before = $beforeAssignable->offer($assignee, ttlSeconds: 10);
    $after = $afterAssignable->offer($assignee, ttlSeconds: 10);

    CarbonImmutable::setTestNow(now()->addSeconds(9));
    $assignee->accept($before);

    CarbonImmutable::setTestNow(now()->addSeconds(2));

    expect($before->isActive())->toBeTrue()
        ->and(fn () => $assignee->accept($after))->toThrow(OfferNotOpen::class);
});

it('defers offer events until the surrounding transaction commits', function (): void {
    Event::fake([AssignmentOffered::class]);

    $assignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();

    DB::beginTransaction();
    $assignable->offer($assignee);
    Event::assertNotDispatched(AssignmentOffered::class);
    DB::commit();

    Event::assertDispatchedTimes(AssignmentOffered::class, 1);
});

it('defers answer events until the surrounding transaction commits', function (): void {
    $acceptedAssignable = AssignableRecord::query()->create();
    $declinedAssignable = AssignableRecord::query()->create();
    $assignee = AssigneeRecord::query()->create();
    $acceptedOffer = $acceptedAssignable->offer($assignee);
    $declinedOffer = $declinedAssignable->offer($assignee);

    Event::fake([AssignmentAccepted::class, Assigned::class, AssignmentDeclined::class]);

    DB::beginTransaction();
    $assignee->accept($acceptedOffer);
    Event::assertNotDispatched(AssignmentAccepted::class);
    Event::assertNotDispatched(Assigned::class);
    DB::commit();

    Event::assertDispatchedTimes(AssignmentAccepted::class, 1);
    Event::assertDispatchedTimes(Assigned::class, 1);

    DB::beginTransaction();
    $assignee->decline($declinedOffer);
    Event::assertNotDispatched(AssignmentDeclined::class);
    DB::commit();

    Event::assertDispatchedTimes(AssignmentDeclined::class, 1);
});

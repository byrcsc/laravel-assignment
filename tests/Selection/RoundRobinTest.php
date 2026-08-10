<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Exceptions\SlotOccupied;
use ByRcsc\LaravelAssignment\Facades\Assignment as AssignmentFacade;
use ByRcsc\LaravelAssignment\Models\AssignmentScope;
use ByRcsc\LaravelAssignment\Policies\RoundRobin;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('test_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
    });
});

it('rotates across rebuilt, reordered, grown, and shrunk candidate lists', function (): void {
    $assignables = collect(range(1, 5))
        ->map(fn (): AssignableRecord => AssignableRecord::query()->create());
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();
    $third = AssigneeRecord::query()->create();
    $fourth = AssigneeRecord::query()->create();
    $scope = 'campaign:north';

    $picked = [
        AssignmentFacade::for($assignables[0])->among([$first, $second, $third]),
        AssignmentFacade::for($assignables[1])->among(AssigneeRecord::query()->whereKey([$first->getKey(), $second->getKey(), $third->getKey()])->get()),
        AssignmentFacade::for($assignables[2])->among([$third, $second, $first]),
        AssignmentFacade::for($assignables[3])->among([$second, $third]),
        AssignmentFacade::for($assignables[4])->among([$second, $third, $fourth]),
    ];

    $assigneeIds = collect($picked)
        ->map(fn ($pending): mixed => $pending->scope($scope)->using(RoundRobin::class)->assign()?->assignee_id)
        ->all();
    $state = AssignmentScope::query()->where('scope', $scope)->firstOrFail()->state;

    expect($assigneeIds)->toBe([
        $first->getKey(),
        $second->getKey(),
        $first->getKey(),
        $second->getKey(),
        $third->getKey(),
    ])->and(data_get($state, 'roundRobin.cursor'))->toBe(json_encode([
        $third->getMorphClass(),
        (string) $third->getKey(),
    ], JSON_THROW_ON_ERROR));
});

it('rolls back the cursor when the selected slot cannot be filled', function (): void {
    $firstAssignable = AssignableRecord::query()->create();
    $occupiedAssignable = AssignableRecord::query()->create();
    $nextAssignable = AssignableRecord::query()->create();
    $first = AssigneeRecord::query()->create();
    $second = AssigneeRecord::query()->create();
    $occupant = AssigneeRecord::query()->create();
    $scope = 'campaign:rollback';
    $candidates = [$first, $second];

    AssignmentFacade::for($firstAssignable)
        ->among($candidates)
        ->scope($scope)
        ->using(RoundRobin::class)
        ->assign();
    $occupiedAssignable->assign($occupant);

    expect(fn () => AssignmentFacade::for($occupiedAssignable)
        ->among($candidates)
        ->scope($scope)
        ->using(RoundRobin::class)
        ->assign())->toThrow(SlotOccupied::class);

    $next = AssignmentFacade::for($nextAssignable)
        ->among($candidates)
        ->scope($scope)
        ->using(RoundRobin::class)
        ->assign();

    expect($next?->assignee->is($second))->toBeTrue();
});

<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Enums\Mode;
use ByRcsc\LaravelAssignment\Notifications\AssignmentReceived;
use ByRcsc\LaravelAssignment\Notifications\AssignmentRemoved;
use ByRcsc\LaravelAssignment\Notifications\OfferExpired;
use ByRcsc\LaravelAssignment\Notifications\OfferReceived;
use ByRcsc\LaravelAssignment\Tests\Fixtures\AssigneeRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\CustomOfferNotification;
use ByRcsc\LaravelAssignment\Tests\Fixtures\InjectedAssignmentProfile;
use ByRcsc\LaravelAssignment\Tests\Fixtures\NotifiableAssigneeRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileAssignableRecord;
use ByRcsc\LaravelAssignment\Tests\Fixtures\ProfileSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Notification::fake();
    Schema::create('test_profile_assignables', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('test_assignees', function (Blueprint $table): void {
        $table->id();
        $table->string('email')->nullable();
    });

    $settings = new ProfileSettings;
    $settings->mode = Mode::Offer;
    $settings->ttl = 60;
    app()->instance(ProfileSettings::class, $settings);
    config()->set('assignment.profiles', [
        ProfileAssignableRecord::class => InjectedAssignmentProfile::class,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('notifies direct assignment, offer acceptance, and unassignment', function (): void {
    $direct = ProfileAssignableRecord::query()->create();
    $offered = ProfileAssignableRecord::query()->create();
    $assignee = NotifiableAssigneeRecord::query()->create(['email' => 'driver@example.com']);

    $directAssignment = $direct->assign($assignee);
    $offer = $offered->offer($assignee);

    Notification::assertSentTo($assignee, AssignmentReceived::class, 1);
    Notification::assertSentTo($assignee, OfferReceived::class, 1);

    $assignee->accept($offer);
    $direct->unassign();

    Notification::assertSentTo($assignee, AssignmentReceived::class, 2);
    Notification::assertSentTo($assignee, AssignmentRemoved::class, function (AssignmentRemoved $notification) use ($directAssignment): bool {
        return $notification->assignment->is($directAssignment);
    });
});

it('sends no notification for decline but notifies the next cascade candidate', function (): void {
    $assignable = ProfileAssignableRecord::query()->create();
    $first = NotifiableAssigneeRecord::query()->create();
    $second = NotifiableAssigneeRecord::query()->create();
    app(ProfileSettings::class)->candidates = [$first, $second];

    $offer = $assignable->autoAssign();
    Notification::assertSentTo($first, OfferReceived::class, 1);

    $first->decline($offer);

    Notification::assertSentTo($first, OfferReceived::class, 1);
    Notification::assertSentTo($first, AssignmentRemoved::class, 0);
    Notification::assertSentTo($second, OfferReceived::class, 1);
});

it('notifies the assignee whose profile offer expires', function (): void {
    CarbonImmutable::setTestNow('2026-08-08 10:00:00');
    $assignable = ProfileAssignableRecord::query()->create();
    $assignee = NotifiableAssigneeRecord::query()->create();
    app(ProfileSettings::class)->candidates = [$assignee];
    $offer = $assignable->autoAssign();

    CarbonImmutable::setTestNow('2026-08-08 10:01:01');
    $this->artisan('assignment:tick')->assertSuccessful();

    Notification::assertSentTo($assignee, OfferExpired::class, function (OfferExpired $notification) use ($offer): bool {
        return $notification->assignment->is($offer);
    });
});

it('notifies each side of reassignment exactly once', function (): void {
    $assignable = ProfileAssignableRecord::query()->create();
    $old = NotifiableAssigneeRecord::query()->create();
    $new = NotifiableAssigneeRecord::query()->create();
    $assignable->assign($old);
    Notification::fake();

    $assignable->reassign($new);

    Notification::assertSentTo($old, AssignmentRemoved::class, 1);
    Notification::assertSentTo($new, AssignmentReceived::class, 1);
    Notification::assertCount(2);
});

it('supports silencing, swapping, and rechanneling notifications', function (): void {
    $assignable = ProfileAssignableRecord::query()->create();
    $assignee = NotifiableAssigneeRecord::query()->create();
    config()->set('assignment.notifications.assigned', null);

    $assignable->assign($assignee);
    Notification::assertNothingSent();

    $second = ProfileAssignableRecord::query()->create();
    config()->set('assignment.notifications.offered', CustomOfferNotification::class);
    config()->set('assignment.notification_channels', ['database']);
    $offer = $second->offer($assignee);

    Notification::assertSentTo($assignee, CustomOfferNotification::class, function (CustomOfferNotification $notification) use ($offer, $assignee): bool {
        if ($notification->via($assignee) !== ['database']) {
            return false;
        }

        return $notification->toArray($assignee) === [
            'assignment_id' => $offer->getKey(),
            'assignable_type' => $offer->assignable_type,
            'assignable_id' => $offer->assignable_id,
            'role' => $offer->role,
            'status' => $offer->status->value,
        ];
    });
});

it('silently skips assignees without the Notifiable trait', function (): void {
    $assignable = ProfileAssignableRecord::query()->create();
    $team = AssigneeRecord::query()->create();

    expect(fn () => $assignable->assign($team))->not->toThrow(Throwable::class);

    Notification::assertNothingSent();
});

it('renders the shipped mail view of every notification', function (string $notification, string $body): void {
    $assignable = ProfileAssignableRecord::query()->create();
    $assignee = NotifiableAssigneeRecord::query()->create(['email' => 'assignee@example.test']);
    $assignment = $assignable->assign($assignee);

    $mail = (new $notification($assignment))->toMail($assignee);

    expect((string) $mail->render())->toContain($body);
})->with([
    [OfferReceived::class, 'A new assignment offer is waiting for your response.'],
    [OfferExpired::class, 'An assignment offer expired before it was accepted.'],
    [AssignmentReceived::class, 'An assignment has been assigned to you.'],
    [AssignmentRemoved::class, 'An assignment is no longer assigned to you.'],
]);

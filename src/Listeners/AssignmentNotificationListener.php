<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Listeners;

use ByRcsc\LaravelAssignment\Enums\EndReason;
use ByRcsc\LaravelAssignment\Events\Assigned;
use ByRcsc\LaravelAssignment\Events\AssignmentOffered;
use ByRcsc\LaravelAssignment\Events\OfferExpired;
use ByRcsc\LaravelAssignment\Events\Reassigned;
use ByRcsc\LaravelAssignment\Events\Unassigned;
use ByRcsc\LaravelAssignment\Models\Assignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;

/** @internal */
final class AssignmentNotificationListener
{
    public function offered(AssignmentOffered $event): void
    {
        $this->send('offered', $event->assignment);
    }

    public function expired(OfferExpired $event): void
    {
        $this->send('offer_expired', $event->assignment);
    }

    public function assigned(Assigned $event): void
    {
        $this->send('assigned', $event->assignment);
    }

    public function unassigned(Unassigned $event): void
    {
        if ($event->assignment->ended_reason === EndReason::Reassigned) {
            return;
        }

        $this->send('unassigned', $event->assignment);
    }

    public function reassigned(Reassigned $event): void
    {
        $this->send('unassigned', $event->oldAssignment);
    }

    private function send(string $moment, Assignment $assignment): void
    {
        $class = config("assignment.notifications.{$moment}");

        if ($class === null) {
            return;
        }

        if (! is_string($class)) {
            return;
        }

        $assignee = $assignment->assignee;

        if (! $assignee instanceof Model) {
            return;
        }

        if (! in_array(Notifiable::class, class_uses_recursive($assignee), true)) {
            return;
        }

        $notification = app()->make($class, ['assignment' => $assignment]);

        Notification::send($assignee, $notification);
    }
}

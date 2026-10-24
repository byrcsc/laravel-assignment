<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Notifications;

use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Support\ModelIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

abstract class AssignmentNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Assignment $assignment) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = config('assignment.notification_channels');

        if (! is_array($channels)) {
            return [];
        }

        return array_values(array_filter($channels, is_string(...)));
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The shipped views extend `mail::message`. Laravel registers the
        // `mail` view namespace only when it resolves the Markdown renderer,
        // so these views must render through markdown(), not view().
        return (new MailMessage)
            ->subject($this->translated('subject'))
            ->markdown($this->view(), [
                'assignment' => $this->assignment,
                'body' => $this->translated('body'),
            ]);
    }

    /** @return array<string, int|string|null> */
    public function toArray(object $notifiable): array
    {
        return [
            'assignment_id' => ModelIdentity::key($this->assignment),
            'assignable_type' => $this->assignment->assignable_type,
            'assignable_id' => $this->assignment->assignable_id,
            'role' => $this->assignment->role,
            'status' => $this->assignment->status->value,
        ];
    }

    abstract protected function name(): string;

    protected function view(): string
    {
        $viewName = str_replace('_', '-', $this->name());

        return "assignment::mail.{$viewName}";
    }

    private function translated(string $part): string
    {
        return trans("assignment::notifications.{$this->name()}.{$part}");
    }
}

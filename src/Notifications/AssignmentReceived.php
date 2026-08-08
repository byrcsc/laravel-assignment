<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Notifications;

final class AssignmentReceived extends AssignmentNotification
{
    protected function name(): string
    {
        return 'assignment_received';
    }
}

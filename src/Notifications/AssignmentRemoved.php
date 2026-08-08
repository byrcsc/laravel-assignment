<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Notifications;

final class AssignmentRemoved extends AssignmentNotification
{
    protected function name(): string
    {
        return 'assignment_removed';
    }
}

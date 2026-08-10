<?php

declare(strict_types=1);

it('ships the assignment persistence and automation defaults', function (): void {
    expect(config('assignment.tables'))->toBe([
        'assignments' => 'assignments',
        'scopes' => 'assignment_scopes',
        'queue' => 'assignment_queue',
    ])->and(config('assignment.assignable_key_type'))->toBe('id')
        ->and(config('assignment.assignee_key_type'))->toBe('id')
        ->and(config('assignment.profiles'))->toBe([])
        ->and(config('assignment.notifications'))->toBe([
            'offered' => 'ByRcsc\\LaravelAssignment\\Notifications\\OfferReceived',
            'offer_expired' => 'ByRcsc\\LaravelAssignment\\Notifications\\OfferExpired',
            'assigned' => 'ByRcsc\\LaravelAssignment\\Notifications\\AssignmentReceived',
            'unassigned' => 'ByRcsc\\LaravelAssignment\\Notifications\\AssignmentRemoved',
        ])->and(config('assignment.notification_channels'))->toBe(['mail'])
        ->and(config('assignment.dispatch_expiry_jobs'))->toBeFalse();
});

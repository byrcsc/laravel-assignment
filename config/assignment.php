<?php

declare(strict_types=1);

return [
    'tables' => [
        'assignments' => 'assignments',
        'scopes' => 'assignment_scopes',
        'queue' => 'assignment_queue',
    ],

    'assignable_key_type' => env('ASSIGNMENT_ASSIGNABLE_KEY_TYPE', 'id'),
    'assignee_key_type' => env('ASSIGNMENT_ASSIGNEE_KEY_TYPE', 'id'),

    'profiles' => [],

    'notifications' => [
        'offered' => 'ByRcsc\\LaravelAssignment\\Notifications\\OfferReceived',
        'offer_expired' => 'ByRcsc\\LaravelAssignment\\Notifications\\OfferExpired',
        'assigned' => 'ByRcsc\\LaravelAssignment\\Notifications\\AssignmentReceived',
        'unassigned' => 'ByRcsc\\LaravelAssignment\\Notifications\\AssignmentRemoved',
    ],

    'notification_channels' => ['mail', 'database'],

    'dispatch_expiry_jobs' => false,
];

<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Notifications;

final class OfferReceived extends AssignmentNotification
{
    protected function name(): string
    {
        return 'offer_received';
    }
}

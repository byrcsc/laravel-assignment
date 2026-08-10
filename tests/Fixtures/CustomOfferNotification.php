<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use ByRcsc\LaravelAssignment\Notifications\AssignmentNotification;

final class CustomOfferNotification extends AssignmentNotification
{
    protected function name(): string
    {
        return 'offer_received';
    }
}

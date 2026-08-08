<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Enums;

enum EndReason: string
{
    case Unassigned = 'unassigned';
    case Reassigned = 'reassigned';
    case Declined = 'declined';
    case Expired = 'expired';
    case Completed = 'completed';
}

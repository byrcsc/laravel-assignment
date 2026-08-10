<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Enums;

enum AssignmentStatus: string
{
    case Offered = 'offered';
    case Active = 'active';
    case Ended = 'ended';
}

<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Exceptions;

use Throwable;

final class SlotOccupied extends AssignmentException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The assignment slot is already occupied.', 0, $previous);
    }
}

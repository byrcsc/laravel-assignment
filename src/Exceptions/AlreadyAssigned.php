<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Exceptions;

final class AlreadyAssigned extends AssignmentException
{
    public function __construct()
    {
        parent::__construct('The assignee already occupies this assignment slot.');
    }
}

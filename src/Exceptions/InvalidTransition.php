<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Exceptions;

final class InvalidTransition extends AssignmentException
{
    public function __construct()
    {
        parent::__construct('The assignment cannot make the requested transition.');
    }
}

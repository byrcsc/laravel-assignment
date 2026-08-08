<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Exceptions;

final class NotAssigned extends AssignmentException
{
    public function __construct()
    {
        parent::__construct('The assignment slot has no open assignment.');
    }
}

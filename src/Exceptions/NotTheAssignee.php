<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Exceptions;

final class NotTheAssignee extends AssignmentException
{
    public function __construct()
    {
        parent::__construct('Only the targeted assignee can answer this offer.');
    }
}

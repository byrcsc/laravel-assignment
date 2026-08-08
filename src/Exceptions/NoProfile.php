<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class NoProfile extends AssignmentException
{
    public function __construct(Model $assignable)
    {
        $class = $assignable::class;

        parent::__construct("No assignment profile is registered for [{$class}].");
    }
}

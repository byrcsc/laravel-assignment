<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Events;

use ByRcsc\LaravelAssignment\Models\Assignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class Unassigned implements ShouldDispatchAfterCommit
{
    public function __construct(public Assignment $assignment) {}
}

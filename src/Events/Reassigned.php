<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Events;

use ByRcsc\LaravelAssignment\Models\Assignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class Reassigned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Assignment $oldAssignment,
        public Assignment $newAssignment,
    ) {}
}

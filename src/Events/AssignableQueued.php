<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Events;

use ByRcsc\LaravelAssignment\Models\QueuedAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class AssignableQueued implements ShouldDispatchAfterCommit
{
    public function __construct(public QueuedAssignment $entry) {}
}

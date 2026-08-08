<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment;

use ByRcsc\LaravelAssignment\Actions\AssignmentWriter;
use ByRcsc\LaravelAssignment\Support\ScopeStateRepository;
use ByRcsc\LaravelAssignment\Support\SelectionPolicyResolver;
use Illuminate\Database\Eloquent\Model;

final readonly class AssignmentManager
{
    public function __construct(
        private AssignmentWriter $writer,
        private ScopeStateRepository $states,
        private SelectionPolicyResolver $policies,
    ) {}

    public function for(Model $assignable): PendingAssignment
    {
        return new PendingAssignment(
            $assignable,
            $this->writer,
            $this->states,
            $this->policies,
        );
    }
}

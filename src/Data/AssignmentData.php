<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Data;

use ByRcsc\LaravelAssignment\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Model;

final readonly class AssignmentData
{
    public function __construct(
        public Model $assignable,
        public Model $assignee,
        public AssignmentStatus $status,
        public ?string $role = null,
        public ?Model $by = null,
        public ?string $scope = null,
        public ?int $ttlSeconds = null,
    ) {}
}

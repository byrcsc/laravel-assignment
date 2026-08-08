<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use ByRcsc\LaravelAssignment\AssignmentProfile;
use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Enums\Mode;
use Closure;
use Illuminate\Database\Eloquent\Model;

final class InjectedAssignmentProfile extends AssignmentProfile
{
    public function __construct(private readonly ProfileSettings $settings) {}

    public function candidates(Model $assignable, ?string $role): iterable
    {
        $this->settings->record(__FUNCTION__, $role);

        return $this->settings->candidates;
    }

    public function policy(Model $assignable, ?string $role): string|SelectionPolicy|Closure
    {
        $this->settings->record(__FUNCTION__, $role);

        return $this->settings->policy;
    }

    public function scope(Model $assignable, ?string $role): ?string
    {
        $this->settings->record(__FUNCTION__, $role);

        return $this->settings->scope;
    }

    public function mode(Model $assignable, ?string $role): Mode
    {
        $this->settings->record(__FUNCTION__, $role);

        return $this->settings->mode;
    }

    public function offerTtl(Model $assignable, ?string $role): ?int
    {
        $this->settings->record(__FUNCTION__, $role);

        return $this->settings->ttl;
    }

    public function queueWhenEmpty(Model $assignable, ?string $role): bool
    {
        $this->settings->record(__FUNCTION__, $role);

        return $this->settings->queueWhenEmpty;
    }

    public function queuePriority(Model $assignable, ?string $role): int
    {
        $this->settings->record(__FUNCTION__, $role);

        return $this->settings->queuePriority;
    }
}

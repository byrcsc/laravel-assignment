<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\Fixtures;

use ByRcsc\LaravelAssignment\AssignmentProfile;
use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Enums\Mode;
use Closure;
use Illuminate\Database\Eloquent\Model;

final class OfferAssignmentProfile extends AssignmentProfile
{
    public function __construct(private readonly ProfileSettings $settings) {}

    public function candidates(Model $assignable, ?string $role): iterable
    {
        return $this->settings->candidates;
    }

    public function policy(Model $assignable, ?string $role): string|SelectionPolicy|Closure
    {
        return $this->settings->policy;
    }

    public function mode(Model $assignable, ?string $role): Mode
    {
        return Mode::Offer;
    }

    public function offerTtl(Model $assignable, ?string $role): ?int
    {
        return $this->settings->ttl;
    }
}

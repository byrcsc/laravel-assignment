<?php

declare(strict_types=1);

namespace Workbench\App\Profiles;

use ByRcsc\LaravelAssignment\AssignmentProfile;
use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use ByRcsc\LaravelAssignment\Enums\Mode;
use ByRcsc\LaravelAssignment\Policies\FirstAvailable;
use ByRcsc\LaravelAssignment\Policies\LeastWorkload;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Workbench\App\Models\Callout;
use Workbench\App\Support\Roster;

/**
 * One callout holds two slots at once. The crew slot is offered and can
 * cascade, because a crew that does not answer should not hold the job; the
 * supervisor slot is assigned outright, in the order the query returns.
 * Eligibility is a query in {@see Roster}, never a policy.
 */
final class CalloutProfile extends AssignmentProfile
{
    /** @return iterable<int, Model> */
    public function candidates(Model $assignable, ?string $role): iterable
    {
        return Roster::for($this->callout($assignable), $role);
    }

    public function policy(Model $assignable, ?string $role): string|SelectionPolicy
    {
        return $role === 'supervisor'
            ? FirstAvailable::class
            : LeastWorkload::of(Callout::class);
    }

    public function scope(Model $assignable, ?string $role): string
    {
        return $role === 'supervisor'
            ? 'callouts:supervisor'
            : 'suburb:'.$this->callout($assignable)->suburb;
    }

    public function mode(Model $assignable, ?string $role): Mode
    {
        return $role === 'supervisor' ? Mode::Assign : Mode::Offer;
    }

    public function offerTtl(Model $assignable, ?string $role): ?int
    {
        return $role === 'supervisor' ? null : 120;
    }

    /**
     * The contract types the assignable as a `Model`, because the engine has
     * no idea which one a profile is registered for.
     */
    private function callout(Model $assignable): Callout
    {
        if (! $assignable instanceof Callout) {
            throw new InvalidArgumentException(self::class.' is registered for callouts only.');
        }

        return $assignable;
    }
}

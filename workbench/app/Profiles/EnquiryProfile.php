<?php

declare(strict_types=1);

namespace Workbench\App\Profiles;

use ByRcsc\LaravelAssignment\AssignmentProfile;
use ByRcsc\LaravelAssignment\Policies\RoundRobin;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Workbench\App\Models\Enquiry;
use Workbench\App\Support\Roster;

/**
 * Enquiries become active assignments immediately, rotating through the
 * available tradies once per trade. With nobody on the tools the enquiry is
 * queued, and an emergency waits at the front of it.
 */
final class EnquiryProfile extends AssignmentProfile
{
    /** @return iterable<int, Model> */
    public function candidates(Model $assignable, ?string $role): iterable
    {
        return Roster::tradiesFor($this->enquiry($assignable)->trade);
    }

    public function policy(Model $assignable, ?string $role): string
    {
        return RoundRobin::class;
    }

    public function scope(Model $assignable, ?string $role): string
    {
        return 'trade:'.$this->enquiry($assignable)->trade;
    }

    public function queuePriority(Model $assignable, ?string $role): int
    {
        return $this->enquiry($assignable)->emergency ? 10 : 0;
    }

    /**
     * The contract types the assignable as a `Model`, because the engine has
     * no idea which one a profile is registered for.
     */
    private function enquiry(Model $assignable): Enquiry
    {
        if (! $assignable instanceof Enquiry) {
            throw new InvalidArgumentException(self::class.' is registered for enquiries only.');
        }

        return $assignable;
    }
}

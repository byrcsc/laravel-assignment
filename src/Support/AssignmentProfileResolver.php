<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use ByRcsc\LaravelAssignment\AssignmentProfile;
use ByRcsc\LaravelAssignment\Exceptions\NoProfile;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/** @internal */
final class AssignmentProfileResolver
{
    public function resolve(Model $assignable): AssignmentProfile
    {
        $profiles = config('assignment.profiles');

        if (! is_array($profiles)) {
            throw new NoProfile($assignable);
        }

        foreach ($this->hierarchy($assignable) as $class) {
            if (! array_key_exists($class, $profiles)) {
                continue;
            }

            $profile = $profiles[$class];

            if (! is_string($profile)) {
                throw new UnexpectedValueException("Assignment profile for [{$class}] must be a class name.");
            }

            $resolved = app($profile);

            if (! $resolved instanceof AssignmentProfile) {
                throw new UnexpectedValueException("Assignment profile [{$profile}] must extend AssignmentProfile.");
            }

            return $resolved;
        }

        throw new NoProfile($assignable);
    }

    /** @return list<class-string> */
    private function hierarchy(Model $assignable): array
    {
        $class = $assignable::class;

        return [$class, ...array_values(class_parents($assignable))];
    }
}

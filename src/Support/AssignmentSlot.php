<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use InvalidArgumentException;

/** @internal */
final class AssignmentSlot
{
    public static function for(?string $role): string
    {
        if ($role === '') {
            throw new InvalidArgumentException('An assignment role must not be an empty string. Pass null for the default slot.');
        }

        return $role ?? '';
    }
}

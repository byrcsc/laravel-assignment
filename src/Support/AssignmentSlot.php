<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

/** @internal */
final class AssignmentSlot
{
    public static function for(?string $role): string
    {
        return $role ?? '';
    }
}

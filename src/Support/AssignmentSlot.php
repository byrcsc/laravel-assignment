<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

final class AssignmentSlot
{
    public static function for(?string $role): string
    {
        return $role ?? '';
    }
}

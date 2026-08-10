<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/** @internal */
final class AssignmentMorphType
{
    public static function for(string $type): string|int
    {
        if (! is_a($type, Model::class, true)) {
            return $type;
        }

        return Relation::getMorphAlias($type);
    }
}

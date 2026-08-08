<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

final class ModelIdentity
{
    public static function for(Model $model): string
    {
        $key = $model->getKey();

        if (! is_int($key)) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('Assignment candidates must have integer or string keys.');
            }
        }

        return json_encode([
            $model->getMorphClass(),
            (string) $key,
        ], JSON_THROW_ON_ERROR);
    }

    public static function matches(Model $first, Model $second): bool
    {
        return self::for($first) === self::for($second);
    }
}

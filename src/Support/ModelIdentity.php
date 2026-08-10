<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/** @internal */
final class ModelIdentity
{
    public static function for(Model $model): string
    {
        return self::from($model->getMorphClass(), self::key($model));
    }

    public static function from(string $type, int|string $key): string
    {
        return json_encode([$type, (string) $key], JSON_THROW_ON_ERROR);
    }

    public static function key(Model $model): int|string
    {
        $key = $model->getKey();

        if (! is_int($key)) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('Assignment candidates must have integer or string keys.');
            }
        }

        return $key;
    }

    public static function matches(Model $first, Model $second): bool
    {
        return self::for($first) === self::for($second);
    }
}

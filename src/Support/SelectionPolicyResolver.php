<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Support;

use ByRcsc\LaravelAssignment\Contracts\SelectionPolicy;
use Closure;
use UnexpectedValueException;

/** @internal */
final class SelectionPolicyResolver
{
    public function resolve(string|SelectionPolicy|Closure $policy): SelectionPolicy
    {
        if ($policy instanceof SelectionPolicy) {
            return $policy;
        }

        if ($policy instanceof Closure) {
            return new ClosureSelectionPolicy($policy);
        }

        $resolved = app($policy);

        if (! $resolved instanceof SelectionPolicy) {
            throw new UnexpectedValueException("Selection policy [{$policy}] must implement SelectionPolicy.");
        }

        return $resolved;
    }
}

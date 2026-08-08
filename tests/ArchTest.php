<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\Exceptions\AssignmentException;

arch('no debugging leftovers ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('everything declares strict types')
    ->expect('ByRcsc\LaravelAssignment')
    ->toUseStrictTypes();

arch('enums are string backed')
    ->expect('ByRcsc\LaravelAssignment\Enums')
    ->toBeStringBackedEnums();

arch('package exceptions share one catchable root')
    ->expect('ByRcsc\LaravelAssignment\Exceptions')
    ->toExtend(AssignmentException::class)
    ->ignoring(AssignmentException::class);

<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Exceptions;

final class OfferNotOpen extends AssignmentException
{
    public function __construct()
    {
        parent::__construct('The assignment offer is not open.');
    }
}

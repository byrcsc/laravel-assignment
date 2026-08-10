<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Commands;

use ByRcsc\LaravelAssignment\Actions\OfferCascade;
use ByRcsc\LaravelAssignment\AssignmentManager;
use Illuminate\Console\Command;

final class AssignmentTickCommand extends Command
{
    protected $signature = 'assignment:tick';

    protected $description = 'Advance expired assignment offers and flush the assignment queue';

    public function handle(OfferCascade $cascades, AssignmentManager $assignments): int
    {
        $expired = $cascades->expireOverdue();
        $assigned = $assignments->flushQueue();

        $this->components->info("Expired {$expired} offer(s) and assigned {$assigned} queued item(s).");

        return self::SUCCESS;
    }
}

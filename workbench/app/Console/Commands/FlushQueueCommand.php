<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use ByRcsc\LaravelAssignment\Facades\Assignment;
use Illuminate\Console\Command;

final class FlushQueueCommand extends Command
{
    protected $signature = 'demo:flush-queue';

    protected $description = 'Re-resolve every queued assignable through its profile';

    public function handle(): int
    {
        $assigned = Assignment::flushQueue();

        $this->info("Flushed the queue: {$assigned} assigned.");

        return self::SUCCESS;
    }
}

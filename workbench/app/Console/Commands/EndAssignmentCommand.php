<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use ByRcsc\LaravelAssignment\Enums\EndReason;
use Illuminate\Console\Command;
use Workbench\App\Console\Commands\Concerns\ResolvesDemoModels;

/**
 * Both endings in one place: the difference is only the reason the row
 * records, `completed` for work that finished and `unassigned` for work
 * taken away.
 */
final class EndAssignmentCommand extends Command
{
    use ResolvesDemoModels;

    protected $signature = 'demo:end {assignment} {--complete}';

    protected $description = 'End an active assignment';

    public function handle(): int
    {
        $assignment = $this->assignment($this->requiredArgument('assignment'));

        $ended = $this->option('complete')
            ? $assignment->complete()
            : $this->assignableOf($assignment)->unassign($assignment->role);

        $reason = $ended->ended_reason;

        $this->info($this->describe($ended).' ended: '.($reason instanceof EndReason ? $reason->value : 'unknown'));

        return self::SUCCESS;
    }
}

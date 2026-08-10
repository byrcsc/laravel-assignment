<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Workbench\App\Console\Commands\Concerns\ResolvesDemoModels;

/**
 * The profile-driven path: no candidates, no policy, no scope in the call.
 */
final class AutoAssignCommand extends Command
{
    use ResolvesDemoModels;

    protected $signature = 'demo:auto {model : enquiry or callout} {id? : defaults to the first free one} {--role=}';

    protected $description = 'Assign through the registered profile';

    public function handle(): int
    {
        $role = $this->stringOption('role');
        $assignable = $this->assignable($this->requiredArgument('model'), $this->optionalArgument('id'), $role);

        $assignment = $assignable->autoAssign($role);

        if ($assignment === null) {
            $entry = $assignable->queuedAssignment($role);

            $this->warn($entry === null
                ? 'No candidates, and the profile does not queue this one.'
                : "No candidates. Queued at priority {$entry->priority}.");

            return self::SUCCESS;
        }

        $this->info($assignment->status->value.': '.$this->describe($assignment));

        return self::SUCCESS;
    }
}

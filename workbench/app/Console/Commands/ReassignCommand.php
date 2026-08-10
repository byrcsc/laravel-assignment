<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Workbench\App\Console\Commands\Concerns\ResolvesDemoModels;
use Workbench\App\Support\Roster;

final class ReassignCommand extends Command
{
    use ResolvesDemoModels;

    protected $signature = 'demo:reassign {model : enquiry or callout} {id} {--role=}';

    protected $description = 'Hand an open slot to a different assignee';

    public function handle(): int
    {
        $role = $this->stringOption('role');
        $assignable = $this->assignable($this->requiredArgument('model'), $this->requiredArgument('id'), $role);
        $current = $assignable->openAssignment($role);

        if ($current === null) {
            $this->error('That slot is empty. Assign it before reassigning it.');

            return self::FAILURE;
        }

        $replacement = Roster::excluding($assignable, $role, $current->assignee_id)->first();

        if ($replacement === null) {
            $this->error('There is nobody else to hand it to.');

            return self::FAILURE;
        }

        $this->info($this->describe($assignable->reassign($replacement, $role)));

        return self::SUCCESS;
    }
}

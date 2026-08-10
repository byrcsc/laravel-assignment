<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Workbench\App\Console\Commands\Concerns\ResolvesDemoModels;

/**
 * Backdates `expires_at` so expiry can be watched without waiting out the
 * TTL. Nothing else is written: the offer stops holding its slot the moment
 * the timestamp passes, and `assignment:tick` advances the cascade after.
 */
final class AgeOfferCommand extends Command
{
    use ResolvesDemoModels;

    protected $signature = 'demo:age-offer {assignment}';

    protected $description = 'Backdate an offer expiry so the tick has work to do';

    public function handle(): int
    {
        $assignment = $this->assignment($this->requiredArgument('assignment'));

        if (! $assignment->isOffered()) {
            $this->error('Only an open offer can be aged.');

            return self::FAILURE;
        }

        $assignment->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->info($this->describe($assignment).' is now overdue. Run assignment:tick.');
        $this->line('The slot is already free: '.($assignment->isOpen() ? 'no' : 'yes'));

        return self::SUCCESS;
    }
}

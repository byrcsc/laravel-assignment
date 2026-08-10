<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Workbench\App\Console\Commands\Concerns\ResolvesDemoModels;
use Workbench\App\Models\Crew;
use Workbench\App\Models\Tradie;

/**
 * Eligibility belongs to the application, so the demo needs a way to change
 * it. Taking every tradie off the tools is what makes the queue reachable
 * through a profile: no candidates, so the enquiry waits instead of failing.
 */
final class AvailabilityCommand extends Command
{
    use ResolvesDemoModels;

    protected $signature = 'demo:availability {who : tradies or crews} {state : on or off} {--trade=} {--suburb=}';

    protected $description = 'Make assignees available or unavailable so eligibility has something to filter';

    public function handle(): int
    {
        $available = match ($this->requiredArgument('state')) {
            'on' => true,
            'off' => false,
            default => throw new InvalidArgumentException('The state must be on or off.'),
        };

        $who = $this->requiredArgument('who');
        $trade = $this->stringOption('trade');
        $suburb = $this->stringOption('suburb');

        $changed = match ($who) {
            'tradies' => Tradie::query()
                ->when($trade !== null, fn ($query) => $query->where('trade', $trade))
                ->update(['available' => $available]),
            'crews' => Crew::query()
                ->when($suburb !== null, fn ($query) => $query->where('suburb', $suburb))
                ->update(['available' => $available]),
            default => throw new InvalidArgumentException('Choose tradies or crews.'),
        };

        $this->info(sprintf('%d %s are now %s.', $changed, $who, $available ? 'available' : 'unavailable'));

        return self::SUCCESS;
    }
}

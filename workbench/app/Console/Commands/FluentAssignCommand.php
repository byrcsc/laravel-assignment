<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use ByRcsc\LaravelAssignment\Facades\Assignment;
use ByRcsc\LaravelAssignment\Policies\Random;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Workbench\App\Console\Commands\Concerns\ResolvesDemoModels;
use Workbench\App\Models\Tradie;
use Workbench\App\Support\Roster;

/**
 * The builder path, and the only place the demo drives `Random`, a closure
 * policy, `by()`, and `queue()`.
 */
final class FluentAssignCommand extends Command
{
    use ResolvesDemoModels;

    protected $signature = 'demo:fluent {model : enquiry or callout} {id? : defaults to the first free one}
        {--role=} {--policy=random : random or closure} {--offer} {--queue}';

    protected $description = 'Assign, offer, or queue through the fluent builder';

    public function handle(): int
    {
        $role = $this->stringOption('role');
        $type = $this->requiredArgument('model');
        $assignable = $this->assignable($type, $this->optionalArgument('id'), $role);

        $pending = Assignment::for($assignable)
            ->among(Roster::for($assignable, $role))
            ->role($role)
            ->scope('fluent:'.$type)
            ->using($this->policy())
            ->by($this->actor());

        if ($this->option('queue')) {
            $entry = $pending->queue(priority: 5);

            $this->info("Queued {$type} {$entry->assignable_id} at priority {$entry->priority}.");

            return self::SUCCESS;
        }

        $assignment = $this->option('offer')
            ? $pending->expiresIn(60)->offer()
            : $pending->assign();

        if ($assignment === null) {
            $this->warn('The candidate list was empty, so nothing was written.');

            return self::SUCCESS;
        }

        $this->info($assignment->status->value.': '.$this->describe($assignment));

        return self::SUCCESS;
    }

    private function policy(): string|Closure
    {
        return $this->stringOption('policy') === 'closure'
            ? $this->firstByName(...)
            : Random::class;
    }

    /**
     * A closure policy receives the assignable and the candidates, and
     * returns one of them or null.
     *
     * @param  Collection<int, Model>  $candidates
     */
    private function firstByName(Model $assignable, Collection $candidates): ?Model
    {
        return $candidates->sortBy('name')->first();
    }

    /**
     * Any model can be the actor. The demo uses a tradie so `assigned_by`
     * holds something recognisable.
     */
    private function actor(): ?Tradie
    {
        return Tradie::query()->orderBy('created_at')->first();
    }
}

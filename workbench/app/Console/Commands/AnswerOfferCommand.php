<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use ByRcsc\LaravelAssignment\Models\Assignment;
use Illuminate\Console\Command;
use Workbench\App\Console\Commands\Concerns\ResolvesDemoModels;

/**
 * Only the targeted assignee may answer, so the answer goes through the
 * assignee model rather than through the assignment.
 */
final class AnswerOfferCommand extends Command
{
    use ResolvesDemoModels;

    protected $signature = 'demo:answer {assignment} {--decline}';

    protected $description = 'Accept or decline an open offer';

    public function handle(): int
    {
        $offer = $this->assignment($this->requiredArgument('assignment'));
        $assignee = $this->assigneeOf($offer);

        $answered = $this->option('decline')
            ? $assignee->decline($offer)
            : $assignee->accept($offer);

        $this->info($this->describe($answered).' is now '.$answered->status->value
            .($answered->ended_reason === null ? '' : ' ('.$answered->ended_reason->value.')'));

        if ($this->option('decline')) {
            $this->cascadeHint($answered);
        }

        return self::SUCCESS;
    }

    /**
     * A decline cascades in the same request, so the next offer already
     * exists by the time this runs.
     */
    private function cascadeHint(Assignment $declined): void
    {
        $next = $this->assignableOf($declined)->openAssignment($declined->role);

        $this->line($next === null
            ? 'The cascade found nobody else. Check demo:status for a queue entry.'
            : 'The cascade offered it on: '.$this->describe($next));
    }
}

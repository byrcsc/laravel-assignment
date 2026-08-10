<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use ByRcsc\LaravelAssignment\Models\Assignment;
use ByRcsc\LaravelAssignment\Models\QueuedAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Workbench\App\Console\Commands\Concerns\ResolvesDemoModels;
use Workbench\App\Models\Crew;
use Workbench\App\Models\Tradie;

final class StatusCommand extends Command
{
    use ResolvesDemoModels;

    protected $signature = 'demo:status';

    protected $description = 'Show every open assignment, the queue, and the workload spread';

    public function handle(): int
    {
        $this->openAssignments();
        $this->queue();
        $this->workload();
        $this->notifications();

        return self::SUCCESS;
    }

    private function openAssignments(): void
    {
        $rows = Assignment::query()->open()->orderBy('id')->get()->map(fn (Assignment $assignment): array => [
            $this->readable($assignment->getKey()),
            class_basename($assignment->assignable_type).' '.$assignment->assignable_id,
            $this->assigneeName($assignment->assignee),
            $assignment->role ?? '-',
            $assignment->status->value,
            $assignment->isOffered() ? ($assignment->expires_at?->diffForHumans() ?? 'never') : '-',
        ])->all();

        $this->components->twoColumnDetail('<fg=cyan>Open assignments</>', (string) count($rows));
        $this->table(['id', 'assignable', 'assignee', 'role', 'status', 'expires'], $rows);
    }

    private function queue(): void
    {
        $rows = QueuedAssignment::query()
            ->orderByDesc('priority')
            ->orderBy('queued_at')
            ->get()
            ->map(fn (QueuedAssignment $entry): array => [
                class_basename($entry->assignable_type).' '.$entry->assignable_id,
                $entry->role ?? '-',
                (string) $entry->priority,
                $entry->queued_at->diffForHumans(),
            ])->all();

        $this->components->twoColumnDetail('<fg=cyan>Queue</>', (string) count($rows));

        if ($rows !== []) {
            $this->table(['assignable', 'role', 'priority', 'queued'], $rows);
        }
    }

    /**
     * Open counts active plus unanswered offers, which is what a capacity cap
     * would be filtering on. Active is what the assignee is actually holding.
     */
    private function workload(): void
    {
        $assignees = [
            ...Tradie::query()->orderBy('name')->get()->all(),
            ...Crew::query()->orderBy('name')->get()->all(),
        ];

        $rows = array_map(static fn (Tradie|Crew $assignee): array => [
            class_basename($assignee),
            $assignee->name,
            (string) $assignee->workload(),
            (string) $assignee->activeAssignments()->count(),
        ], $assignees);

        $this->components->twoColumnDetail('<fg=cyan>Workload</>', 'open, then active');
        $this->table(['type', 'name', 'open', 'active'], $rows);
    }

    private function notifications(): void
    {
        $this->components->twoColumnDetail(
            '<fg=cyan>Database notifications</>',
            (string) DB::table('notifications')->count(),
        );
        $this->line("Mail goes to the log: tail the skeleton's storage/logs/laravel.log.");
    }
}

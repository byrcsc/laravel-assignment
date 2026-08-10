<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands\Concerns;

use ByRcsc\LaravelAssignment\Models\Assignment;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Workbench\App\Models\Callout;
use Workbench\App\Models\Crew;
use Workbench\App\Models\Enquiry;
use Workbench\App\Models\Tradie;

trait ResolvesDemoModels
{
    /**
     * Resolves `enquiry` or `callout` by id, or the first one whose slot is
     * still free when no id is given, so the demo can be driven without
     * copying keys around.
     */
    protected function assignable(string $type, ?string $id, ?string $role): Enquiry|Callout
    {
        $query = match ($type) {
            'enquiry' => Enquiry::query(),
            'callout' => Callout::query(),
            default => throw new InvalidArgumentException("Unknown demo model [{$type}]. Use enquiry or callout."),
        };

        if ($id !== null) {
            return $query->findOrFail($id);
        }

        $free = $query->orderBy('id')->get()->first(
            fn (Enquiry|Callout $candidate): bool => $candidate->openAssignment($role) === null
                && $candidate->queuedAssignment($role) === null,
        );

        if ($free === null) {
            throw new InvalidArgumentException("Every {$type} already holds that slot or is queued for it.");
        }

        return $free;
    }

    protected function assignment(string $id): Assignment
    {
        return Assignment::query()->findOrFail($id);
    }

    /** The assignable an assignment points at, back in a type the demo knows. */
    protected function assignableOf(Assignment $assignment): Enquiry|Callout
    {
        $assignable = $assignment->assignable;

        if (! $assignable instanceof Enquiry && ! $assignable instanceof Callout) {
            throw new InvalidArgumentException('That assignment points at something the demo does not model.');
        }

        return $assignable;
    }

    /** The assignee an assignment points at, back in a type the demo knows. */
    protected function assigneeOf(Assignment $assignment): Tradie|Crew
    {
        $assignee = $assignment->assignee;

        if (! $assignee instanceof Tradie && ! $assignee instanceof Crew) {
            throw new InvalidArgumentException('That assignment points at an assignee the demo does not model.');
        }

        return $assignee;
    }

    protected function requiredArgument(string $name): string
    {
        $value = $this->argument($name);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The [{$name}] argument is required.");
        }

        return $value;
    }

    protected function optionalArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function assigneeName(?Model $assignee): string
    {
        return $this->readable($assignee?->getAttribute('name'));
    }

    /** Model keys and morph ids are `mixed` to static analysis; output is not. */
    protected function readable(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : 'unknown';
    }

    protected function describe(Assignment $assignment): string
    {
        return sprintf(
            '#%s %s %s -> %s (%s)',
            $this->readable($assignment->getKey()),
            class_basename($assignment->assignable_type),
            $this->readable($assignment->assignable_id),
            $this->assigneeName($assignment->assignee),
            $assignment->role ?? 'no role',
        );
    }
}

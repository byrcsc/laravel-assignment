<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Workbench\App\Models\Callout;
use Workbench\App\Models\Crew;
use Workbench\App\Models\Enquiry;
use Workbench\App\Models\Tradie;

/**
 * Who is eligible, in one place. The engine never asks this question, so the
 * demo has to answer it the way an application would: as a query, expressed
 * once and called from both the profiles and the drive commands.
 */
final class Roster
{
    /**
     * The candidates for one slot. A callout's crew slot wants available
     * crews in the same suburb, its supervisor slot wants any available
     * tradie, and an enquiry wants the tradies who work that trade.
     *
     * @return Collection<int, Tradie|Crew>
     */
    public static function for(Enquiry|Callout $assignable, ?string $role): Collection
    {
        $candidates = match (true) {
            $assignable instanceof Callout && $role !== 'supervisor' => self::crewsIn($assignable->suburb),
            $assignable instanceof Callout => self::tradies(),
            default => self::tradiesFor($assignable->trade),
        };

        // Widened to the union deliberately: a candidate list is a list of
        // assignees, and the two demo slots disagree about which class that
        // is.
        return $candidates->map(static fn (Tradie|Crew $candidate): Tradie|Crew => $candidate);
    }

    /** @return Collection<int, Tradie> */
    public static function tradies(): Collection
    {
        return Tradie::query()->where('available', true)->orderBy('grade')->get();
    }

    /** @return Collection<int, Tradie> */
    public static function tradiesFor(string $trade): Collection
    {
        return Tradie::query()
            ->where('available', true)
            ->where('trade', $trade)
            ->orderBy('grade')
            ->get();
    }

    /** @return Collection<int, Crew> */
    public static function crewsIn(string $suburb): Collection
    {
        return Crew::query()
            ->where('suburb', $suburb)
            ->where('available', true)
            ->get();
    }

    /**
     * The same list with one assignee removed, for handing a slot on to
     * somebody else. Morph ids are `mixed` to static analysis, so a
     * non-scalar one excludes nobody rather than excluding everybody.
     *
     * @return Collection<int, Tradie|Crew>
     */
    public static function excluding(Enquiry|Callout $assignable, ?string $role, mixed $assigneeId): Collection
    {
        $candidates = self::for($assignable, $role);

        if (! is_scalar($assigneeId)) {
            return $candidates;
        }

        return $candidates->reject(
            fn (Model $candidate): bool => self::key($candidate) === (string) $assigneeId,
        )->values();
    }

    private static function key(Model $model): ?string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : null;
    }
}

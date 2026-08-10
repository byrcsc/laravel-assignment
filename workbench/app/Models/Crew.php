<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use ByRcsc\LaravelAssignment\Concerns\Assignee;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\CrewFactory;

/**
 * A crew is a team, not a person, and it takes assignments the same way a
 * tradie does. Two assignee classes in one demo is the point: the engine
 * cares that a candidate is a model, not which model it is.
 *
 * @property string $name
 * @property string $email
 * @property string $suburb
 * @property bool $available
 */
final class Crew extends Model
{
    use Assignee, HasUuids, Notifiable;

    /** @use HasFactory<CrewFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'available' => 'boolean',
    ];

    protected static function newFactory(): CrewFactory
    {
        return CrewFactory::new();
    }
}

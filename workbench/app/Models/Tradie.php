<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use ByRcsc\LaravelAssignment\Concerns\Assignee;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\TradieFactory;

/**
 * Assignees are uuid keyed on purpose: the demo is the only place the
 * `ASSIGNMENT_ASSIGNEE_KEY_TYPE` column type is exercised end to end.
 *
 * @property string $name
 * @property string $email
 * @property string $trade
 * @property bool $available
 * @property int $grade
 */
final class Tradie extends Model
{
    use Assignee, HasUuids, Notifiable;

    /** @use HasFactory<TradieFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'available' => 'boolean',
        'grade' => 'integer',
    ];

    protected static function newFactory(): TradieFactory
    {
        return TradieFactory::new();
    }
}

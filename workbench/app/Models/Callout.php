<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use ByRcsc\LaravelAssignment\Concerns\Assignable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\CalloutFactory;

/**
 * @property string $reference
 * @property string $suburb
 */
final class Callout extends Model
{
    use Assignable;

    /** @use HasFactory<CalloutFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): CalloutFactory
    {
        return CalloutFactory::new();
    }
}

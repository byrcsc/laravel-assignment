<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use ByRcsc\LaravelAssignment\Concerns\Assignable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\EnquiryFactory;

/**
 * @property string $customer
 * @property string $trade
 * @property bool $emergency
 */
final class Enquiry extends Model
{
    use Assignable;

    /** @use HasFactory<EnquiryFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'emergency' => 'boolean',
    ];

    protected static function newFactory(): EnquiryFactory
    {
        return EnquiryFactory::new();
    }
}

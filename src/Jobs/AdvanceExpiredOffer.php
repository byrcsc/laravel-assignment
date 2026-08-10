<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Jobs;

use ByRcsc\LaravelAssignment\Actions\OfferCascade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/** @internal */
final class AdvanceExpiredOffer implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public readonly int|string $assignmentId) {}

    public function handle(OfferCascade $cascades): void
    {
        $cascades->expire($this->assignmentId);
    }
}

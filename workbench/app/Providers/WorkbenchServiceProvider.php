<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Console\Commands\AgeOfferCommand;
use Workbench\App\Console\Commands\AnswerOfferCommand;
use Workbench\App\Console\Commands\AutoAssignCommand;
use Workbench\App\Console\Commands\AvailabilityCommand;
use Workbench\App\Console\Commands\EndAssignmentCommand;
use Workbench\App\Console\Commands\FluentAssignCommand;
use Workbench\App\Console\Commands\FlushQueueCommand;
use Workbench\App\Console\Commands\ReassignCommand;
use Workbench\App\Console\Commands\StatusCommand;
use Workbench\App\Models\Callout;
use Workbench\App\Models\Enquiry;
use Workbench\App\Profiles\CalloutProfile;
use Workbench\App\Profiles\EnquiryProfile;

/**
 * The one file that stands in for an application's own service provider. A
 * real application publishes `config/assignment.php` and edits it; the demo
 * has no published config to edit, so the same values are set here.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'assignment.assignee_key_type' => 'uuid',
            'assignment.profiles' => [
                Enquiry::class => EnquiryProfile::class,
                Callout::class => CalloutProfile::class,
            ],
            // The demo ships a notifications table, so it can watch the
            // database channel as well as the log.
            'assignment.notification_channels' => ['mail', 'database'],
            // Flip to true, and QUEUE_CONNECTION off `sync`, to watch the
            // delayed expiry jobs instead of the tick. It is a literal rather
            // than an `ASSIGNMENT_*` variable because `workbench/.env` is
            // copied into the skeleton the package test suite also boots
            // against, and that suite asserts the shipped defaults.
            'assignment.dispatch_expiry_jobs' => false,
        ]);
    }

    public function boot(): void
    {
        $this->commands([
            StatusCommand::class,
            AutoAssignCommand::class,
            AvailabilityCommand::class,
            FluentAssignCommand::class,
            AnswerOfferCommand::class,
            ReassignCommand::class,
            EndAssignmentCommand::class,
            FlushQueueCommand::class,
            AgeOfferCommand::class,
        ]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('assignment:tick')->everyMinute();
        });
    }
}

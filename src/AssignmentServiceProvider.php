<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment;

use ByRcsc\LaravelAssignment\Commands\AssignmentTickCommand;
use ByRcsc\LaravelAssignment\Events\Assigned;
use ByRcsc\LaravelAssignment\Events\AssignmentOffered;
use ByRcsc\LaravelAssignment\Events\OfferExpired;
use ByRcsc\LaravelAssignment\Events\Reassigned;
use ByRcsc\LaravelAssignment\Events\Unassigned;
use ByRcsc\LaravelAssignment\Listeners\AssignmentNotificationListener;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class AssignmentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-assignment')
            ->hasConfigFile('assignment')
            ->hasMigration('create_assignments_table')
            ->hasTranslations()
            ->hasViews('assignment')
            ->hasCommand(AssignmentTickCommand::class);
    }

    public function packageBooted(): void
    {
        Event::listen(AssignmentOffered::class, [AssignmentNotificationListener::class, 'offered']);
        Event::listen(OfferExpired::class, [AssignmentNotificationListener::class, 'expired']);
        Event::listen(Assigned::class, [AssignmentNotificationListener::class, 'assigned']);
        Event::listen(Unassigned::class, [AssignmentNotificationListener::class, 'unassigned']);
        Event::listen(Reassigned::class, [AssignmentNotificationListener::class, 'reassigned']);
    }
}

<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment;

use ByRcsc\LaravelAssignment\Commands\AssignmentTickCommand;
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
            ->hasCommand(AssignmentTickCommand::class);
    }
}

<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class AssignmentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        // The `assignment:tick` command registers here when it lands
        // (issue D2).
        $package
            ->name('laravel-assignment')
            ->hasConfigFile('assignment')
            ->hasMigration('create_assignments_table');
    }
}

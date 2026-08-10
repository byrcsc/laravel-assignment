<?php

declare(strict_types=1);

use ByRcsc\LaravelAssignment\AssignmentServiceProvider;
use Illuminate\Support\ServiceProvider;

it('registers the config file', function (): void {
    // The keys land with issue A2; an array (rather than null) proves the
    // file is registered and loaded.
    expect(config('assignment'))->toBeArray();
});

it('publishes the config under the assignment-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(AssignmentServiceProvider::class, 'assignment-config');

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $source => $destination) {
        expect($source)->toEndWith('config/assignment.php');
    }
});

it('publishes the migration under the assignment-migrations tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(AssignmentServiceProvider::class, 'assignment-migrations');

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $source => $destination) {
        expect($source)->toEndWith('create_assignments_table.php.stub')
            ->and($destination)->toEndWith('create_assignments_table.php');
    }
});

it('publishes notification translations and mail views', function (): void {
    $translations = ServiceProvider::pathsToPublish(AssignmentServiceProvider::class, 'assignment-translations');
    $views = ServiceProvider::pathsToPublish(AssignmentServiceProvider::class, 'assignment-views');

    expect($translations)->not->toBeEmpty()
        ->and($views)->not->toBeEmpty();
});

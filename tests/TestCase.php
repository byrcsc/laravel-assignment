<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests;

use ByRcsc\LaravelAssignment\AssignmentServiceProvider;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AssignmentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->databaseConnection());
    }

    /**
     * Which engine the suite runs against. SQLite in memory is the default;
     * CI's database matrix sets `DB_DRIVER` to exercise row locking, native
     * column types, and production index behavior absent from SQLite.
     *
     * @return array<string, mixed>
     */
    protected function databaseConnection(): array
    {
        return match (env('DB_DRIVER', 'sqlite')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'assignment_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'assignment_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', 'postgres'),
                'charset' => 'utf8',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }

    protected function defineDatabaseMigrations(): void
    {
        // A memory database is born empty; a server database has to be put
        // back to that state before each test builds its schema.
        if (env('DB_DRIVER', 'sqlite') !== 'sqlite') {
            Schema::dropAllTables();
        }

        $this->runAssignmentMigrations();
    }

    /**
     * Loading the real stub is the point: a suite that builds its own schema
     * proves nothing about the one that ships.
     */
    protected function runAssignmentMigrations(): void
    {
        $migration = require __DIR__.'/../database/migrations/create_assignments_table.php.stub';

        $migration->up();
    }
}

<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The one file that stands in for an application's own service provider. Demo
 * wiring (models, profiles, listeners, commands) lands here with issue F2.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}

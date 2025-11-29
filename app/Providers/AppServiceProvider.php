<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\TaskProgress;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Observers\ProjectObserver;
use App\Observers\TaskProgressObserver;
use App\Observers\TaskTemplateObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set Carbon locale to Indonesian for date/time formatting
        Carbon::setLocale(config('app.locale'));

        // Automatically clone task templates when a project is created
        Project::observe(ProjectObserver::class);

        // Automatically backfill progress with S-curve on first entry
        TaskProgress::observe(TaskProgressObserver::class);

        // Log changes to task templates for audit trail
        TaskTemplate::observe(TaskTemplateObserver::class);

        // Gate: Only Admin and Kodim Admin can access the map
        Gate::define('access-map', function (User $user) {
            return $user->isAdmin() || $user->hasRole('Kodim Admin');
        });
    }
}

<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;

use Illuminate\Support\Facades\URL;
// use Spatie\Backup\Events\BackupWasSuccessful; // Not needed anymore
// use App\Listeners\SetBackupPermissions; // Not needed anymore
use Illuminate\Support\Facades\Event;

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
        Blade::anonymousComponentPath(base_path('resources/views/general/components'));    }
}

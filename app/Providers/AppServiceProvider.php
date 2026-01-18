<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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

                if (!$this->app->runningInConsole()) {

                    config(['app.url' => request()->getSchemeAndHttpHost()]);

                }

        

                // if (config('app.env') !== 'local' || str_contains(config('app.url'), 'https')) {

                //     URL::forceScheme('https');

                // }

            }

}

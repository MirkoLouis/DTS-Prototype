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

        // Explicitly register the listener for debugging purposes

        // Event::listen(BackupWasSuccessful::class, SetBackupPermissions::class); // Not needed anymore



        // Only run this check if we are not in production

        if (config('app.env') !== 'production') {

            // Get the current host (e.g. 'localhost' or 'your-tunnel.trycloudflare.com')

            $host = request()->getHost();



            // If the host is NOT localhost, assume it's the tunnel and force HTTPS

            if (!in_array($host, ['localhost', '127.0.0.1'])) {

                URL::forceScheme('https');

            }

        }

    }

}

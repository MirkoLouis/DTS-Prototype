<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DTSMigrate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dts:migrate {--devseed : Run fresh migration with development/dummy data} {--prodseed : Run fresh migration with production data only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a fresh migration with environment-specific seeders (Production vs. Development)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->option('devseed') && !$this->option('prodseed')) {
            $this->error('Please specify either --devseed or --prodseed.');
            return 1;
        }

        if ($this->option('devseed') && $this->option('prodseed')) {
            $this->error('You cannot use both --devseed and --prodseed at the same time.');
            return 1;
        }

        $seeder = $this->option('devseed') ? 'DevelopmentSeeder' : 'ProductionSeeder';
        
        $this->info("Running php artisan migrate:fresh --seed --seeder={$seeder}...");

        $exitCode = Artisan::call('migrate:fresh', [
            '--seed' => true,
            '--seeder' => $seeder,
            '--force' => $this->option('prodseed'), // Force in production mode
        ], $this->output);

        if ($exitCode === 0) {
            $this->info("Clearing application cache...");
            Artisan::call('cache:clear');

            $this->info("Backfilling performance metrics for optimized dashboards...");
            Artisan::call('dts:backfill-metrics', ['--fresh' => true]);

            $this->info("Database successfully initialized for " . ($this->option('devseed') ? 'DEVELOPMENT' : 'PRODUCTION') . ".");
        }

        return $exitCode;
    }
}

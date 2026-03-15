<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TuneDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dts:tune-db';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Injects high-performance RAM settings into MySQL globals for 1M record scaling.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Tuning database for 1,000,000 document load...');

        try {
            // Set Buffer Pool to 4GB
            DB::statement("SET GLOBAL innodb_buffer_pool_size = 4294967296;");
            $this->comment('✔ InnoDB Buffer Pool set to 4GB (RAM)');

            // Set Log File Size to 1GB (Best effort, might require restart on some systems)
            try {
                DB::statement("SET GLOBAL innodb_log_file_size = 1073741824;");
                $this->comment('✔ InnoDB Log File Size set to 1GB (Performance)');
            } catch (\Exception $e) {
                $this->warn('⚠ Note: Log file size tuning skipped (requires restart on this MySQL version).');
            }

            $this->info('Database tuning successful!');
        } catch (\Exception $e) {
            $this->error('Failed to tune database: ' . $e->getMessage());
            $this->line('Suggestion: Run "sudo service mysql restart" after editing my.cnf manually.');
        }
    }
}

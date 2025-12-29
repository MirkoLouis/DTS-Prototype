<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Document;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PrunePendingDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:prune-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune pending documents older than two weeks.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Pruning pending documents older than two weeks...');

        $cutoffDate = Carbon::now()->subWeeks(2);

        $documentsToDelete = Document::where('status', 'pending')
                                     ->where('created_at', '<', $cutoffDate);

        $count = $documentsToDelete->count();

        if ($count > 0) {
            $documentsToDelete->delete();
            $message = "Successfully pruned {$count} pending document(s) older than two weeks.";
            $this->info($message);
            Log::info($message);
        } else {
            $message = 'No pending documents older than two weeks to prune.';
            $this->info($message);
            Log::info($message);
        }

        return 0;
    }
}

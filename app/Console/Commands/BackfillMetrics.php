<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dts:backfill-metrics {--fresh : Truncate the metrics table before starting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate denormalized columns and reconstruct the daily_department_metrics table from historical logs.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('fresh')) {
            $this->info('Truncating daily_department_metrics...');
            DB::table('daily_department_metrics')->truncate();
        }

        $this->info('Step 1/2: Populating denormalized columns in the documents table...');
        
        $totalDocs = Document::count();
        $progressBar = $this->output->createProgressBar($totalDocs);
        $progressBar->start();

        $departments = Department::all()->pluck('id', 'name')->toArray();

        Document::chunk(1000, function ($documents) use ($departments, $progressBar) {
            foreach ($documents as $doc) {
                $updateData = [];

                // 1. Resolve current_department_id
                if ($doc->status === 'processing' || $doc->status === 'in_transit' || $doc->status === 'ready_for_release') {
                    $route = $doc->finalized_route ?? [];
                    $stepIndex = ($doc->current_step ?? 1) - 1;
                    
                    if ($stepIndex < count($route)) {
                        $deptName = $route[$stepIndex]['name'];
                        $updateData['current_department_id'] = $departments[$deptName] ?? null;
                    } elseif ($doc->status === 'ready_for_release') {
                        $updateData['current_department_id'] = $departments['Records Unit'] ?? null;
                    }
                }

                // 2. Resolve released_at and released_by_user_id
                if ($doc->status === 'completed') {
                    $lastLog = DocumentLog::where('document_id', $doc->id)
                        ->where('action', 'Document Released')
                        ->latest()
                        ->first();
                    
                    if ($lastLog) {
                        $updateData['released_at'] = $lastLog->created_at;
                        $updateData['released_by_user_id'] = $lastLog->user_id;
                    }
                }

                if (!empty($updateData)) {
                    DB::table('documents')->where('id', $doc->id)->update($updateData);
                }
                
                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Step 2/2: Reconstructing daily_department_metrics table from logs...');

        $totalLogs = DocumentLog::count();
        $logProgressBar = $this->output->createProgressBar($totalLogs);
        $logProgressBar->start();

        $receivedTimes = [];
        $metricsBuffer = []; // Local cache for metrics in the current chunk

        DocumentLog::with(['user'])
            ->orderBy('document_id')
            ->orderBy('created_at')
            ->chunk(2000, function ($logs) use (&$receivedTimes, &$metricsBuffer, $logProgressBar) {
                foreach ($logs as $log) {
                    if (!$log->user || !$log->user->department_id) {
                        $logProgressBar->advance();
                        continue;
                    }

                    $deptId = $log->user->department_id;
                    $date = $log->created_at->toDateString();
                    $key = "{$deptId}_{$date}";

                    if (!isset($metricsBuffer[$key])) {
                        $metricsBuffer[$key] = [
                            'department_id' => $deptId,
                            'date' => $date,
                            'received_count' => 0,
                            'processed_count' => 0,
                            'released_count' => 0,
                            'total_processing_seconds' => 0,
                        ];
                    }

                    // Track Received timestamps for duration calculation
                    if ($log->action === 'Received' || $log->action === 'Ready for Releasing') {
                        $receivedTimes[$log->document_id][$log->user_id] = $log->created_at;
                        $metricsBuffer[$key]['received_count']++;
                    }

                    // Processed actions
                    if ($log->action === 'Processing Complete') {
                        $seconds = 0;
                        if (isset($receivedTimes[$log->document_id][$log->user_id])) {
                            // Ensure absolute difference
                            $seconds = abs($log->created_at->diffInSeconds($receivedTimes[$log->document_id][$log->user_id]));
                            unset($receivedTimes[$log->document_id][$log->user_id]);
                        }
                        
                        $metricsBuffer[$key]['processed_count']++;
                        $metricsBuffer[$key]['total_processing_seconds'] += $seconds;
                    }

                    // Released actions
                    if ($log->action === 'Document Released') {
                        $seconds = 0;
                        if (isset($receivedTimes[$log->document_id][$log->user_id])) {
                            $seconds = abs($log->created_at->diffInSeconds($receivedTimes[$log->document_id][$log->user_id]));
                            unset($receivedTimes[$log->document_id][$log->user_id]);
                        }
                        
                        $metricsBuffer[$key]['released_count']++;
                        $metricsBuffer[$key]['total_processing_seconds'] += $seconds;
                    }

                    $logProgressBar->advance();
                }

                // Flush metrics buffer to DB after each chunk
                if (!empty($metricsBuffer)) {
                    foreach ($metricsBuffer as $data) {
                        DB::table('daily_department_metrics')->upsert(
                            [$data],
                            ['department_id', 'date'],
                            [
                                'received_count' => DB::raw("received_count + {$data['received_count']}"),
                                'processed_count' => DB::raw("processed_count + {$data['processed_count']}"),
                                'released_count' => DB::raw("released_count + {$data['released_count']}"),
                                'total_processing_seconds' => DB::raw("total_processing_seconds + {$data['total_processing_seconds']}"),
                                'updated_at' => now()
                            ]
                        );
                    }
                    $metricsBuffer = []; // Clear for next chunk
                }
                
                // Clear receivedTimes for documents that are definitely finished to save RAM
                // (Since we order by document_id, once doc_id changes, we can clear it)
                // But simpler to just let it grow for 10k docs.
            });

        $logProgressBar->finish();
        $this->newLine();
        $this->info('Backfill complete!');
    }
}

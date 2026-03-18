<?php

namespace App\Jobs;

use App\Models\ReportJob;
use App\Models\User;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use iio\libmergepdf\Merger;
use Throwable;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 1200;

    public $reportJob;
    public $user;
    public $filters;

    public function __construct(ReportJob $reportJob, User $user, array $filters)
    {
        $this->reportJob = $reportJob;
        $this->user = $user;
        $this->filters = $filters;
    }

    public function handle(): void
    {
        ini_set('memory_limit', '2G'); // Give it plenty of room but we will be careful
        set_time_limit(1200); // 20 minutes for very large reports

        $this->reportJob->update(['status' => 'processing', 'progress' => 5]);

        try {
            $query = $this->buildQuery();
            $totalCount = $query->count();
            
            if ($totalCount === 0) {
                throw new \Exception("No documents found for the selected filters.");
            }

            $this->reportJob->update(['total_documents' => $totalCount, 'progress' => 10]);

            $format = $this->filters['format'] ?? 'pdf';
            $filename = 'reports/released-documents-' . $this->reportJob->id . ($format === 'csv' ? '.csv' : '.pdf');

            if ($format === 'csv') {
                $this->generateCsv($query, $filename);
            } else {
                $this->generateMergedPdf($query, $filename, $totalCount);
            }

            $this->reportJob->update([
                'status' => 'completed',
                'progress' => 100,
                'file_path' => $filename,
            ]);

        } catch (Throwable $e) {
            $this->reportJob->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function generateMergedPdf($query, $filename, $totalCount)
    {
        $merger = new Merger();
        $chunkSize = 250; // Balanced batch size for performance and stability
        $processed = 0;
        $chunkIndex = 0;
        $tempFiles = [];

        // Prepare a version of filters WITHOUT the heavy base64 images
        $filtersWithoutImages = $this->filters;
        unset($filtersWithoutImages['chart_load_img'], $filtersWithoutImages['chart_throughput_img'], $filtersWithoutImages['chart_avg_time_img']);

        // Optimization: use chunkById for massive tables
        $query->with(['purpose:id,name'])
              ->select(['id', 'tracking_code', 'title', 'purpose_id', 'district', 'guest_info', 'updated_at'])
              ->orderBy('id', 'asc') 
              ->chunk($chunkSize, function ($documents) use ($merger, &$processed, &$chunkIndex, $totalCount, &$tempFiles, $filtersWithoutImages) {
                  
                  $this->reportJob->refresh();
                  if ($this->reportJob->status === 'cancelled') throw new \Exception("Job cancelled by user.");

                  $currentDocsCount = $documents->count();

                  // Generate the PDF for this batch
                  $pdf = Pdf::loadView('officer.report-pdf', [
                      'releasedDocuments' => $documents,
                      'charts' => ($chunkIndex === 0) ? $this->getCharts() : [],
                      'filters' => ($chunkIndex === 0) ? $this->filters : $filtersWithoutImages,
                      'departmentName' => $this->user->department->name,
                      'isFirstChunk' => ($chunkIndex === 0)
                  ]);

                  $pdf->setPaper('a4', 'landscape');
                  $pdf->setOptions([
                      'isHtml5ParserEnabled' => true,
                      'isRemoteEnabled' => true,
                      'defaultFont' => 'sans-serif',
                      'logOutputFile' => null,
                  ]);
                  
                  // Save chunk to a TEMPORARY FILE on disk to free up RAM
                  $tempPath = tempnam(sys_get_temp_dir(), 'pdf_chunk_');
                  file_put_contents($tempPath, $pdf->output());
                  $merger->addFile($tempPath);
                  $tempFiles[] = $tempPath;

                  // Clear heavy variables from memory immediately
                  unset($pdf, $documents);
                  gc_collect_cycles(); // Force PHP to clean up memory

                  $chunkIndex++;
                  $processed += $currentDocsCount;
                  $percent = 10 + floor(($processed / $totalCount) * 80);
                  $this->reportJob->update(['progress' => $percent]);
              });

        // Merge all temporary files into the final report
        Storage::disk('public')->put($filename, $merger->merge());

        // Cleanup: Delete the temporary chunk files
        foreach ($tempFiles as $tempFile) {
            if (file_exists($tempFile)) @unlink($tempFile);
        }
    }

    private function generateCsv($query, $filename)
    {
        $handle = fopen('php://temp', 'r+');
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($handle, ['Tracking Code', 'Title', 'Purpose', 'District', 'Submitted By', 'Date Released']);

        $query->with(['purpose:id,name'])
              ->select(['id', 'tracking_code', 'title', 'purpose_id', 'district', 'guest_info', 'updated_at'])
              ->orderBy('id', 'asc')
              ->chunk(1000, function ($documents) use ($handle) {
                  foreach ($documents as $doc) {
                      fputcsv($handle, [
                          $doc->tracking_code,
                          $doc->title,
                          $doc->purpose->name,
                          $doc->district,
                          $doc->guest_info['name'] ?? 'N/A',
                          $doc->updated_at->format('Y-m-d h:i A')
                      ]);
                  }
              });

        rewind($handle);
        Storage::disk('public')->put($filename, stream_get_contents($handle));
        fclose($handle);
    }

    private function buildQuery()
    {
        $departmentId = $this->user->department_id;
        $query = Document::query();

        $query->whereHas('logs', function ($q) use ($departmentId) {
            $q->where('action', 'Document Released');
            $q->whereHas('user', function ($userQuery) use ($departmentId) {
                $userQuery->where('department_id', $departmentId);
            });
            
            $year = $this->filters['year'] ?? 'all';
            $month = $this->filters['month'] ?? 'all';
            $day = $this->filters['day'] ?? 'all';

            if ($year !== 'all') $q->whereYear('created_at', $year);
            if ($month !== 'all') $q->whereMonth('created_at', $month);
            if ($day !== 'all') $q->whereDay('created_at', $day);
        });

        $search = $this->filters['search'] ?? null;
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('tracking_code', 'like', '%' . $search . '%')
                  ->orWhere('guest_info->name', 'like', '%' . $search . '%');
            });
        }

        $purposeId = $this->filters['purpose_id'] ?? 'all';
        if ($purposeId !== 'all') {
            $query->where('purpose_id', $purposeId);
        }

        $submitter = $this->filters['submitter'] ?? null;
        if ($submitter) {
            $query->whereRaw('LOWER(json_unquote(json_extract(guest_info, "$.name"))) LIKE ?', ['%' . strtolower($submitter) . '%']);
        }

        return $query;
    }

    private function getCharts()
    {
        if ($this->filters['include_charts'] ?? false) {
            return [
                'load' => $this->filters['chart_load_img'] ?? null,
                'avg_time' => $this->filters['chart_avg_time_img'] ?? null,
                'throughput' => $this->filters['chart_throughput_img'] ?? null,
            ];
        }
        return [];
    }
}

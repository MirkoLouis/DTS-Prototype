<?php

namespace App\Jobs;

use App\Core\Database;

class GenerateReportJob
{
    protected $reportJobId;
    protected $userId;
    protected $filters;

    public function __construct(string $reportJobId, int $userId, array $filters = [])
    {
        $this->reportJobId = $reportJobId;
        $this->userId = $userId;
        $this->filters = $filters;
    }

    public function handle(): void
    {
        ini_set('memory_limit', '2G');
        set_time_limit(1200);

        $db = Database::getInstance();
        $db->query("UPDATE report_jobs SET status = 'processing', progress = 5, updated_at = NOW() WHERE id = :id", ['id' => $this->reportJobId]);

        try {
            // Simplified CSV generation for the raw PHP port since DomPDF/libmergepdf are large dependencies
            $format = $this->filters['format'] ?? 'csv';
            
            $userDept = $db->query("SELECT department_id FROM users WHERE id = :uid", ['uid' => $this->userId])->fetch();
            $departmentId = $userDept['department_id'] ?? 0;

            // Simple CSV generation query
            $sql = "
                SELECT d.tracking_code, d.title, p.name as purpose_name, d.district, d.guest_info, d.updated_at
                FROM documents d
                LEFT JOIN purposes p ON d.purpose_id = p.id
                WHERE d.status = 'completed' AND d.released_by_user_id IN (
                    SELECT id FROM users WHERE department_id = :deptId
                )
            ";

            $stmt = $db->query($sql, ['deptId' => $departmentId]);
            $documents = $stmt->fetchAll();

            $totalCount = count($documents);
            if ($totalCount === 0) {
                throw new \Exception("No documents found for the selected filters.");
            }

            $db->query("UPDATE report_jobs SET total_documents = :td, progress = 10, updated_at = NOW() WHERE id = :id", [
                'td' => $totalCount,
                'id' => $this->reportJobId
            ]);

            $filename = 'reports/released-documents-' . $this->reportJobId . '.csv';
            $filePath = BASE_PATH . '/storage/app/' . $filename;
            
            if (!is_dir(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            $handle = fopen($filePath, 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['Tracking Code', 'Title', 'Purpose', 'District', 'Submitted By', 'Date Released']);

            foreach ($documents as $doc) {
                $guestName = 'N/A';
                if ($doc['guest_info']) {
                    $gi = json_decode($doc['guest_info'], true);
                    $guestName = $gi['name'] ?? 'N/A';
                }
                fputcsv($handle, [
                    $doc['tracking_code'],
                    $doc['title'],
                    $doc['purpose_name'],
                    $doc['district'],
                    $guestName,
                    $doc['updated_at']
                ]);
            }

            fclose($handle);

            $db->query("UPDATE report_jobs SET status = 'completed', progress = 100, file_path = :fp, updated_at = NOW() WHERE id = :id", [
                'fp' => $filename,
                'id' => $this->reportJobId
            ]);

        } catch (\Throwable $e) {
            $db->query("UPDATE report_jobs SET status = 'failed', error_message = :err, updated_at = NOW() WHERE id = :id", [
                'err' => $e->getMessage(),
                'id' => $this->reportJobId
            ]);
        }
    }
}

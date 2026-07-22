<?php

namespace App\Jobs;

use App\Core\Database;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Entity\Row;

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
        ini_set('memory_limit', '-1');
        set_time_limit(1200);

        $db = Database::getInstance();
        $db->query("UPDATE report_jobs SET status = 'processing', progress = 5, updated_at = NOW() WHERE id = :id", ['id' => $this->reportJobId]);

        try {
            $userDept = $db->query("SELECT department_id FROM users WHERE id = :uid", ['uid' => $this->userId])->fetch();
            $departmentId = $userDept['department_id'] ?? 0;

            $where = ["d.status = 'completed'", "d.released_by_user_id > 0"];
            $params = [':dept_id' => $departmentId];
            
            $join = "INNER JOIN users u ON d.released_by_user_id = u.id AND u.department_id = :dept_id
                     LEFT JOIN purposes p ON d.purpose_id = p.id";

            if (!empty($this->filters['date'])) {
                $where[] = "DATE(d.released_at) = :date";
                $params[':date'] = $this->filters['date'];
            }
            if (!empty($this->filters['search'])) {
                $where[] = "(d.tracking_code LIKE :search OR json_unquote(json_extract(d.guest_info, '$.name')) LIKE :search2)";
                $params[':search'] = '%' . $this->filters['search'] . '%';
                $params[':search2'] = '%' . $this->filters['search'] . '%';
            }
            if (!empty($this->filters['purpose']) && $this->filters['purpose'] !== 'all') {
                $where[] = "p.name = :purpose";
                $params[':purpose'] = $this->filters['purpose'];
            }
            if (!empty($this->filters['submitter'])) {
                $where[] = "LOWER(json_unquote(json_extract(d.guest_info, '$.name'))) LIKE :submitter";
                $params[':submitter'] = '%' . strtolower($this->filters['submitter']) . '%';
            }

            $whereSql = implode(' AND ', $where);
            $sql = "
                SELECT d.tracking_code, d.title, p.name as purpose_name, d.district, d.guest_info, d.updated_at
                FROM documents d
                {$join}
                WHERE {$whereSql}
                ORDER BY d.released_at DESC
            ";

            $stmt = $db->query($sql, $params);

            // Count rows first to update progress without loading all into memory
            $countSql = "
                SELECT COUNT(*) as total
                FROM documents d
                {$join}
                WHERE {$whereSql}
            ";
            $countStmt = $db->query($countSql, $params);
            $totalCount = (int) $countStmt->fetch()['total'];

            if ($totalCount === 0) {
                throw new \Exception("No documents found for the selected filters.");
            }

            $db->query("UPDATE report_jobs SET total_documents = :td, progress = 10, updated_at = NOW() WHERE id = :id", [
                'td' => $totalCount,
                'id' => $this->reportJobId
            ]);

            $filename = 'reports/released-documents-' . $this->reportJobId . '.xlsx';
            $filePath = BASE_PATH . '/storage/app/' . $filename;
            
            if (!is_dir(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            $writer = new Writer();
            $writer->openToFile($filePath);

            $styleCant = (new Style())->withFontName('Canterbury')->withFontSize(14);
            $styleBold = (new Style())->withFontBold(true);

            $writer->addRow(Row::fromValuesWithStyle(['Republic of the Philippines'], $styleCant));
            $writer->addRow(Row::fromValuesWithStyle(['Department of Education'], $styleCant));
            $writer->addRow(Row::fromValuesWithStyle(['Region X - Northern Mindanao'], $styleBold));
            $writer->addRow(Row::fromValuesWithStyle(['SCHOOLS DIVISION OF ILIGAN CITY'], $styleBold));
            $writer->addRow(Row::fromValues([]));

            $writer->addRow(Row::fromValuesWithStyle(['Tracking Code', 'Title', 'Purpose', 'District', 'Submitted By', 'Date Released'], $styleBold));

            $rowNum = 6;
            
            // Process row by row to conserve memory (OpenSpout naturally streams to disk with almost zero RAM usage)
            while ($doc = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $guestName = 'N/A';
                if ($doc['guest_info']) {
                    $gi = json_decode($doc['guest_info'], true);
                    $guestName = $gi['name'] ?? 'N/A';
                }
                
                $writer->addRow(Row::fromValues([
                    $doc['tracking_code'],
                    $doc['title'],
                    $doc['purpose_name'],
                    $doc['district'],
                    $guestName,
                    $doc['updated_at']
                ]));

                $rowNum++;
                
                // Update progress every 1000 rows
                if (($rowNum - 6) % 1000 === 0) {
                    $progress = 10 + floor((($rowNum - 6) / $totalCount) * 80);
                    $db->query("UPDATE report_jobs SET progress = :p, updated_at = NOW() WHERE id = :id", [
                        'p' => $progress,
                        'id' => $this->reportJobId
                    ]);
                }
            }

            $writer->close();

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

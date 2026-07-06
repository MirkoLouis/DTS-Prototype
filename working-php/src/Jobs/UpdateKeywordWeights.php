<?php

namespace App\Jobs;

use App\Core\Database;

class UpdateKeywordWeights
{
    protected $purposeText;
    protected $finalizedRoute;

    public function __construct(string $purposeText, array $finalizedRoute)
    {
        $this->purposeText = $purposeText;
        $this->finalizedRoute = $finalizedRoute;
    }

    public function handle(): void
    {
        $rawTokens = preg_split('/[\s,.;]+/', strtolower($this->purposeText), -1, PREG_SPLIT_NO_EMPTY);
        $stopWords = ['the', 'and', 'for', 'with', 'n/a', 'na', 'not', 'applicable', 'this', 'that'];
        
        $tokens = array_filter($rawTokens, function($token) use ($stopWords) {
            return strlen($token) > 2 && !in_array($token, $stopWords);
        });

        if (empty($tokens) || empty($this->finalizedRoute)) {
            return;
        }

        $db = Database::getInstance();
        $inQuery = implode(',', array_fill(0, count($this->finalizedRoute), '?'));
        
        $stmt = $db->query("SELECT id, name FROM departments WHERE name IN ($inQuery)", array_values($this->finalizedRoute));
        $departments = [];
        while ($row = $stmt->fetch()) {
            $departments[$row['name']] = $row['id'];
        }

        $processedKeywordsPerDept = [];

        foreach ($this->finalizedRoute as $departmentName) {
            if (isset($departments[$departmentName])) {
                $departmentId = $departments[$departmentName];

                if (!isset($processedKeywordsPerDept[$departmentId])) {
                    $processedKeywordsPerDept[$departmentId] = [];
                }

                foreach ($tokens as $token) {
                    // Update or insert
                    $exists = $db->query("SELECT id FROM prediction_keywords WHERE keyword = :k AND department_id = :d", [
                        'k' => $token,
                        'd' => $departmentId
                    ])->fetch();

                    if ($exists) {
                        $db->query("UPDATE prediction_keywords SET weight = weight + 1 WHERE id = :id", ['id' => $exists['id']]);
                        $keywordId = $exists['id'];
                    } else {
                        $db->query("INSERT INTO prediction_keywords (keyword, department_id, weight, document_count, created_at, updated_at) VALUES (:k, :d, 1, 0, NOW(), NOW())", [
                            'k' => $token,
                            'd' => $departmentId
                        ]);
                        $keywordId = $db->getConnection()->lastInsertId();
                    }

                    if (!in_array($token, $processedKeywordsPerDept[$departmentId])) {
                        $db->query("UPDATE prediction_keywords SET document_count = document_count + 1 WHERE id = :id", ['id' => $keywordId]);
                        $processedKeywordsPerDept[$departmentId][] = $token;
                    }
                }
            }
        }
    }
}

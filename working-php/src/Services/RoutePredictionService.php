<?php

namespace App\Services;

use App\Core\Database;

class RoutePredictionService
{
    /**
     * Predicts a suggested route based on the input text.
     *
     * @param string $inputContext
     * @param string|null $preferredDepartment
     * @return array
     */
    public function predict(string $inputContext, ?string $preferredDepartment = null): array
    {
        $db = Database::getInstance();
        $inputContext = strtolower($inputContext);
        $rawTokens = preg_split('/[\s,.;]+/', $inputContext, -1, PREG_SPLIT_NO_EMPTY);
        
        $stopWords = ['the', 'and', 'for', 'with', 'N/A', 'n/a', 'not', 'applicable', 'this', 'that'];
        
        $tokens = array_filter($rawTokens, function($token) use ($stopWords) {
            return strlen($token) > 2 && !in_array($token, $stopWords);
        });

        if (empty($tokens)) {
            return $preferredDepartment ? [$preferredDepartment] : ['Records Unit'];
        }

        $termFrequencies = array_count_values($tokens);
        $uniqueTokens = array_keys($termFrequencies);

        // Fetch max document_count
        $maxStmt = $db->query("SELECT MAX(document_count) as max_count FROM prediction_keywords");
        $maxRow = $maxStmt->fetch();
        $totalSamples = $maxRow && $maxRow['max_count'] ? $maxRow['max_count'] : 1;

        // Prepare IN clause safely
        $inQuery = implode(',', array_fill(0, count($uniqueTokens), '?'));
        
        $sql = "SELECT departments.name as dept_name, prediction_keywords.keyword, 
                       prediction_keywords.weight, prediction_keywords.document_count 
                FROM prediction_keywords 
                INNER JOIN departments ON prediction_keywords.department_id = departments.id 
                WHERE prediction_keywords.keyword IN ({$inQuery})";
                
        $stmt = $db->query($sql, array_values($uniqueTokens));
        $keywords = $stmt->fetchAll();

        $departmentScores = [];

        foreach ($keywords as $kw) {
            $tf = $termFrequencies[$kw['keyword']];
            $idf = log(($totalSamples + 1) / ($kw['document_count'] + 1));
            
            $score = $tf * $kw['weight'] * $idf;

            if (!isset($departmentScores[$kw['dept_name']])) {
                $departmentScores[$kw['dept_name']] = 0;
            }
            $departmentScores[$kw['dept_name']] += $score;
        }

        arsort($departmentScores);
        $predictedRoute = array_keys($departmentScores);

        if ($preferredDepartment) {
            $predictedRoute = array_values(array_unique(array_merge([$preferredDepartment], $predictedRoute)));
        }

        if (empty($predictedRoute)) {
            $predictedRoute[] = 'Records Unit';
        }

        return $predictedRoute;
    }
}

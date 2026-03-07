<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoutePredictionService
{
    /**
     * Predicts a suggested route based on the purpose text using TF-IDF-inspired logic.
     * Rare keywords (high IDF) have more impact than common words.
     *
     * @param string $purposeText
     * @param string|null $preferredDepartment (Guest-selected department)
     * @return array
     */
    public function predict(string $purposeText, ?string $preferredDepartment = null): array
    {
        $purposeText = strtolower($purposeText);
        // Tokenize and count term frequencies (TF)
        $tokens = preg_split('/[\s,.;]+/', $purposeText, -1, PREG_SPLIT_NO_EMPTY);
        
        if (empty($tokens)) {
            return $preferredDepartment ? [$preferredDepartment] : ['Records Unit'];
        }

        $termFrequencies = array_count_values($tokens);
        $uniqueTokens = array_keys($termFrequencies);

        // Get total number of "learned" documents (sum of distinct document counts across all keywords is a proxy)
        // A better proxy is the max document_count found in the database.
        $totalSamples = DB::table('prediction_keywords')->max('document_count') ?? 1;

        // Fetch keywords with their weights and document counts (for IDF)
        $keywords = DB::table('prediction_keywords')
            ->join('departments', 'prediction_keywords.department_id', '=', 'departments.id')
            ->whereIn('prediction_keywords.keyword', $uniqueTokens)
            ->select(
                'departments.name as dept_name', 
                'prediction_keywords.keyword', 
                'prediction_keywords.weight', 
                'prediction_keywords.document_count'
            )
            ->get();

        $departmentScores = [];

        foreach ($keywords as $kw) {
            $tf = $termFrequencies[$kw->keyword];
            // IDF calculation: log(Total Samples / Document Count of this keyword)
            // Adding 1 to avoid log(0) and division by zero.
            $idf = log(($totalSamples + 1) / ($kw->document_count + 1));
            
            // Score = TF * Weight * IDF
            $score = $tf * $kw->weight * $idf;

            if (!isset($departmentScores[$kw->dept_name])) {
                $departmentScores[$kw->dept_name] = 0;
            }
            $departmentScores[$kw->dept_name] += $score;
        }

        arsort($departmentScores);
        $predictedRoute = array_keys($departmentScores);

        // If a preferred department was selected by the guest, ensure it's at the front
        if ($preferredDepartment) {
            // Remove it from current position and prepended to the start
            $predictedRoute = array_values(array_unique(array_merge([$preferredDepartment], $predictedRoute)));
        }

        // Final fallback
        if (empty($predictedRoute)) {
            $predictedRoute[] = 'Records Unit';
        }

        return $predictedRoute;
    }
}

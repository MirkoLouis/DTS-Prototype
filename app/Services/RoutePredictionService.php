<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoutePredictionService
{
    /**
     * Predicts a suggested route based on the purpose text using a database of weighted keywords.
     *
     * @param string $purposeText
     * @return array
     */
    public function predict(string $purposeText): array
    {
        $purposeText = strtolower($purposeText);
        // Simple tokenization: split by spaces and remove common punctuation.
        $tokens = preg_split('/[\s,.;]+/', $purposeText, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($tokens)) {
            return ['Records']; // Default route if no usable words
        }

        // Find department scores based on keywords found in the text
        $departmentScores = DB::table('prediction_keywords')
            ->join('departments', 'prediction_keywords.department_id', '=', 'departments.id')
            ->whereIn('prediction_keywords.keyword', $tokens)
            ->select('departments.name', DB::raw('SUM(prediction_keywords.weight) as score'))
            ->groupBy('departments.name')
            ->orderByDesc('score')
            ->get();

        $predictedRoute = $departmentScores->pluck('name')->toArray();

        // If no prediction, provide a default route to Records for initial handling
        if (empty($predictedRoute)) {
            $predictedRoute[] = 'Records';
        }

        return $predictedRoute;
    }
}

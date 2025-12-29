<?php

namespace App\Services;

use App\Models\Department;

class RoutePredictionService
{
    /**
     * Predicts a suggested route based on the purpose text.
     *
     * @param string $purposeText
     * @return array
     */
    public function predict(string $purposeText): array
    {
        $predictedRoute = [];
        $purposeText = strtolower($purposeText); // Normalize for easier matching

        // Fetch all department names to match against
        $departmentNames = Department::pluck('name')->map(function ($name) {
            return strtolower($name);
        })->toArray();

        // Simple keyword matching for prototype
        if (str_contains($purposeText, 'records') || str_contains($purposeText, 'document') || str_contains($purposeText, 'request') || str_contains($purposeText, 'copy')) {
            if (in_array('records', $departmentNames)) {
                $predictedRoute[] = 'Records';
            }
        }
        if (str_contains($purposeText, 'hr') || str_contains($purposeText, 'employment') || str_contains($purposeText, 'service') || str_contains($purposeText, 'transfer') || str_contains($purposeText, 'application')) {
            if (in_array('human resources', $departmentNames)) {
                $predictedRoute[] = 'Human Resources';
            }
        }
        if (str_contains($purposeText, 'accounting') || str_contains($purposeText, 'finance') || str_contains($purposeText, 'budget')) {
            if (in_array('accounting', $departmentNames)) {
                $predictedRoute[] = 'Accounting';
            }
        }
        if (str_contains($purposeText, 'legal') || str_contains($purposeText, 'complaint') || str_contains($purposeText, 'grievance')) {
            if (in_array('legal', $departmentNames)) {
                $predictedRoute[] = 'Legal';
            }
        }
        if (str_contains($purposeText, 'superintendent') || str_contains($purposeText, 'office') || str_contains($purposeText, 'approval') || str_contains($purposeText, 'moa') || str_contains($purposeText, 'mou')) {
            if (in_array('office of the superintendent', $departmentNames)) {
                $predictedRoute[] = 'Office of the Superintendent';
            }
        }
        
        // Remove duplicates and maintain order (if any)
        $predictedRoute = array_values(array_unique($predictedRoute));

        // If no prediction, provide a default route to Records for initial handling
        if (empty($predictedRoute)) {
            if (in_array('records', $departmentNames)) {
                $predictedRoute[] = 'Records';
            }
        }

        return $predictedRoute;
    }
}

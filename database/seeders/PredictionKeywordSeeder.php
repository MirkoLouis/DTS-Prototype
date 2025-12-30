<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\PredictionKeyword;

class PredictionKeywordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get department IDs
        $departments = Department::pluck('id', 'name')->all();

        $keywords = [
            // Accounting
            'budget' => ['department' => 'Accounting', 'weight' => 10],
            'financial' => ['department' => 'Accounting', 'weight' => 10],
            'salary' => ['department' => 'Accounting', 'weight' => 8],
            'claim' => ['department' => 'Accounting', 'weight' => 8],
            'reimbursement' => ['department' => 'Accounting', 'weight' => 8],

            // Human Resources
            'leave' => ['department' => 'Human Resources', 'weight' => 10],
            'hiring' => ['department' => 'Human Resources', 'weight' => 10],
            'personnel' => ['department' => 'Human Resources', 'weight' => 9],
            'pds' => ['department' => 'Human Resources', 'weight' => 8],
            'recruitment' => ['department' => 'Human Resources', 'weight' => 10],

            // Legal
            'complaint' => ['department' => 'Legal', 'weight' => 10],
            'legal' => ['department' => 'Legal', 'weight' => 10],
            'affidavit' => ['department' => 'Legal', 'weight' => 9],
            'contract' => ['department' => 'Legal', 'weight' => 8],

            // Records
            'request' => ['department' => 'Records', 'weight' => 5], // Lower weight as it's a general term
            'document' => ['department' => 'Records', 'weight' => 5],
            'file' => ['department' => 'Records', 'weight' => 6],

            // Office of the Superintendent
            'approval' => ['department' => 'Office of the Superintendent', 'weight' => 7],
            'memo' => ['department' => 'Office of the Superintendent', 'weight' => 8],
            'superintendent' => ['department' => 'Office of the Superintendent', 'weight' => 10],
        ];

        foreach ($keywords as $keyword => $data) {
            if (isset($departments[$data['department']])) {
                PredictionKeyword::updateOrCreate(
                    [
                        'keyword' => $keyword,
                        'department_id' => $departments[$data['department']],
                    ],
                    [
                        'weight' => $data['weight'],
                    ]
                );
            }
        }
    }
}

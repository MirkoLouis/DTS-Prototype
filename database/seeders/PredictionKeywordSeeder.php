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
            // Accounting Unit
            'budget' => ['department' => 'Accounting Unit', 'weight' => 10],
            'financial' => ['department' => 'Accounting Unit', 'weight' => 10],
            'salary' => ['department' => 'Accounting Unit', 'weight' => 8],
            'claim' => ['department' => 'Accounting Unit', 'weight' => 8],
            'reimbursement' => ['department' => 'Accounting Unit', 'weight' => 8],

            // Personnel Unit
            'leave' => ['department' => 'Personnel Unit', 'weight' => 10],
            'hiring' => ['department' => 'Personnel Unit', 'weight' => 10],
            'personnel' => ['department' => 'Personnel Unit', 'weight' => 9],
            'pds' => ['department' => 'Personnel Unit', 'weight' => 8],
            'recruitment' => ['department' => 'Personnel Unit', 'weight' => 10],
            'service record' => ['department' => 'Personnel Unit', 'weight' => 10],
            'coe' => ['department' => 'Personnel Unit', 'weight' => 10],

            // Legal Unit
            'complaint' => ['department' => 'Legal Unit', 'weight' => 10],
            'legal' => ['department' => 'Legal Unit', 'weight' => 10],
            'affidavit' => ['department' => 'Legal Unit', 'weight' => 9],
            'contract' => ['department' => 'Legal Unit', 'weight' => 8],

            // Records Unit
            'request' => ['department' => 'Records Unit', 'weight' => 5],
            'document' => ['department' => 'Records Unit', 'weight' => 5],
            'file' => ['department' => 'Records Unit', 'weight' => 6],
            'cav' => ['department' => 'Records Unit', 'weight' => 10],
            'ctc' => ['department' => 'Records Unit', 'weight' => 10],

            // Cash Unit
            'cash' => ['department' => 'Cash Unit', 'weight' => 10],
            'payment' => ['department' => 'Cash Unit', 'weight' => 9],
            'check' => ['department' => 'Cash Unit', 'weight' => 10],
            'payroll' => ['department' => 'Cash Unit', 'weight' => 8],

            // Supply Unit
            'inventory' => ['department' => 'Supply Unit', 'weight' => 10],
            'equipment' => ['department' => 'Supply Unit', 'weight' => 9],
            'procurement' => ['department' => 'Supply Unit', 'weight' => 8],
            'property' => ['department' => 'Supply Unit', 'weight' => 7],

            // Budget Unit
            'allotment' => ['department' => 'Budget Unit', 'weight' => 10],
            'obligation' => ['department' => 'Budget Unit', 'weight' => 10],
            'fund' => ['department' => 'Budget Unit', 'weight' => 8],

            // Health and Nutrition
            'medical' => ['department' => 'Health and Nutrition', 'weight' => 10],
            'dental' => ['department' => 'Health and Nutrition', 'weight' => 10],
            'health' => ['department' => 'Health and Nutrition', 'weight' => 9],
            'nutrition' => ['department' => 'Health and Nutrition', 'weight' => 10],

            // Bids and Awards Committee Unit
            'bidding' => ['department' => 'Bids and Awards Committee Unit', 'weight' => 10],
            'bac' => ['department' => 'Bids and Awards Committee Unit', 'weight' => 10],
            'tender' => ['department' => 'Bids and Awards Committee Unit', 'weight' => 9],

            // Schools Division Superintendent Office
            'approval' => ['department' => 'Schools Division Superintendent Office', 'weight' => 7],
            'memo' => ['department' => 'Schools Division Superintendent Office', 'weight' => 8],
            'superintendent' => ['department' => 'Schools Division Superintendent Office', 'weight' => 10],
            'sds' => ['department' => 'Schools Division Superintendent Office', 'weight' => 10],

            // Curriculum Implementation Division
            'cid' => ['department' => 'Curriculum Implementation Division', 'weight' => 10],
            'learning' => ['department' => 'Curriculum Implementation Division', 'weight' => 9],
            'instructional' => ['department' => 'Curriculum Implementation Division', 'weight' => 9],
            'curriculum' => ['department' => 'Curriculum Implementation Division', 'weight' => 10],

            // School Governance and Operations Division
            'sgod' => ['department' => 'School Governance and Operations Division', 'weight' => 10],
            'governance' => ['department' => 'School Governance and Operations Division', 'weight' => 9],
            'planning' => ['department' => 'School Governance and Operations Division', 'weight' => 8],
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

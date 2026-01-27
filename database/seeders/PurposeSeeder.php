<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Purpose;

class PurposeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $purposes = [
            // Category 1: Records & Certifications
            [
                'name' => 'CAV Request (Certification, Authentication, and Verification)',
                'is_official' => true,
                'requirements' => ['Letter of Request', 'Certified True Copy of School Records', 'PSA Birth Certificate'],
                'suggested_route' => ['Records Unit', 'Schools Division Superintendent Office'],
            ],
            [
                'name' => 'Request for Certified True Copy (CTC)',
                'is_official' => true,
                'requirements' => ['Letter of Request', 'Valid ID'],
                'suggested_route' => ['Records Unit'],
            ],
            [
                'name' => 'Correction of School Entries',
                'is_official' => true,
                'requirements' => ['Affidavit of Discrepancy', 'PSA Birth Certificate', 'Original School Records'],
                'suggested_route' => ['Records Unit', 'Legal Unit'],
            ],

            // Category 2: Employment & HR
            [
                'name' => 'Submission of Application Documents (Ranking)',
                'is_official' => true,
                'requirements' => ['Letter of Intent', 'Personal Data Sheet (PDS)', 'CSC Form 212', 'PRC License/ID', 'Transcript of Records'],
                'suggested_route' => ['Personnel Unit', 'Records Unit'],
            ],
            [
                'name' => 'Request for Service Record',
                'is_official' => true,
                'requirements' => ['Letter of Request', 'Valid ID'],
                'suggested_route' => ['Personnel Unit'],
            ],
            [
                'name' => 'Request for Certificate of Employment (COE)',
                'is_official' => true,
                'requirements' => ['Letter of Request', 'Valid ID'],
                'suggested_route' => ['Personnel Unit'],
            ],

            // Category 3: Research & Data
            [
                'name' => 'Request for Approval to Conduct Study/Survey',
                'is_official' => true,
                'requirements' => ['Letter of Intent to Conduct Study', 'Research Proposal', 'Survey Questionnaire'],
                'suggested_route' => ['Records Unit', 'Schools Division Superintendent Office'],
            ],
            [
                'name' => 'Request for Data/Statistics',
                'is_official' => true,
                'requirements' => ['Letter of Request specifying data needed', 'Valid ID'],
                'suggested_route' => ['Records Unit'],
            ],

            // Category 4: Private School Regulation
            [
                'name' => 'Application for Government Permit/Recognition',
                'is_official' => true,
                'requirements' => ['Application Letter', 'Feasibility Study', 'School Site and Building Plans', 'List of Faculty and Staff'],
                'suggested_route' => ['Records Unit', 'Schools Division Superintendent Office'],
            ],

            // Category 5: Legal & Grievances
            [
                'name' => 'Filing of Administrative Complaint',
                'is_official' => true,
                'requirements' => ['Formal Complaint-Affidavit', 'Supporting Evidence/Documents'],
                'suggested_route' => ['Legal Unit', 'Schools Division Superintendent Office'],
            ],

            // Category 6: External Partnerships & Events
            [
                'name' => 'Proposal for Partnership (MOA/MOU)',
                'is_official' => true,
                'requirements' => ['Letter of Intent for Partnership', 'Draft Memorandum of Agreement/Understanding'],
                'suggested_route' => ['Schools Division Superintendent Office'],
            ],
            // Additional purposes from the current context I was working with.
            // These ensure specific routing for salary processing and retirement claims.
            [
                'name' => 'Processing of First Salary',
                'is_official' => true,
                'requirements' => ['Appointment Paper', 'PSIPOP', 'ATM Form'],
                'suggested_route' => ['Personnel Unit', 'Budget Unit', 'Accounting Unit', 'Cash Unit']
            ],
            [
                'name' => 'Retirement Claims',
                'is_official' => true,
                'requirements' => ['Letter of Intent to Retire', 'Service Record'],
                'suggested_route' => ['Personnel Unit', 'Accounting Unit', 'Schools Division Superintendent Office']
            ]
        ];

        foreach ($purposes as $purpose) {
            Purpose::updateOrCreate(['name' => $purpose['name']], $purpose);
        }
    }
}

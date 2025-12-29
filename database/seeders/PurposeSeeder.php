<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Purpose;

class PurposeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Category 1: Records & Certifications
        Purpose::updateOrCreate(
            ['name' => 'CAV Request (Certification, Authentication, and Verification)'],
            [
                'is_official' => true,
                'requirements' => ['Letter of Request', 'Certified True Copy of School Records', 'PSA Birth Certificate'],
                'suggested_route' => ['Records', 'Office of the Superintendent'],
            ]
        );
        Purpose::updateOrCreate(
            ['name' => 'Request for Certified True Copy (CTC)'],
            [
                'is_official' => true,
                'requirements' => ['Letter of Request', 'Valid ID'],
                'suggested_route' => ['Records'],
            ]
        );
        Purpose::updateOrCreate(
            ['name' => 'Correction of School Entries'],
            [
                'is_official' => true,
                'requirements' => ['Affidavit of Discrepancy', 'PSA Birth Certificate', 'Original School Records'],
                'suggested_route' => ['Records', 'Legal'],
            ]
        );

        // Category 2: Employment & HR
        Purpose::updateOrCreate(
            ['name' => 'Submission of Application Documents (Ranking)'],
            [
                'is_official' => true,
                'requirements' => ['Letter of Intent', 'Personal Data Sheet (PDS)', 'CSC Form 212', 'PRC License/ID', 'Transcript of Records'],
                'suggested_route' => ['Human Resources', 'Records'],
            ]
        );
        Purpose::updateOrCreate(
            ['name' => 'Request for Service Record'],
            [
                'is_official' => true,
                'requirements' => ['Letter of Request', 'Valid ID'],
                'suggested_route' => ['Human Resources'],
            ]
        );
        Purpose::updateOrCreate(
            ['name' => 'Request for Certificate of Employment (COE)'],
            [
                'is_official' => true,
                'requirements' => ['Letter of Request', 'Valid ID'],
                'suggested_route' => ['Human Resources'],
            ]
        );

        // Category 3: Research & Data
        Purpose::updateOrCreate(
            ['name' => 'Request for Approval to Conduct Study/Survey'],
            [
                'is_official' => true,
                'requirements' => ['Letter of Intent to Conduct Study', 'Research Proposal', 'Survey Questionnaire'],
                'suggested_route' => ['Records', 'Office of the Superintendent'],
            ]
        );
        Purpose::updateOrCreate(
            ['name' => 'Request for Data/Statistics'],
            [
                'is_official' => true,
                'requirements' => ['Letter of Request specifying data needed', 'Valid ID'],
                'suggested_route' => ['Records'],
            ]
        );

        // Category 4: Private School Regulation
        Purpose::updateOrCreate(
            ['name' => 'Application for Government Permit/Recognition'],
            [
                'is_official' => true,
                'requirements' => ['Application Letter', 'Feasibility Study', 'School Site and Building Plans', 'List of Faculty and Staff'],
                'suggested_route' => ['Records', 'Office of the Superintendent'],
            ]
        );

        // Category 5: Legal & Grievances
        Purpose::updateOrCreate(
            ['name' => 'Filing of Administrative Complaint'],
            [
                'is_official' => true,
                'requirements' => ['Formal Complaint-Affidavit', 'Supporting Evidence/Documents'],
                'suggested_route' => ['Legal', 'Office of the Superintendent'],
            ]
        );

        // Category 6: External Partnerships & Events
        Purpose::updateOrCreate(
            ['name' => 'Proposal for Partnership (MOA/MOU)'],
            [
                'is_official' => true,
                'requirements' => ['Letter of Intent for Partnership', 'Draft Memorandum of Agreement/Understanding'],
                'suggested_route' => ['Office of the Superintendent'],
            ]
        );
    }
}

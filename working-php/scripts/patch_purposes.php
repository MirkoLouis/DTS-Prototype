<?php
require "vendor/autoload.php";
require "src/Core/Database.php";
define("BASE_PATH", __DIR__ . '/..');

$db = App\Core\Database::getInstance();

$purposes = [
    [
        'name' => 'CAV Request (Certification, Authentication, and Verification)',
        'requirements' => ['Letter of Request', 'Certified True Copy of School Records', 'PSA Birth Certificate'],
        'suggested_route' => ['Records Unit', 'Schools Division Superintendent Office'],
    ],
    [
        'name' => 'Request for Certified True Copy (CTC)',
        'requirements' => ['Letter of Request', 'Valid ID'],
        'suggested_route' => ['Records Unit'],
    ],
    [
        'name' => 'Correction of School Entries',
        'requirements' => ['Affidavit of Discrepancy', 'PSA Birth Certificate', 'Original School Records'],
        'suggested_route' => ['Records Unit', 'Legal Unit'],
    ],
    [
        'name' => 'Submission of Application Documents (Ranking)',
        'requirements' => ['Letter of Intent', 'Personal Data Sheet (PDS)', 'CSC Form 212', 'PRC License/ID', 'Transcript of Records'],
        'suggested_route' => ['Personnel Unit', 'Records Unit'],
    ],
    [
        'name' => 'Request for Service Record',
        'requirements' => ['Letter of Request', 'Valid ID'],
        'suggested_route' => ['Personnel Unit'],
    ],
    [
        'name' => 'Request for Certificate of Employment (COE)',
        'requirements' => ['Letter of Request', 'Valid ID'],
        'suggested_route' => ['Personnel Unit'],
    ],
    [
        'name' => 'Request for Approval to Conduct Study/Survey',
        'requirements' => ['Letter of Intent to Conduct Study', 'Research Proposal', 'Survey Questionnaire'],
        'suggested_route' => ['Records Unit', 'Schools Division Superintendent Office'],
    ],
    [
        'name' => 'Request for Data/Statistics',
        'requirements' => ['Letter of Request specifying data needed', 'Valid ID'],
        'suggested_route' => ['Records Unit'],
    ],
    [
        'name' => 'Application for Government Permit/Recognition',
        'requirements' => ['Application Letter', 'Feasibility Study', 'School Site and Building Plans', 'List of Faculty and Staff'],
        'suggested_route' => ['Records Unit', 'Schools Division Superintendent Office'],
    ],
    [
        'name' => 'Filing of Administrative Complaint',
        'requirements' => ['Formal Complaint-Affidavit', 'Supporting Evidence/Documents'],
        'suggested_route' => ['Legal Unit', 'Schools Division Superintendent Office'],
    ],
    [
        'name' => 'Proposal for Partnership (MOA/MOU)',
        'requirements' => ['Letter of Intent for Partnership', 'Draft Memorandum of Agreement/Understanding'],
        'suggested_route' => ['Schools Division Superintendent Office'],
    ],
    [
        'name' => 'Processing of First Salary',
        'requirements' => ['Appointment Paper', 'PSIPOP', 'ATM Form'],
        'suggested_route' => ['Personnel Unit', 'Budget Unit', 'Accounting Unit', 'Cash Unit']
    ],
    [
        'name' => 'Retirement Claims',
        'requirements' => ['Letter of Intent to Retire', 'Service Record'],
        'suggested_route' => ['Personnel Unit', 'Accounting Unit', 'Schools Division Superintendent Office']
    ],
    [
        'name' => 'Request for Use of Division Facilities/Vehicle',
        'requirements' => ['Letter of Request', 'Approved Itinerary (if vehicle)'],
        'suggested_route' => ['Administrative Unit', 'Assistant Schools Division Superintendent Office'],
    ],
    [
        'name' => 'Request for Office Supplies/Equipment (RIS)',
        'requirements' => ['Requisition and Issue Slip (RIS)', 'Inventory Custodian Slip (ICS)'],
        'suggested_route' => ['Supply Unit', 'Administrative Unit'],
    ],
    [
        'name' => 'Submission of Bid Documents (Project Tender)',
        'requirements' => ['Bidding Documents', 'Technical Proposal', 'Financial Proposal'],
        'suggested_route' => ['Bids and Awards Committee Unit', 'Legal Unit'],
    ],
    [
        'name' => 'Submission of Nutritional Status Report (SF13)',
        'requirements' => ['Nutritional Status Summary', 'School Health Profile'],
        'suggested_route' => ['Health and Nutrition', 'Curriculum Implementation Division'],
    ],
    [
        'name' => 'Request for Learning Resources/Textbooks',
        'requirements' => ['Inventory of Books', 'Request Form'],
        'suggested_route' => ['Curriculum Implementation Division'],
    ],
    [
        'name' => 'Submission of School Monitoring and Evaluation Report',
        'requirements' => ['Monitoring Tool Results', 'Analysis Report'],
        'suggested_route' => ['School Governance and Operations Division', 'Assistant Schools Division Superintendent Office'],
    ]
];

foreach ($purposes as $purpose) {
    $db->query(
        "UPDATE purposes SET requirements = :req, suggested_route = :route WHERE name = :name",
        [
            ':req' => json_encode($purpose['requirements']),
            ':route' => json_encode($purpose['suggested_route']),
            ':name' => $purpose['name']
        ]
    );
}

echo "Database updated successfully.\n";

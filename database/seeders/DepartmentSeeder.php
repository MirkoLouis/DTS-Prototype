<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Department;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            // Operational & Administrative Units
            ['name' => 'Records Unit'],
            ['name' => 'Cash Unit'],
            ['name' => 'Administrative Unit'],
            ['name' => 'Personnel Unit'],
            ['name' => 'Supply Unit'],
            ['name' => 'Budget Unit'],
            ['name' => 'Accounting Unit'],
            ['name' => 'Legal Unit'],
            ['name' => 'Health and Nutrition'],
            ['name' => 'Bids and Awards Committee Unit'],

            // Office of the Superintendent
            ['name' => 'Schools Division Superintendent Office'],
            ['name' => 'Assistant Schools Division Superintendent Office'],

            // Functional Divisions
            ['name' => 'Curriculum Implementation Division'],
            ['name' => 'School Governance and Operations Division']
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(['name' => $department['name']]);
        }
    }
}
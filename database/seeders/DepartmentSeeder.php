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
        Department::updateOrCreate(['name' => 'Office of the Superintendent']);
        Department::updateOrCreate(['name' => 'Accounting']);
        Department::updateOrCreate(['name' => 'Records']);
        Department::updateOrCreate(['name' => 'Human Resources']);
        Department::updateOrCreate(['name' => 'Legal']);
    }
}

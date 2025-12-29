<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Admin User is a system admin, not tied to a department
        User::updateOrCreate(
            ['email' => 'admin@dts.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'department_id' => null,
            ]
        );

        // Create a user for each department, with a special role for 'Records'
        $departments = Department::all();

        foreach ($departments as $department) {
            $email = Str::slug($department->name, '_') . '@dts.com';
            
            // The user for the 'Records' department is the 'officer', all others are 'staff'
            $role = ($department->name === 'Records') ? 'officer' : 'staff';

            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $department->name . ' Staff', // e.g., "Records Staff", "Accounting Staff"
                    'password' => Hash::make('password'),
                    'role' => $role,
                    'department_id' => $department->id,
                ]
            );
        }
    }
}

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
        // 1. Ensure 'Records Unit' department exists and create the essential Records Officer.
        // This is critical for other seeders (like DocumentSeeder) that rely on this specific user.
        $recordsDepartment = Department::firstOrCreate(
            ['name' => 'Records Unit'],
        );
        
        User::updateOrCreate(
            ['email' => 'records@dts.com'], // This email is hardcoded in DocumentSeeder
            [
                'name' => 'Records Officer',
                'password' => Hash::make('password'),
                'role' => 'officer',
                'department_id' => $recordsDepartment->id,
            ]
        );
        
        // 2. Create the Admin User (system-wide, not tied to a department)
        User::updateOrCreate(
            ['email' => 'admin@dts.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'department_id' => null,
            ]
        );

        // 3. Create 'staff' users for all other departments.
        $otherDepartments = Department::where('name', '!=', 'Records Unit')->get();

        foreach ($otherDepartments as $department) {
            $email = Str::slug($department->name, '.') . '@dts.com';
            
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $department->name . ' Staff',
                    'password' => Hash::make('password'),
                    'role' => 'staff',
                    'department_id' => $department->id,
                ]
            );
        }
    }
}

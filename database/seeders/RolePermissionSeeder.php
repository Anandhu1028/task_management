<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        if (! app()->environment('testing')) {
            return;
        }

        $company = Company::firstOrCreate(
            ['name' => 'Acme Workspace'],
            ['description' => 'Default Company', 'status' => 'active']
        );

        $department = Department::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Operations'],
            [
                'designation' => 'Employee',
                'description' => 'Core operations team',
                'status' => 'active',
            ]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'System Admin',
                'employee_id' => 'EMP-ADMIN',
                'phone' => '+1234567890',
                'company_id' => $company->id,
                'department_id' => $department->id,
                'designation' => 'Administrator',
                'status' => 'active',
                'password' => Hash::make('password'),
            ]
        );

        $adminRole = Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail();
        $admin->forceFill(['role_id' => $adminRole->id])->save();
        $admin->syncRoles([$adminRole]);

    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.change_status',
            'companies.view',
            'companies.create',
            'companies.update',
            'companies.delete',
            'companies.change_status',
            'departments.view',
            'departments.create',
            'departments.update',
            'departments.delete',
            'departments.change_status',
            'tasks.view',
            'tasks.create',
            'tasks.update',
            'tasks.delete',
            'tasks.change_status',
            'tasks.submit_for_review',
            'tasks.flag_risk',
            'tasks.review',
            'tasks.cancel',
        ];

        $roleManagementPermissions = config('role-management.management_permissions', []);

        foreach (array_merge($permissions, $roleManagementPermissions) as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $employeePermissions = array_values(array_diff($permissions, [
            'tasks.update',
            'tasks.delete',
        ]));

        $roles = [
            'Admin' => array_merge($permissions, $roleManagementPermissions),
            'CEO' => $permissions,
            'Manager' => ['tasks.view'],
            'Team Leader' => $permissions,
            'Employee' => $employeePermissions,
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions);
        }
    }
}

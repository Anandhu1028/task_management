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
            'subtasks.create',
            'subtasks.manage',
            'milestones.create',
            'milestones.manage',
            'daily_tasks.create_own',
            'daily_tasks.create_for_others',
            'daily_tasks.view_team',
            'daily_tasks.update_own',
            'daily_tasks.manage',
        ];

        $roleManagementPermissions = config('role-management.management_permissions', []);

        foreach (array_merge($permissions, $roleManagementPermissions) as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $employeePermissions = array_values(array_diff($permissions, [
            'tasks.create',
            'tasks.update',
            'tasks.delete',
            'subtasks.create',
            'subtasks.manage',
            'milestones.create',
            'milestones.manage',
            'daily_tasks.create_for_others',
            'daily_tasks.view_team',
            'daily_tasks.manage',
        ]));

        $roles = [
            'Admin' => array_merge($permissions, $roleManagementPermissions),
            'CEO' => $permissions,
            'Manager' => [
                'tasks.view',
                'tasks.create',
                'tasks.update',
                'subtasks.create',
                'subtasks.manage',
                'milestones.create',
                'milestones.manage',
                'daily_tasks.create_own',
                'daily_tasks.create_for_others',
                'daily_tasks.view_team',
                'daily_tasks.manage',
            ],
            'Team Leader' => array_values(array_diff($permissions, ['tasks.create', 'tasks.delete'])),
            'Employee' => $employeePermissions,
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions);
        }
    }
}

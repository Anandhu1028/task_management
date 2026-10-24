<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RoleBasedDashboardSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $adminRole = Role::query()->where('name', 'Admin')->where('guard_name', 'web')->firstOrFail();
        $ceoRole = Role::query()->where('name', 'CEO')->where('guard_name', 'web')->firstOrFail();
        $tlRole = Role::query()->where('name', 'Team Leader')->where('guard_name', 'web')->firstOrFail();
        $empRole = Role::query()->where('name', 'Employee')->where('guard_name', 'web')->firstOrFail();

        // 1. Ensure at least two active companies
        $company1 = Company::firstOrCreate(
            ['name' => 'UGI'],
            ['description' => 'UGI Headquarters', 'status' => 'active']
        );

        $company2 = Company::firstOrCreate(
            ['name' => 'Northstar Digital'],
            ['description' => 'Digital Product Studio', 'status' => 'active']
        );

        // 2. Ensure departments for each company
        $dept1 = Department::firstOrCreate(
            ['company_id' => $company1->id, 'name' => 'DM Team'],
            ['designation' => 'Developers', 'status' => 'active']
        );

        $dept2 = Department::firstOrCreate(
            ['company_id' => $company1->id, 'name' => 'QA'],
            ['designation' => 'QA Engineers', 'status' => 'active']
        );

        $dept3 = Department::firstOrCreate(
            ['company_id' => $company2->id, 'name' => 'Product Design'],
            ['designation' => 'Product Designers', 'status' => 'active']
        );

        // 3. Admin User
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'System Admin',
                'employee_id' => 'EMP-ADMIN01',
                'phone' => '+1555010001',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'designation' => 'Administrator',
                'status' => 'active',
                'password' => Hash::make('password'),
            ]
        );
        $admin->forceFill(['role_id' => $adminRole->id])->save();
        $admin->syncRoles([$adminRole]);

        // 4. CEO User
        $ceo = User::firstOrCreate(
            ['email' => 'ceo@example.com'],
            [
                'name' => 'Chief Executive',
                'employee_id' => 'EMP-CEO01',
                'phone' => '+1555010002',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'designation' => 'CEO',
                'status' => 'active',
                'password' => Hash::make('password'),
            ]
        );
        $ceo->forceFill(['role_id' => $ceoRole->id])->save();
        $ceo->syncRoles([$ceoRole]);

        // 5. Team Leader (Karthik)
        $tl = User::firstOrCreate(
            ['email' => 'karthik@gmail.com'],
            [
                'name' => 'Karthik',
                'employee_id' => 'EMP-TL01',
                'phone' => '+1555010003',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'designation' => 'Lead Developer',
                'status' => 'active',
                'password' => Hash::make('password'),
            ]
        );
        $tl->forceFill(['role_id' => $tlRole->id, 'department_id' => $dept1->id, 'company_id' => $company1->id])->save();
        $tl->syncRoles([$tlRole]);

        // 6. Employees (Anandhu and colleagues)
        $emp1 = User::firstOrCreate(
            ['email' => 'anandhu@gmail.com'],
            [
                'name' => 'Anandhu',
                'employee_id' => 'EMP-EMP01',
                'phone' => '+1555010004',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'designation' => 'Senior Developer',
                'status' => 'active',
                'password' => Hash::make('password'),
            ]
        );
        $emp1->forceFill(['role_id' => $empRole->id, 'department_id' => $dept1->id, 'company_id' => $company1->id])->save();
        $emp1->syncRoles([$empRole]);

        $emp2 = User::firstOrCreate(
            ['email' => 'rahul@example.com'],
            [
                'name' => 'Rahul Sharma',
                'employee_id' => 'EMP-EMP02',
                'phone' => '+1555010005',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'designation' => 'Fullstack Developer',
                'status' => 'active',
                'password' => Hash::make('password'),
            ]
        );
        $emp2->forceFill(['role_id' => $empRole->id, 'department_id' => $dept1->id, 'company_id' => $company1->id])->save();
        $emp2->syncRoles([$empRole]);

        $emp3 = User::firstOrCreate(
            ['email' => 'asha@example.com'],
            [
                'name' => 'Asha Nair',
                'employee_id' => 'EMP-EMP03',
                'phone' => '+1555010006',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'designation' => 'Frontend Developer',
                'status' => 'active',
                'password' => Hash::make('password'),
            ]
        );
        $emp3->forceFill(['role_id' => $empRole->id, 'department_id' => $dept1->id, 'company_id' => $company1->id])->save();
        $emp3->syncRoles([$empRole]);

        // 7. Seed realistic tasks
        $sampleTasks = [
            [
                'code' => 'TSK-1001',
                'title' => 'Design dashboard UI architecture',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'assigned_to' => $emp1->id,
                'priority' => 'high',
                'status' => 'completed',
                'progress' => 100,
                'completed_at' => now()->subDays(3),
                'due_date' => now()->subDays(3),
            ],
            [
                'code' => 'TSK-1002',
                'title' => 'Implement authentication & role middleware',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'assigned_to' => $emp1->id,
                'priority' => 'critical',
                'status' => 'completed',
                'progress' => 100,
                'completed_at' => now()->subDays(1),
                'due_date' => now()->subDays(1),
            ],
            [
                'code' => 'TSK-1003',
                'title' => 'Build Team Leader workload analytics',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'assigned_to' => $emp1->id,
                'priority' => 'high',
                'status' => 'in_progress',
                'progress' => 60,
                'completed_at' => null,
                'due_date' => now()->addDays(2),
            ],
            [
                'code' => 'TSK-1004',
                'title' => 'Database query optimization for statistics',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'assigned_to' => $emp2->id,
                'priority' => 'medium',
                'status' => 'in_progress',
                'progress' => 40,
                'completed_at' => null,
                'due_date' => now()->addDays(4),
            ],
            [
                'code' => 'TSK-1005',
                'title' => 'Implement responsive mobile navigation bar',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'assigned_to' => $emp3->id,
                'priority' => 'low',
                'status' => 'submitted_for_review',
                'progress' => 90,
                'completed_at' => null,
                'due_date' => now()->addDays(1),
            ],
            [
                'code' => 'TSK-1006',
                'title' => 'Update invoice templates and export formats',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'assigned_to' => $emp1->id,
                'priority' => 'critical',
                'status' => 'assigned',
                'progress' => 0,
                'completed_at' => null,
                'due_date' => now()->subDays(2), // Overdue!
            ],
            [
                'code' => 'TSK-1007',
                'title' => 'Sprint planning & backlog grooming',
                'company_id' => $company1->id,
                'department_id' => $dept1->id,
                'assigned_to' => $tl->id, // TL personal task
                'priority' => 'high',
                'status' => 'in_progress',
                'progress' => 50,
                'completed_at' => null,
                'due_date' => now()->addDays(3),
            ],
            [
                'code' => 'TSK-1008',
                'title' => 'Q3 Quality assurance audit report',
                'company_id' => $company1->id,
                'department_id' => $dept2->id,
                'assigned_to' => $admin->id,
                'priority' => 'medium',
                'status' => 'completed',
                'progress' => 100,
                'completed_at' => now()->subDays(2),
                'due_date' => now()->subDays(2),
            ],
            [
                'code' => 'TSK-1009',
                'title' => 'Brand identity guidelines for Northstar studio',
                'company_id' => $company2->id,
                'department_id' => $dept3->id,
                'assigned_to' => $ceo->id,
                'priority' => 'high',
                'status' => 'in_progress',
                'progress' => 30,
                'completed_at' => null,
                'due_date' => now()->addDays(7),
            ],
        ];

        foreach ($sampleTasks as $st) {
            $task = Task::firstOrCreate(
                ['task_code' => $st['code']],
                [
                    'title' => $st['title'],
                    'company_id' => $st['company_id'],
                    'department_id' => $st['department_id'],
                    'project' => 'Core Platform',
                    'task_type' => 'process',
                    'created_by' => $admin->id,
                    'assigned_to' => $st['assigned_to'],
                    'priority' => $st['priority'],
                    'status' => $st['status'],
                    'progress_percentage' => $st['progress'],
                    'expected_output' => 'Task output documentation and artifacts',
                    'due_date' => $st['due_date'],
                    'original_deadline' => $st['due_date'],
                    'estimated_hours' => 6.0,
                    'completed_at' => $st['completed_at'],
                ]
            );

            TaskAssignment::firstOrCreate(
                ['task_id' => $task->id, 'user_id' => $st['assigned_to']],
                [
                    'assigned_by' => $admin->id,
                    'reason' => 'Sprint assignment',
                    'assigned_at' => now()->subDays(5),
                    'is_active' => true,
                ]
            );
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentDesignation;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

class DemoOrganizationSeeder extends Seeder
{
    /**
     * @var array<int, array{key: string, name: string, description: string}>
     */
    private const COMPANIES = [
        ['key' => 'atlas', 'name' => 'Demo Atlas Technologies', 'description' => 'Software delivery, cloud platforms, and digital products.'],
        ['key' => 'greenfield', 'name' => 'Demo Greenfield Retail', 'description' => 'Retail operations, merchandising, and customer experience.'],
        ['key' => 'meridian', 'name' => 'Demo Meridian Healthcare', 'description' => 'Healthcare services, clinical operations, and patient support.'],
        ['key' => 'horizon', 'name' => 'Demo Horizon Logistics', 'description' => 'Transportation, warehousing, and supply chain operations.'],
    ];

    /**
     * @var array<int, array{key: string, name: string, description: string}>
     */
    private const DEPARTMENTS = [
        ['key' => 'operations', 'name' => 'Operations', 'description' => 'Coordinate daily operations and service delivery.'],
        ['key' => 'customer-success', 'name' => 'Customer Success', 'description' => 'Support customers and improve service outcomes.'],
        ['key' => 'engineering', 'name' => 'Engineering', 'description' => 'Build, maintain, and improve products and systems.'],
        ['key' => 'finance', 'name' => 'Finance', 'description' => 'Manage budgets, reporting, and financial controls.'],
        ['key' => 'human-resources', 'name' => 'Human Resources', 'description' => 'Support recruitment, people operations, and learning.'],
        ['key' => 'marketing', 'name' => 'Marketing', 'description' => 'Plan campaigns, communications, and market research.'],
    ];

    /**
     * @var array<int, array{title: string, type: string, description: string, output: string}>
     */
    private const TASK_TEMPLATES = [
        ['title' => 'Quarterly process review', 'type' => 'process', 'description' => 'Review the current workflow, document issues, and recommend improvements.', 'output' => 'Reviewed workflow and an approved improvement checklist.'],
        ['title' => 'Customer request backlog', 'type' => 'volume', 'description' => 'Resolve a batch of queued requests and record the outcome of each item.', 'output' => 'Completed request batch with outcome report.'],
        ['title' => 'Weekly service health check', 'type' => 'recurring', 'description' => 'Complete the recurring service checks and flag any issues for follow-up.', 'output' => 'Updated service checklist and follow-up notes.'],
        ['title' => 'Department improvement project', 'type' => 'project', 'description' => 'Coordinate a small improvement project from planning through handover.', 'output' => 'Project deliverable, handover notes, and completion summary.'],
        ['title' => 'Monthly compliance checklist', 'type' => 'process', 'description' => 'Verify required records and submit any missing items for correction.', 'output' => 'Completed compliance checklist with exceptions documented.'],
    ];

    public function run(): void
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', ['CEO', 'Team Leader', 'Employee'])
            ->get()
            ->keyBy('name');

        foreach (['CEO', 'Team Leader', 'Employee'] as $roleName) {
            if (! $roles->has($roleName)) {
                throw new RuntimeException("The [{$roleName}] role is missing. Run PermissionSeeder before DemoOrganizationSeeder.");
            }
        }

        DB::transaction(function () use ($roles): void {
            foreach (self::COMPANIES as $companyIndex => $companyDefinition) {
                $company = Company::firstOrCreate(
                    ['name' => $companyDefinition['name']],
                    ['description' => $companyDefinition['description'], 'status' => 'active'],
                );
                $departmentRecords = [];

                foreach (self::DEPARTMENTS as $departmentIndex => $departmentDefinition) {
                    $employeeDesignation = $departmentDefinition['name'].' Specialist';
                    $department = Department::firstOrCreate(
                        ['company_id' => $company->id, 'name' => $departmentDefinition['name']],
                        [
                            'designation' => $employeeDesignation,
                            'description' => $departmentDefinition['description'],
                            'status' => 'active',
                        ],
                    );

                    $leaderDesignation = $departmentDefinition['name'].' Team Leader';
                    $leaderDesignationRecord = $this->assignDesignationRole($department, $leaderDesignation, $roles->get('Team Leader'));
                    $employeeDesignationRecord = $this->assignDesignationRole($department, $employeeDesignation, $roles->get('Employee'));

                    if ($departmentIndex === 0) {
                        $this->assignDesignationRole($department, 'Chief Executive Officer', $roles->get('CEO'));
                    }

                    $teamLeader = $this->createDemoUser(
                        company: $company,
                        department: $department,
                        role: $roles->get('Team Leader'),
                        designation: $leaderDesignationRecord->name,
                        email: "{$companyDefinition['key']}.{$departmentDefinition['key']}.lead@example.test",
                        employeeId: $this->employeeId($companyIndex, $departmentIndex, 'TL'),
                        name: "{$departmentDefinition['name']} Team Leader ".($companyIndex + 1),
                    );

                    $employees = [];

                    foreach (range(1, 8) as $employeeNumber) {
                        $employees[] = $this->createDemoUser(
                            company: $company,
                            department: $department,
                            role: $roles->get('Employee'),
                            designation: $employeeDesignationRecord->name,
                            email: "{$companyDefinition['key']}.{$departmentDefinition['key']}.employee".str_pad((string) $employeeNumber, 2, '0', STR_PAD_LEFT).'@example.test',
                            employeeId: $this->employeeId($companyIndex, $departmentIndex, 'E'.str_pad((string) $employeeNumber, 2, '0', STR_PAD_LEFT)),
                            name: "{$departmentDefinition['name']} Employee {$employeeNumber} - ".($companyIndex + 1),
                        );
                    }

                    if ($departmentIndex === 0) {
                        $this->createDemoUser(
                            company: $company,
                            department: $department,
                            role: $roles->get('CEO'),
                            designation: 'Chief Executive Officer',
                            email: "{$companyDefinition['key']}.ceo@example.test",
                            employeeId: $this->employeeId($companyIndex, $departmentIndex, 'CEO'),
                            name: $companyDefinition['name'].' CEO',
                        );
                    }

                    $departmentRecords[] = [
                        'department' => $department,
                        'department_index' => $departmentIndex,
                        'team_leader' => $teamLeader,
                        'employees' => $employees,
                    ];
                }

                foreach ($departmentRecords as $departmentRecord) {
                    $this->createDepartmentTasks(
                        company: $company,
                        department: $departmentRecord['department'],
                        departmentIndex: $departmentRecord['department_index'],
                        teamLeader: $departmentRecord['team_leader'],
                        employees: $departmentRecord['employees'],
                    );
                }
            }
        });

        $this->command?->info('Demo organization data is ready: 4 companies, 24 departments, 220 users, and 120 assigned tasks.');
    }

    private function assignDesignationRole(Department $department, string $designationName, Role $role): DepartmentDesignation
    {
        $designation = $department->designations()->firstOrCreate(['name' => $designationName]);
        $designation->roles()->syncWithoutDetaching([$role->id]);

        return $designation;
    }

    private function createDemoUser(
        Company $company,
        Department $department,
        Role $role,
        string $designation,
        string $email,
        string $employeeId,
        string $name,
    ): User {
        $user = User::withTrashed()->where('email', $email)->first();

        if ($user !== null && ! $user->is_demo) {
            throw new RuntimeException("Cannot seed demo user [{$email}] because that email belongs to a non-demo account.");
        }

        if ($user === null && User::withTrashed()->where('employee_id', $employeeId)->exists()) {
            throw new RuntimeException("Cannot seed demo user because employee ID [{$employeeId}] is already in use.");
        }

        if ($user === null) {
            $user = new User([
                'email' => $email,
                'password' => Str::random(64),
            ]);
        }

        $user->fill([
            'name' => $name,
            'employee_id' => $employeeId,
            'phone' => '+1 555 '.str_pad((string) (abs(crc32($email)) % 10000000), 7, '0', STR_PAD_LEFT),
            'company_id' => $company->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'designation' => $designation,
            'status' => 'active',
            'requires_approval' => false,
            'is_demo' => true,
        ]);

        if ($user->trashed()) {
            $user->restore();
        }

        $user->save();
        $user->syncRoles([$role]);

        return $user;
    }

    /**
     * @param  array<int, User>  $employees
     */
    private function createDepartmentTasks(
        Company $company,
        Department $department,
        int $departmentIndex,
        User $teamLeader,
        array $employees,
    ): void {
        $statuses = [
            ['name' => 'assigned', 'progress' => 0],
            ['name' => 'in_progress', 'progress' => 35],
            ['name' => 'submitted_for_review', 'progress' => 85],
            ['name' => 'completed', 'progress' => 100],
            ['name' => 'on_hold', 'progress' => 20],
        ];
        $priorities = ['medium', 'high', 'low', 'critical', 'medium'];

        foreach (self::TASK_TEMPLATES as $taskIndex => $template) {
            $taskNumber = $taskIndex + 1;
            $title = 'DEMO-'.$company->id.'-'.$department->id.'-'.$taskNumber.': '.$template['title'];
            $task = Task::withTrashed()
                ->where('department_id', $department->id)
                ->where('title', $title)
                ->first();
            $status = $statuses[$taskIndex];
            $dueDate = now()->addDays(3 + (($departmentIndex + $taskIndex * 2) % 18))->toDateString();

            if ($task === null) {
                $task = Task::create([
                    'task_code' => Task::generateCode(),
                    'title' => $title,
                    'description' => $template['description'],
                    'company_id' => $company->id,
                    'department_id' => $department->id,
                    'project' => $company->name.' - '.$department->name.' Operations',
                    'task_type' => $template['type'],
                    'created_by' => $teamLeader->id,
                    'priority' => $priorities[$taskIndex],
                    'status' => $status['name'],
                    'progress_percentage' => $status['progress'],
                    'start_date' => now()->subDays(($departmentIndex + $taskIndex) % 5)->toDateString(),
                    'due_date' => $dueDate,
                    'original_deadline' => $dueDate,
                    'estimated_hours' => 2 + (($taskIndex + $departmentIndex) % 10),
                    'expected_output' => $template['output'],
                    'completed_at' => $status['name'] === 'completed' ? now()->subDay() : null,
                    'submitted_at' => $status['name'] === 'submitted_for_review' ? now()->subDay() : null,
                    'hold_reason' => $status['name'] === 'on_hold' ? 'Waiting for a dependency.' : null,
                ]);

                TaskStatusHistory::create([
                    'task_id' => $task->id,
                    'changed_by' => $teamLeader->id,
                    'new_status' => $status['name'],
                    'remarks' => 'Initial demo task status.',
                ]);
            } elseif ($task->trashed()) {
                $task->restore();
            }

            $employeeIndex = ($taskIndex * 3 + $departmentIndex) % count($employees);
            $employee = $employees[$employeeIndex];
            TaskAssignment::query()
                ->where('task_id', $task->id)
                ->where('is_active', true)
                ->where('user_id', '!=', $employee->id)
                ->update(['is_active' => false, 'unassigned_at' => now()]);

            $assignment = TaskAssignment::query()
                ->firstOrNew(['task_id' => $task->id, 'user_id' => $employee->id]);
            $assignment->fill([
                'assigned_by' => $teamLeader->id,
                'assigned_at' => $assignment->assigned_at ?? now(),
                'unassigned_at' => null,
                'is_active' => true,
            ]);
            $assignment->save();

            $task->forceFill([
                'assigned_to' => $employee->id,
                'reviewer_id' => $teamLeader->id,
            ])->save();
            $task->pocs()->syncWithoutDetaching([$teamLeader->id]);
        }
    }

    private function employeeId(int $companyIndex, int $departmentIndex, string $suffix): string
    {
        return sprintf('D%02d-%02d-%s', $companyIndex + 1, $departmentIndex + 1, $suffix);
    }
}

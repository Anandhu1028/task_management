<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

class SampleTaskDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $employeeRole = Role::query()
            ->where('name', 'Employee')
            ->where('guard_name', 'web')
            ->firstOrFail();

        DB::transaction(function () use ($employeeRole): void {
            $company = Company::firstOrCreate(
                ['name' => 'UGI'],
                ['description' => 'UGI Headquarters', 'status' => 'active'],
            );
            $department = Department::firstOrCreate(
                ['company_id' => $company->id, 'name' => 'Technical & Innovation'],
                [
                    'designation' => 'Junior Software Developer',
                    'description' => 'Software development and technical innovation.',
                    'status' => 'active',
                ],
            );

            $userDefinitions = [
                ['name' => 'Aarav Menon', 'email' => 'sample.employee01@ugi.example.test', 'employee_id' => 'SEED-UGI-001', 'phone' => '+91 90000 10001'],
                ['name' => 'Meera Nair', 'email' => 'sample.employee02@ugi.example.test', 'employee_id' => 'SEED-UGI-002', 'phone' => '+91 90000 10002'],
                ['name' => 'Rohan Pillai', 'email' => 'sample.employee03@ugi.example.test', 'employee_id' => 'SEED-UGI-003', 'phone' => '+91 90000 10003'],
            ];

            $users = collect($userDefinitions)->map(function (array $definition) use ($company, $department, $employeeRole): User {
                $user = User::withTrashed()->where('email', $definition['email'])->first();

                if ($user !== null && ! $user->is_demo) {
                    throw new RuntimeException("Cannot seed sample account because [{$definition['email']}] belongs to a non-demo user.");
                }

                if ($user === null && User::withTrashed()->where('employee_id', $definition['employee_id'])->exists()) {
                    throw new RuntimeException("Cannot seed sample account because employee ID [{$definition['employee_id']}] is already in use.");
                }

                $user ??= new User([
                    'email' => $definition['email'],
                    'password' => Str::random(64),
                ]);
                $user->fill([
                    'name' => $definition['name'],
                    'employee_id' => $definition['employee_id'],
                    'phone' => $definition['phone'],
                    'company_id' => $company->id,
                    'department_id' => $department->id,
                    'role_id' => $employeeRole->id,
                    'designation' => $department->designation,
                    'status' => 'active',
                    'is_demo' => true,
                ]);

                if ($user->trashed()) {
                    $user->restore();
                }

                $user->save();
                $user->syncRoles([$employeeRole]);

                return $user;
            });

            $taskDefinitions = [
                [
                    'code' => 'SAMPLE-UGI-001',
                    'title' => 'Sample: Improve employee dashboard',
                    'description' => 'Refine the employee dashboard layout and improve its usability.',
                    'expected_output' => 'Updated dashboard with responsive layout and documented changes.',
                    'priority' => 'high',
                    'assignee' => 0,
                    'subtasks' => [
                        ['title' => 'Review dashboard requirements', 'description' => 'Document the requested dashboard improvements.'],
                        ['title' => 'Implement responsive dashboard layout', 'description' => 'Update the dashboard layout for desktop and mobile screens.'],
                    ],
                ],
                [
                    'code' => 'SAMPLE-UGI-002',
                    'title' => 'Sample: Add task progress reporting',
                    'description' => 'Add a clear progress summary for active department tasks.',
                    'expected_output' => 'A tested task progress summary available to department employees.',
                    'priority' => 'medium',
                    'assignee' => 1,
                    'subtasks' => [
                        ['title' => 'Define task progress metrics', 'description' => 'Agree on the task statuses and progress values to report.'],
                        ['title' => 'Build progress summary view', 'description' => 'Display the agreed metrics in a concise report.'],
                    ],
                ],
            ];

            foreach ($taskDefinitions as $definition) {
                $creator = $users[0];
                $assignee = $users[$definition['assignee']];
                $task = Task::withTrashed()->where('task_code', $definition['code'])->first();

                if ($task !== null
                    && ($task->title !== $definition['title']
                        || $task->company_id !== $company->id
                        || $task->department_id !== $department->id)) {
                    throw new RuntimeException("Cannot seed sample task because task code [{$definition['code']}] is already in use.");
                }

                $task ??= new Task(['task_code' => $definition['code']]);
                $task->fill([
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'company_id' => $company->id,
                    'department_id' => $department->id,
                    'project' => 'UGI Technical & Innovation',
                    'task_type' => 'process',
                    'created_by' => $creator->id,
                    'assigned_to' => $assignee->id,
                    'priority' => $definition['priority'],
                    'status' => 'assigned',
                    'progress_percentage' => 0,
                    'start_date' => today()->toDateString(),
                    'due_date' => today()->addDays(7)->toDateString(),
                    'original_deadline' => today()->addDays(7)->toDateString(),
                    'estimated_hours' => 8,
                    'expected_output' => $definition['expected_output'],
                ]);

                if ($task->trashed()) {
                    $task->restore();
                }

                $task->save();

                $assignment = TaskAssignment::firstOrNew([
                    'task_id' => $task->id,
                    'user_id' => $assignee->id,
                ]);
                $assignment->fill([
                    'assigned_by' => $creator->id,
                    'reason' => 'Sample task assignment',
                    'assigned_at' => $assignment->assigned_at ?? now(),
                    'unassigned_at' => null,
                    'is_active' => true,
                ]);
                $assignment->save();

                foreach ($definition['subtasks'] as $index => $subtaskDefinition) {
                    Subtask::firstOrCreate(
                        ['task_id' => $task->id, 'title' => $subtaskDefinition['title']],
                        [
                            'created_by' => $creator->id,
                            'assigned_to' => $assignee->id,
                            'description' => $subtaskDefinition['description'],
                            'priority' => $definition['priority'],
                            'status' => 'assigned',
                            'sort_order' => $index + 1,
                        ],
                    );
                }
            }
        });

        $this->command?->info('Sample data is ready: 3 UGI employees, 2 tasks, and 4 subtasks.');
    }
}

<?php

namespace App\Services\Mcp\Tools;

use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CreateTaskTool implements McpToolInterface
{
    public function name(): string
    {
        return 'create_task';
    }

    public function description(): string
    {
        return 'Create a new task within the authenticated user\'s authorized company and department. Requires tasks.create permission.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'The task title or summary.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Detailed task description or deliverables specification.',
                ],
                'project' => [
                    'type' => 'string',
                    'description' => 'Optional project name.',
                ],
                'priority' => [
                    'type' => 'string',
                    'description' => 'Task priority: critical, high, medium, low.',
                    'enum' => Task::PRIORITIES,
                ],
                'due_date' => [
                    'type' => 'string',
                    'description' => 'Due date in YYYY-MM-DD format.',
                ],
                'department_id' => [
                    'type' => 'integer',
                    'description' => 'Target department ID (must belong to your company). Defaults to user\'s department if omitted.',
                ],
                'assignee_id' => [
                    'type' => 'integer',
                    'description' => 'User ID to assign the task to (must belong to your company).',
                ],
                'estimated_hours' => [
                    'type' => 'number',
                    'description' => 'Estimated effort in hours (default: 4.0).',
                ],
                'task_type' => [
                    'type' => 'string',
                    'description' => 'Task type: process, operational, recurring, milestone.',
                    'enum' => ['process', 'operational', 'recurring', 'milestone'],
                ],
            ],
            'required' => ['title'],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        if (! $user->can('tasks.create')) {
            throw new \InvalidArgumentException('Permission denied: You do not have permission to create tasks (requires tasks.create).');
        }

        $title = trim((string) ($arguments['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Task title cannot be empty.');
        }

        $companyId = $user->company_id;
        if (! $companyId) {
            throw new \InvalidArgumentException('Cannot create task: User account has no assigned company.');
        }

        $departmentId = ! empty($arguments['department_id'])
            ? (int) $arguments['department_id']
            : $user->department_id;

        if ($departmentId) {
            $deptValid = Department::query()
                ->where('id', $departmentId)
                ->where('company_id', $companyId)
                ->exists();

            if (! $deptValid) {
                throw new \InvalidArgumentException('Invalid department_id: Department does not belong to your company.');
            }
        }

        $assigneeId = ! empty($arguments['assignee_id']) ? (int) $arguments['assignee_id'] : null;
        if ($assigneeId) {
            $assigneeValid = User::query()
                ->where('id', $assigneeId)
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->exists();

            if (! $assigneeValid) {
                throw new \InvalidArgumentException('Invalid assignee_id: User must be an active member of your company.');
            }
        }

        $dueDate = ! empty($arguments['due_date'])
            ? Carbon::parse($arguments['due_date'])->toDateString()
            : null;

        $priority = in_array($arguments['priority'] ?? null, Task::PRIORITIES, true)
            ? $arguments['priority']
            : 'medium';

        $taskType = in_array($arguments['task_type'] ?? null, ['process', 'operational', 'recurring', 'milestone'], true)
            ? $arguments['task_type']
            : 'process';

        $estimatedHours = isset($arguments['estimated_hours'])
            ? (float) $arguments['estimated_hours']
            : 4.0;

        $task = DB::transaction(function () use ($user, $title, $arguments, $companyId, $departmentId, $assigneeId, $dueDate, $priority, $taskType, $estimatedHours): Task {
            $initialStatus = $assigneeId ? 'assigned' : 'unassigned';

            $task = Task::create([
                'task_code' => Task::generateCode(),
                'title' => $title,
                'description' => $arguments['description'] ?? null,
                'project' => $arguments['project'] ?? null,
                'company_id' => $companyId,
                'department_id' => $departmentId,
                'assigned_to' => $assigneeId,
                'created_by' => $user->id,
                'priority' => $priority,
                'task_type' => $taskType,
                'status' => $initialStatus,
                'estimated_hours' => $estimatedHours,
                'expected_output' => 'Completed deliverable',
                'due_date' => $dueDate,
                'original_deadline' => $dueDate,
            ]);

            if ($assigneeId) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $assigneeId,
                    'is_active' => true,
                    'assigned_at' => now(),
                    'assigned_by' => $user->id,
                ]);
            }

            TaskStatusHistory::create([
                'task_id' => $task->id,
                'old_status' => null,
                'new_status' => $initialStatus,
                'event_type' => 'create',
                'changed_by' => $user->id,
                'reason' => 'Task created via MCP integration',
            ]);

            return $task;
        });

        return [
            'success' => true,
            'message' => "Task {$task->task_code} created successfully.",
            'task' => [
                'id' => $task->id,
                'task_code' => $task->task_code,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toDateString(),
                'project' => $task->project,
                'company_id' => $task->company_id,
                'department_id' => $task->department_id,
            ],
        ];
    }
}

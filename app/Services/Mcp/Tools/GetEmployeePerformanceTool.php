<?php

namespace App\Services\Mcp\Tools;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

class GetEmployeePerformanceTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_employee_performance';
    }

    public function description(): string
    {
        return 'Retrieve performance, completion metrics, and workload statistics for an employee. Accessible to managers, team leaders, and administrators within permitted company scopes.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'employee_id' => [
                    'type' => 'integer',
                    'description' => 'The user ID of the employee (defaults to authenticated user).',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $targetEmployeeId = ! empty($arguments['employee_id'])
            ? (int) $arguments['employee_id']
            : $user->id;

        $targetUser = User::query()
            ->with(['department:id,name', 'company:id,name'])
            ->where('id', $targetEmployeeId)
            ->first();

        if (! $targetUser) {
            throw new \InvalidArgumentException('Employee not found.');
        }

        // Multi-tenant check: Must belong to the same company
        if ($user->company_id && (int) $user->company_id !== (int) $targetUser->company_id) {
            throw new \InvalidArgumentException('Access denied: You cannot view employee metrics from another company.');
        }

        // Role authorization check
        $isAdminOrCeo = $user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO');
        $isManager = $user->hasAssignedRole('Manager');
        $isTeamLeader = $user->hasAssignedRole('Team Leader');
        $isSelf = (int) $user->id === (int) $targetUser->id;

        if (! $isAdminOrCeo && ! $isManager && ! $isSelf) {
            if ($isTeamLeader) {
                if ($user->department_id !== $targetUser->department_id) {
                    throw new \InvalidArgumentException('Access denied: Team Leaders can only view employees in their department.');
                }
            } else {
                // Regular employees can only view their own performance
                throw new \InvalidArgumentException('Access denied: Employees can only view their own performance metrics.');
            }
        }

        $assignedTasksQuery = Task::query()->assignedTo($targetUser->id);
        if ($user->company_id) {
            $assignedTasksQuery->where('company_id', $user->company_id);
        }

        $totalAssigned = (clone $assignedTasksQuery)->count();
        $completedTasks = (clone $assignedTasksQuery)->where('status', 'completed')->count();
        $inProgressTasks = (clone $assignedTasksQuery)->where('status', 'in_progress')->count();
        $overdueTasks = (clone $assignedTasksQuery)->overdue()->count();

        $subtasksQuery = Subtask::query()->where('assigned_to', $targetUser->id);
        $totalSubtasks = (clone $subtasksQuery)->count();
        $completedSubtasks = (clone $subtasksQuery)->where('is_completed', true)->count();

        $taskCompletionRate = $totalAssigned > 0 ? round(($completedTasks / $totalAssigned) * 100, 1) : 0.0;
        $subtaskCompletionRate = $totalSubtasks > 0 ? round(($completedSubtasks / $totalSubtasks) * 100, 1) : 0.0;

        return [
            'employee' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'designation' => $targetUser->designation,
                'department' => $targetUser->department?->name,
                'company' => $targetUser->company?->name,
            ],
            'performance' => [
                'total_assigned_tasks' => $totalAssigned,
                'completed_tasks' => $completedTasks,
                'in_progress_tasks' => $inProgressTasks,
                'overdue_tasks' => $overdueTasks,
                'task_completion_rate' => $taskCompletionRate,
                'total_subtasks' => $totalSubtasks,
                'completed_subtasks' => $completedSubtasks,
                'subtask_completion_rate' => $subtaskCompletionRate,
            ],
            'evaluated_at' => Carbon::now()->toIso8601String(),
        ];
    }
}

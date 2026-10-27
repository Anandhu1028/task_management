<?php

namespace App\Services\Mcp\Tools;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetTeamReportTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_team_report';
    }

    public function description(): string
    {
        return 'Generate team workload and employee-wise task completion reports scoped strictly by the user role and company/department permissions.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'department_id' => [
                    'type' => 'integer',
                    'description' => 'Optional filter for specific department (if permitted).',
                ],
                'company_id' => [
                    'type' => 'integer',
                    'description' => 'Optional filter for specific company (Admin/CEO only).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of employees to return (default: 25, max: 50).',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $scopedTasks = Task::query()->visibleTo($user);

        if (! empty($arguments['department_id'])) {
            $deptId = (int) $arguments['department_id'];
            if ($user->hasAssignedRole('Team Leader') && $user->department_id !== $deptId) {
                // Team Leader cannot view outside their department
                $scopedTasks->whereRaw('1 = 0');
            } else {
                $scopedTasks->where('tasks.department_id', $deptId);
            }
        }

        if (! empty($arguments['company_id'])) {
            $comp = (int) $arguments['company_id'];
            if ($user->hasAssignedRole('Manager') && $user->company_id !== $comp) {
                // Manager cannot view outside their company
                $scopedTasks->whereRaw('1 = 0');
            } else {
                $scopedTasks->where('tasks.company_id', $comp);
            }
        }

        $activeAssignments = DB::table('task_assignments')
            ->select('task_id', 'user_id')
            ->where('is_active', true)
            ->whereNull('unassigned_at');

        $legacyAssignments = DB::table('tasks')
            ->selectRaw('id as task_id, assigned_to as user_id')
            ->whereNotNull('assigned_to')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('task_assignments')
                    ->whereColumn('task_assignments.task_id', 'tasks.id')
                    ->where('task_assignments.is_active', true)
                    ->whereNull('task_assignments.unassigned_at');
            });

        $currentAssignments = $activeAssignments->unionAll($legacyAssignments);

        $reportTasks = (clone $scopedTasks)->select([
            'tasks.id',
            'tasks.department_id',
            'tasks.status',
            'tasks.due_date',
            'tasks.original_deadline',
        ]);

        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 25)));
        $todayStr = Carbon::today()->toDateString();

        $employeeSummaries = DB::query()
            ->fromSub($currentAssignments, 'current_assignments')
            ->joinSub($reportTasks, 'report_tasks', 'report_tasks.id', '=', 'current_assignments.task_id')
            ->join('users', 'users.id', '=', 'current_assignments.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'report_tasks.department_id')
            ->whereNull('users.deleted_at')
            ->select('users.id', 'users.name', 'users.designation', 'departments.name as department_name')
            ->selectRaw('COUNT(DISTINCT report_tasks.id) as total_tasks')
            ->selectRaw('COUNT(DISTINCT CASE WHEN report_tasks.status = ? THEN report_tasks.id END) as completed_tasks', ['completed'])
            ->selectRaw('COUNT(DISTINCT CASE WHEN report_tasks.status = ? THEN report_tasks.id END) as in_progress_tasks', ['in_progress'])
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN report_tasks.status NOT IN (?, ?) AND COALESCE(report_tasks.due_date, report_tasks.original_deadline) < ? THEN report_tasks.id END) as overdue_tasks',
                ['completed', 'cancelled', $todayStr],
            )
            ->groupBy('users.id', 'users.name', 'users.designation', 'departments.name')
            ->orderByDesc('total_tasks')
            ->orderBy('users.name')
            ->limit($limit)
            ->get();

        $teamData = $employeeSummaries->map(function ($emp): array {
            $total = (int) $emp->total_tasks;
            $completed = (int) $emp->completed_tasks;
            $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

            return [
                'user_id' => $emp->id,
                'name' => $emp->name,
                'designation' => $emp->designation,
                'department' => $emp->department_name,
                'workload' => [
                    'total_tasks' => $total,
                    'completed' => $completed,
                    'in_progress' => (int) $emp->in_progress_tasks,
                    'overdue' => (int) $emp->overdue_tasks,
                    'completion_rate' => $rate,
                ],
            ];
        })->values()->all();

        return [
            'team_members_count' => count($teamData),
            'team_workload' => $teamData,
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }
}

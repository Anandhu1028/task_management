<?php

namespace App\Services\Mcp\Tools;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

class GetProjectReportTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_project_report';
    }

    public function description(): string
    {
        return 'Generate project-wise task totals, status breakdowns, overdue counts, and completion percentages for active projects.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project' => [
                    'type' => 'string',
                    'description' => 'Optional specific project name to filter on.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum projects to return (default: 20, max: 50).',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $scopedQuery = Task::query()
            ->visibleTo($user)
            ->whereNotNull('tasks.project')
            ->where('tasks.project', '!=', '');

        if (! empty($arguments['project'])) {
            $scopedQuery->where('tasks.project', 'like', '%'.trim((string) $arguments['project']).'%');
        }

        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 20)));
        $todayStr = Carbon::today()->toDateString();

        $projectAggregates = (clone $scopedQuery)
            ->select('tasks.project')
            ->selectRaw('COUNT(*) as total_tasks')
            ->selectRaw('SUM(CASE WHEN tasks.status = ? THEN 1 ELSE 0 END) as completed_tasks', ['completed'])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? THEN 1 ELSE 0 END) as in_progress_tasks', ['in_progress'])
            ->selectRaw('SUM(CASE WHEN tasks.status IN (?, ?, ?) THEN 1 ELSE 0 END) as pending_tasks', ['notified', 'unassigned', 'assigned'])
            ->selectRaw('SUM(CASE WHEN tasks.status = ? THEN 1 ELSE 0 END) as on_hold_tasks', ['on_hold'])
            ->selectRaw(
                'SUM(CASE WHEN tasks.status NOT IN (?, ?) AND COALESCE(tasks.due_date, tasks.original_deadline) < ? THEN 1 ELSE 0 END) as overdue_tasks',
                ['completed', 'cancelled', $todayStr],
            )
            ->groupBy('tasks.project')
            ->orderByDesc('total_tasks')
            ->limit($limit)
            ->get();

        $projects = $projectAggregates->map(function ($proj): array {
            $total = (int) $proj->total_tasks;
            $completed = (int) $proj->completed_tasks;
            $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

            return [
                'project_name' => $proj->project,
                'total_tasks' => $total,
                'completed' => $completed,
                'in_progress' => (int) $proj->in_progress_tasks,
                'pending' => (int) $proj->pending_tasks,
                'on_hold' => (int) $proj->on_hold_tasks,
                'overdue' => (int) $proj->overdue_tasks,
                'completion_percentage' => $completionRate,
            ];
        })->values()->all();

        return [
            'total_projects' => count($projects),
            'projects' => $projects,
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }
}

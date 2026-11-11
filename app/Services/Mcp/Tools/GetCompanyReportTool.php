<?php

namespace App\Services\Mcp\Tools;

use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

class GetCompanyReportTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_company_report';
    }

    public function description(): string
    {
        return 'Generate an executive-level summary of company performance, department workloads, completion metrics, and overdue risks. Available only to CEO and Admin roles.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'company_id' => [
                    'type' => 'integer',
                    'description' => 'Target company ID (defaults to user\'s company; cross-company access forbidden).',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $isAdminOrCeo = $user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO');
        if (! $isAdminOrCeo) {
            throw new \InvalidArgumentException('Permission denied: Executive company reports are restricted to CEO and Admin roles.');
        }

        $companyId = $user->company_id;
        if (! empty($arguments['company_id'])) {
            $requested = (int) $arguments['company_id'];
            if ($companyId && $companyId !== $requested) {
                throw new \InvalidArgumentException('Access denied: You cannot view reports for companies you do not manage.');
            }
            $companyId = $requested;
        }

        if (! $companyId) {
            throw new \InvalidArgumentException('User account has no assigned company.');
        }

        $company = $user->company;
        $companyName = $company?->name ?? 'Company #'.$companyId;

        $taskQuery = Task::query()->where('company_id', $companyId);
        $totalTasks = (clone $taskQuery)->count();
        $completedTasks = (clone $taskQuery)->where('status', 'completed')->count();
        $inProgressTasks = (clone $taskQuery)->where('status', 'in_progress')->count();
        $overdueTasks = (clone $taskQuery)->overdue()->count();
        $atRiskTasks = (clone $taskQuery)->atRisk()->count();

        $overallCompletion = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0.0;

        $todayStr = Carbon::today()->toDateString();

        $departmentBreakdown = Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($query) => $query->where('status', 'completed'),
                'tasks as overdue_tasks_count' => fn ($query) => $query->whereNotIn('status', ['completed', 'cancelled'])->whereRaw('COALESCE(due_date, original_deadline) < ?', [$todayStr]),
                'users' => fn ($q) => $q->where('status', 'active'),
            ])
            ->get()
            ->map(fn (Department $d): array => [
                'department_id' => $d->id,
                'name' => $d->name,
                'team_members_count' => $d->users_count,
                'total_tasks' => $d->tasks_count,
                'completed_tasks' => $d->completed_tasks_count,
                'overdue_tasks' => $d->overdue_tasks_count,
                'completion_rate' => $d->tasks_count > 0 ? round(($d->completed_tasks_count / $d->tasks_count) * 100, 1) : 0.0,
            ])->values()->all();

        return [
            'company' => [
                'id' => $companyId,
                'name' => $companyName,
            ],
            'executive_summary' => [
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'in_progress_tasks' => $inProgressTasks,
                'overdue_tasks' => $overdueTasks,
                'at_risk_tasks' => $atRiskTasks,
                'completion_percentage' => $overallCompletion,
            ],
            'department_performance' => $departmentBreakdown,
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }
}

<?php

namespace App\Services\Mcp\Tools;

use App\Models\EmployeeDailyAccountability;
use App\Models\SubtaskDailyUpdate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class GetDailyWorkReportTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_daily_work_report';
    }

    public function description(): string
    {
        return 'Retrieve Daily Work Reports (DWR) and employee daily accountability records for today or a specific date, scoped by role and company.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'description' => 'Target report date in YYYY-MM-DD format (defaults to today).',
                ],
                'employee_id' => [
                    'type' => 'integer',
                    'description' => 'Optional filter for specific employee ID (elevated roles only).',
                ],
                'department_id' => [
                    'type' => 'integer',
                    'description' => 'Optional filter for specific department (Admin/CEO/Manager only).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum records to return (default: 25, max: 50).',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $targetDate = ! empty($arguments['date'])
            ? Carbon::parse($arguments['date'])->toDateString()
            : Carbon::today()->toDateString();

        $isAdminOrCeo = $user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO');
        $isManager = $user->hasAssignedRole('Manager');
        $isTeamLeader = $user->hasAssignedRole('Team Leader');
        $isElevated = $isAdminOrCeo || $isManager || $isTeamLeader;

        $updateQuery = SubtaskDailyUpdate::query()
            ->with([
                'subtask:id,task_id,title,is_completed',
                'subtask.task:id,task_code,title,company_id,department_id',
                'employee:id,name,department_id,company_id',
            ])
            ->whereDate('activity_date', $targetDate);

        // Apply company tenant isolation
        if ($user->company_id) {
            $updateQuery->whereHas('employee', fn (Builder $q) => $q->where('company_id', $user->company_id));
        }

        // Apply role hierarchy scoping
        if (! $isElevated) {
            // Employee only sees their own DWR
            $updateQuery->where('employee_id', $user->id);
        } elseif ($isTeamLeader) {
            // Team Leader only sees their department
            if ($user->department_id) {
                $updateQuery->whereHas('employee', fn (Builder $q) => $q->where('department_id', $user->department_id));
            }
        }

        if (! empty($arguments['employee_id']) && $isElevated) {
            $updateQuery->where('employee_id', (int) $arguments['employee_id']);
        }

        if (! empty($arguments['department_id']) && ($isAdminOrCeo || $isManager)) {
            $updateQuery->whereHas('employee', fn (Builder $q) => $q->where('department_id', (int) $arguments['department_id']));
        }

        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 25)));
        $updates = $updateQuery->orderByDesc('id')->limit($limit)->get();

        $accountabilityQuery = EmployeeDailyAccountability::query()
            ->with(['employee:id,name,department_id,company_id'])
            ->whereDate('date', $targetDate);

        if ($user->company_id) {
            $accountabilityQuery->whereHas('employee', fn (Builder $q) => $q->where('company_id', $user->company_id));
        }

        if (! $isElevated) {
            $accountabilityQuery->where('employee_id', $user->id);
        } elseif ($isTeamLeader && $user->department_id) {
            $accountabilityQuery->whereHas('employee', fn (Builder $q) => $q->where('department_id', $user->department_id));
        }

        $accountabilities = $accountabilityQuery->limit(25)->get();

        return [
            'date' => $targetDate,
            'viewer' => [
                'name' => $user->name,
                'role' => $user->getRoleNames()->first() ?? 'User',
            ],
            'daily_updates_count' => $updates->count(),
            'daily_updates' => $updates->map(fn (SubtaskDailyUpdate $u): array => [
                'id' => $u->id,
                'employee' => $u->employee?->name,
                'task_code' => $u->subtask?->task?->task_code,
                'task_title' => $u->subtask?->task?->title,
                'subtask_title' => $u->subtask?->title,
                'update_text' => $u->update_text,
                'progress_percentage' => (int) $u->progress_percentage,
                'hours_spent' => (float) $u->hours_spent,
                'is_completed' => (bool) $u->is_completed,
                'created_at' => $u->created_at?->toIso8601String(),
            ])->values()->all(),
            'accountabilities' => $accountabilities->map(fn (EmployeeDailyAccountability $a): array => [
                'employee' => $a->employee?->name,
                'total_subtasks' => (int) $a->total_subtasks,
                'completed_subtasks' => (int) $a->completed_subtasks,
                'in_progress_subtasks' => (int) $a->in_progress_subtasks,
                'total_hours_logged' => (float) $a->total_hours_logged,
            ])->values()->all(),
        ];
    }
}

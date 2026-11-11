<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDailyAccountability;
use App\Models\Subtask;
use App\Models\SubtaskDailyUpdate;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubtaskManagementController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', SubtaskDailyUpdate::class);
        $user = $request->user();
        $validated = $request->validate([
            'date_range' => ['nullable', Rule::in(['today', 'yesterday', 'custom'])],
            'from_date' => ['nullable', 'required_if:date_range,custom', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'required_if:date_range,custom', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $dateRange = $validated['date_range'] ?? 'today';
        [$startDate, $endDate] = $this->dateRange($dateRange, $validated);
        $managerCanMonitor = $this->canMonitorEmployees($user);

        $visibleTaskQuery = Task::query()->visibleTo($user)->availableInRecurringWindow();

        $eligibleSubtasks = Subtask::query()
            ->whereHas('task', fn (Builder $taskQuery) => $taskQuery->visibleTo($user)->availableInRecurringWindow())
            ->when(! $managerCanMonitor, fn (Builder $query) => $query->where('subtasks.assigned_to', $user->id))
            ->when(
                $managerCanMonitor && ! empty($validated['employee_id']),
                fn (Builder $query) => $query->where('subtasks.assigned_to', $validated['employee_id']),
            );

        $subtaskQuery = (clone $eligibleSubtasks)
            ->with([
                'task:id,task_code,title,priority,due_date,original_deadline',
                'assignee:id,name,designation',
                'dailyUpdates' => fn ($query) => $query
                    ->whereDate('work_date', '>=', $startDate->toDateString())
                    ->whereDate('work_date', '<=', $endDate->toDateString())
                    ->with('employee:id,name')
                    ->orderByDesc('work_date')
                    ->orderByDesc('created_at')
                    ->limit(1),
            ])
            ->orderByRaw("CASE (SELECT priority FROM tasks WHERE tasks.id = subtasks.task_id) WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE WHEN subtasks.due_date < ? AND subtasks.status != 'completed' THEN 0 ELSE 1 END", [today()->toDateString()])
            ->orderBy('subtasks.due_date')
            ->orderByDesc('subtasks.updated_at');

        $subtasks = $subtaskQuery->paginate(20)->withQueryString();
        $totalSubtasks = (clone $eligibleSubtasks)->count();
        $completedSubtasks = (clone $eligibleSubtasks)->where('subtasks.status', 'completed')->count();
        $inProgressSubtasks = (clone $eligibleSubtasks)->where('subtasks.status', 'in_progress')->count();
        $pendingSubtasks = (clone $eligibleSubtasks)->whereIn('subtasks.status', ['todo', 'assigned'])->count();
        $totalMainTasks = (clone $eligibleSubtasks)->distinct()->count('subtasks.task_id');
        $completedMainTasks = Task::query()
            ->availableInRecurringWindow()
            ->whereIn('id', (clone $eligibleSubtasks)->select('subtasks.task_id'))
            ->where('status', 'completed')
            ->count();
        $criticalSubtasks = (clone $eligibleSubtasks)
            ->whereHas('task', fn (Builder $query) => $query->where('priority', 'critical'))
            ->count();
        $statusPriorityCounts = (clone $eligibleSubtasks)
            ->join('tasks as dwr_tasks', 'dwr_tasks.id', '=', 'subtasks.task_id')
            ->selectRaw('dwr_tasks.priority as task_priority, subtasks.status, COUNT(*) as total')
            ->groupBy('dwr_tasks.priority', 'subtasks.status')
            ->get();
        $priorityTaskQuery = clone $visibleTaskQuery;
        if ($managerCanMonitor && ! empty($validated['employee_id'])) {
            $employeeId = (int) $validated['employee_id'];
            $priorityTaskQuery->where(function (Builder $query) use ($employeeId): void {
                $query->where('tasks.assigned_to', $employeeId)
                    ->orWhereHas('assignments', fn (Builder $assignmentQuery) => $assignmentQuery
                        ->where('user_id', $employeeId)
                        ->where('is_active', true)
                        ->whereNull('unassigned_at'))
                    ->orWhereHas('pocs', fn (Builder $pocQuery) => $pocQuery->whereKey($employeeId))
                    ->orWhereHas('subtasks', fn (Builder $subtaskQuery) => $subtaskQuery
                        ->where('subtasks.assigned_to', $employeeId));
            });
        }
        $priorityTaskCounts = (clone $priorityTaskQuery)
            ->selectRaw('tasks.priority, COUNT(*) as total')
            ->groupBy('tasks.priority')
            ->pluck('total', 'priority');
        $priorityTaskCountTotal = (clone $priorityTaskQuery)->count();
        $mainTaskStatusCounts = (clone $priorityTaskQuery)
            ->selectRaw('tasks.status, COUNT(*) as total')
            ->groupBy('tasks.status')
            ->pluck('total', 'status');
        $mainTaskStatusCountTotal = (clone $priorityTaskQuery)->count();
        $priorityBreakdown = collect(Task::PRIORITIES)
            ->mapWithKeys(fn (string $priority): array => [
                $priority => (int) ($priorityTaskCounts[$priority] ?? 0),
            ]);
        $mainTaskStatusBreakdown = collect(Task::STATUSES)
            ->mapWithKeys(fn (string $status): array => [
                $status => (int) ($mainTaskStatusCounts[$status] ?? 0),
            ]);
        $statusBreakdown = collect(Subtask::STATUSES)
            ->mapWithKeys(fn (string $status): array => [
                $status => (int) $statusPriorityCounts
                    ->where('status', $status)
                    ->sum('total'),
            ]);
        $mainTaskSummaries = (clone $eligibleSubtasks)
            ->join('tasks as dwr_summary_tasks', 'dwr_summary_tasks.id', '=', 'subtasks.task_id')
            ->select([
                'dwr_summary_tasks.id as task_id',
                'dwr_summary_tasks.task_code',
                'dwr_summary_tasks.title as task_title',
                'dwr_summary_tasks.priority as task_priority',
            ])
            ->selectRaw('COUNT(subtasks.id) as total_subtasks');

        foreach (Subtask::STATUSES as $status) {
            $mainTaskSummaries->selectRaw(
                'SUM(CASE WHEN subtasks.status = ? THEN 1 ELSE 0 END) as status_'.$status,
                [$status],
            );
        }

        $mainTaskSummaries = $mainTaskSummaries
            ->groupBy(
                'dwr_summary_tasks.id',
                'dwr_summary_tasks.task_code',
                'dwr_summary_tasks.title',
                'dwr_summary_tasks.priority',
            )
            ->orderByRaw("CASE dwr_summary_tasks.priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('dwr_summary_tasks.title')
            ->get();

        $mainTaskSubtasks = (clone $eligibleSubtasks)
            ->with('assignee:id,name')
            ->select([
                'subtasks.id',
                'subtasks.task_id',
                'subtasks.title',
                'subtasks.description',
                'subtasks.status',
                'subtasks.assigned_to',
                'subtasks.due_date',
                'subtasks.created_at',
                'subtasks.progress_percentage',
            ])
            ->orderBy('subtasks.title')
            ->get()
            ->groupBy('task_id');

        $dailyUpdates = SubtaskDailyUpdate::query()
            ->whereDate('work_date', '>=', $startDate->toDateString())
            ->whereDate('work_date', '<=', $endDate->toDateString())
            ->whereIn('subtask_id', (clone $eligibleSubtasks)->select('subtasks.id'))
            ->count();

        $tasks = (clone $visibleTaskQuery)
            ->orderBy('title')
            ->get(['id', 'task_code', 'title', 'company_id', 'department_id']);
        $employees = $tasks->isEmpty()
            ? collect()
            : User::query()
                ->where('status', 'active')
                ->where(function (Builder $query) use ($tasks): void {
                    foreach ($tasks as $task) {
                        $query->orWhere(fn (Builder $employeeQuery) => $employeeQuery
                            ->where('company_id', $task->company_id)
                            ->where('department_id', $task->department_id));
                    }
                })
                ->where(function (Builder $query): void {
                    $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'Employee'))
                        ->orWhereHas('primaryRole', fn (Builder $roleQuery) => $roleQuery->where('name', 'Employee'));
                })
                ->orderBy('name')
                ->get(['id', 'name', 'company_id', 'department_id']);

        $missingAccountabilities = EmployeeDailyAccountability::query()
            ->with('employee:id,name,designation')
            ->whereDate('activity_date', '>=', $startDate->toDateString())
            ->whereDate('activity_date', '<=', $endDate->toDateString())
            ->where('warning_status', true)
            ->when(
                ! $managerCanMonitor,
                fn (Builder $query) => $query->where('employee_id', $user->id),
            )
            ->when(
                $managerCanMonitor && ! empty($validated['employee_id']),
                fn (Builder $query) => $query->where('employee_id', $validated['employee_id']),
            )
            ->when(
                $managerCanMonitor,
                fn (Builder $query) => $query->whereIn('employee_id', $employees->pluck('id')),
            )
            ->orderByDesc('activity_date')
            ->get();

        $activities = TaskStatusHistory::query()
            ->with(['changedBy:id,name,designation', 'task:id,task_code,title', 'subtask:id,title'])
            ->whereHas('task', fn (Builder $query) => $query->visibleTo($user))
            ->whereNotNull('subtask_id')
            ->where(function (Builder $query): void {
                $query->where('event_type', 'like', 'subtask_%')
                    ->orWhere('event_type', 'work_update');
            })
            ->where(function (Builder $query) use ($startDate, $endDate): void {
                $query->whereDate('activity_date', '>=', $startDate->toDateString())
                    ->whereDate('activity_date', '<=', $endDate->toDateString())
                    ->orWhere(function (Builder $query) use ($startDate, $endDate): void {
                        $query->whereNull('activity_date')
                            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()]);
                    });
            })
            ->when(! $managerCanMonitor, fn (Builder $query) => $query->where('changed_by', $user->id))
            ->when(
                $managerCanMonitor && ! empty($validated['employee_id']),
                fn (Builder $query) => $query->where('changed_by', $validated['employee_id']),
            )
            ->latest('created_at')
            ->limit(15)
            ->get();

        return view('dwr_dashboard.index', [
            'subtasks' => $subtasks,
            'subtaskGroups' => $subtasks->getCollection()->groupBy('task_id'),
            'employees' => $employees,
            'tasks' => $tasks,
            'activities' => $activities,
            'missingAccountabilities' => $missingAccountabilities,
            'filters' => [
                ...$validated,
                'date_range' => $dateRange,
                'from_date' => $startDate->toDateString(),
                'to_date' => $endDate->toDateString(),
            ],
            'canMonitorEmployees' => $managerCanMonitor,
            'metrics' => [
                'total_subtasks' => $totalSubtasks,
                'total_main_tasks' => $totalMainTasks,
                'completed_tasks' => $completedMainTasks,
                'critical_subtasks' => $criticalSubtasks,
                'completed_subtasks' => $completedSubtasks,
                'in_progress_subtasks' => $inProgressSubtasks,
                'pending_subtasks' => $pendingSubtasks,
                'daily_updates' => $dailyUpdates,
                'missing_updates' => $missingAccountabilities->unique('employee_id')->count(),
            ],
            'priorityBreakdown' => $priorityBreakdown,
            'priorityTaskCountTotal' => $priorityTaskCountTotal,
            'mainTaskStatusBreakdown' => $mainTaskStatusBreakdown,
            'mainTaskStatusCountTotal' => $mainTaskStatusCountTotal,
            'statusBreakdown' => $statusBreakdown,
            'mainTaskSummaries' => $mainTaskSummaries,
            'mainTaskSubtasks' => $mainTaskSubtasks,
            'subtaskStatuses' => Subtask::STATUSES,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{Carbon, Carbon}
     */
    private function dateRange(string $range, array $filters): array
    {
        if ($range === 'custom') {
            return [
                Carbon::parse($filters['from_date'], config('app.timezone'))->startOfDay(),
                Carbon::parse($filters['to_date'], config('app.timezone'))->endOfDay(),
            ];
        }

        $date = $range === 'yesterday' ? today()->subDay() : today();

        return [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
    }

    private function canMonitorEmployees(User $user): bool
    {
        return $user->hasAssignedRole('Admin')
            || $user->hasAssignedRole('CEO')
            || $user->hasAssignedRole('Manager')
            || $user->hasAssignedRole('Team Leader');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportsController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        $request->merge([
            'from_date' => $request->input('from_date', today()->subDays(29)->toDateString()),
            'to_date' => $request->input('to_date', today()->toDateString()),
        ]);

        $filters = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);

        $from = today()->parse($filters['from_date'])->startOfDay();
        $to = today()->parse($filters['to_date'])->endOfDay();
        $user = $request->user();
        $scopedTasks = fn (): Builder => Task::query()
            ->visibleTo($user)
            ->whereBetween('tasks.created_at', [$from, $to]);

        $totalTasks = (clone $scopedTasks())->count();
        $completedTasksCount = (clone $scopedTasks())->where('tasks.status', 'completed')->count();
        $overdueTasksCount = (clone $scopedTasks())->overdue()->count();
        $activeTasksCount = (clone $scopedTasks())
            ->whereNotIn('tasks.status', ['completed', 'cancelled'])
            ->count();

        $statusCounts = (clone $scopedTasks())
            ->select('tasks.status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('tasks.status')
            ->pluck('total', 'status');
        $statusSummary = collect(Task::STATUSES)
            ->map(fn (string $status): array => [
                'key' => $status,
                'label' => Task::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status)),
                'count' => (int) ($statusCounts[$status] ?? 0),
            ]);

        $overdueTasks = (clone $scopedTasks())
            ->overdue()
            ->with(['department:id,name', 'assignee:id,name', 'activeAssignees:id,name'])
            ->orderByRaw('COALESCE(tasks.due_date, tasks.original_deadline) ASC')
            ->orderBy('tasks.id')
            ->limit(8)
            ->get();

        $completedTasks = (clone $scopedTasks())
            ->where('tasks.status', 'completed')
            ->with(['department:id,name', 'assignee:id,name', 'activeAssignees:id,name'])
            ->orderByDesc('tasks.completed_at')
            ->orderByDesc('tasks.updated_at')
            ->limit(8)
            ->get();

        $departmentSummary = (clone $scopedTasks())
            ->join('departments', 'departments.id', '=', 'tasks.department_id')
            ->whereNull('departments.deleted_at')
            ->select('departments.id', 'departments.name')
            ->selectRaw('COUNT(tasks.id) as total_tasks')
            ->selectRaw('SUM(CASE WHEN tasks.status = ? THEN 1 ELSE 0 END) as completed_tasks', ['completed'])
            ->selectRaw(
                'SUM(CASE WHEN tasks.status NOT IN (?, ?) AND COALESCE(tasks.due_date, tasks.original_deadline) < ? THEN 1 ELSE 0 END) as overdue_tasks',
                ['completed', 'cancelled', today()->toDateString()],
            )
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc('total_tasks')
            ->orderBy('departments.name')
            ->get();

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
        $reportTasks = (clone $scopedTasks())->select([
            'tasks.id',
            'tasks.department_id',
            'tasks.status',
            'tasks.due_date',
            'tasks.original_deadline',
        ]);

        $employeeSummary = DB::query()
            ->fromSub($currentAssignments, 'current_assignments')
            ->joinSub($reportTasks, 'report_tasks', 'report_tasks.id', '=', 'current_assignments.task_id')
            ->join('users', 'users.id', '=', 'current_assignments.user_id')
            ->leftJoin('departments', 'departments.id', '=', 'report_tasks.department_id')
            ->whereNull('users.deleted_at')
            ->select('users.id', 'users.name', 'departments.name as department_name')
            ->selectRaw('COUNT(DISTINCT report_tasks.id) as total_tasks')
            ->selectRaw('COUNT(DISTINCT CASE WHEN report_tasks.status = ? THEN report_tasks.id END) as completed_tasks', ['completed'])
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN report_tasks.status NOT IN (?, ?) AND COALESCE(report_tasks.due_date, report_tasks.original_deadline) < ? THEN report_tasks.id END) as overdue_tasks',
                ['completed', 'cancelled', today()->toDateString()],
            )
            ->groupBy('users.id', 'users.name', 'departments.name')
            ->orderByDesc('total_tasks')
            ->orderBy('users.name')
            ->limit(20)
            ->get();

        return view('reports.index', [
            'filters' => $filters,
            'totalTasks' => $totalTasks,
            'activeTasksCount' => $activeTasksCount,
            'completedTasksCount' => $completedTasksCount,
            'overdueTasksCount' => $overdueTasksCount,
            'statusSummary' => $statusSummary,
            'overdueTasks' => $overdueTasks,
            'completedTasks' => $completedTasks,
            'departmentSummary' => $departmentSummary,
            'employeeSummary' => $employeeSummary,
        ]);
    }
}

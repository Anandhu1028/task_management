<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class DashboardService
{
    /**
     * Resolve the normalized role name for the authenticated user.
     */
    public static function resolveUserRole(User $user): string
    {
        if ($user->hasAssignedRole('Admin')) {
            return 'Admin';
        }

        if ($user->hasAssignedRole('CEO')) {
            return 'CEO';
        }

        if ($user->hasAssignedRole('Team Leader')) {
            return 'Team Leader';
        }

        if ($user->hasAssignedRole('Employee')) {
            return 'Employee';
        }

        $spatieRole = $user->getRoleNames()->first();
        if ($spatieRole) {
            return $spatieRole;
        }

        return $user->primaryRole?->name ?? 'Employee';
    }

    /**
     * Check if user is allowed to access organization-wide data.
     */
    public static function canAccessOrganizationData(User $user): bool
    {
        $role = self::resolveUserRole($user);

        return in_array($role, ['Admin', 'CEO'], true);
    }

    /**
     * Parse date range selection into carbon start and end.
     *
     * @return array{start: Carbon, end: Carbon, label: string, key: string}
     */
    public function resolveDateRange(string $rangeKey, ?string $from = null, ?string $to = null): array
    {
        $now = now();

        return match ($rangeKey) {
            'today' => [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'label' => 'Today',
                'key' => 'today',
            ],
            'this_week' => [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy()->endOfWeek(),
                'label' => 'This Week',
                'key' => 'this_week',
            ],
            'this_month' => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
                'label' => 'This Month',
                'key' => 'this_month',
            ],
            'last_30_days' => [
                'start' => $now->copy()->subDays(29)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'label' => 'Last 30 Days',
                'key' => 'last_30_days',
            ],
            'custom' => [
                'start' => $from ? Carbon::parse($from)->startOfDay() : $now->copy()->subDays(6)->startOfDay(),
                'end' => $to ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfDay(),
                'label' => 'Custom Range',
                'key' => 'custom',
            ],
            default => [
                'start' => $now->copy()->subDays(6)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'label' => 'Last 7 Days',
                'key' => 'last_7_days',
            ],
        };
    }

    /**
     * Get complete dashboard payload for the user and role.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getDashboardData(User $user, string $role, array $params = []): array
    {
        $dateRangeKey = (string) ($params['date_range'] ?? 'last_7_days');
        $dateRange = $this->resolveDateRange(
            $dateRangeKey,
            isset($params['from_date']) ? (string) $params['from_date'] : null,
            isset($params['to_date']) ? (string) $params['to_date'] : null
        );

        $greeting = $this->getGreeting();

        return match ($role) {
            'Admin', 'CEO' => $this->buildAdminCeoDashboard($user, $role, $dateRange, $params, $greeting),
            'Team Leader' => $this->buildTeamLeaderDashboard($user, $dateRange, $params, $greeting),
            default => $this->buildEmployeeDashboard($user, $dateRange, $params, $greeting),
        };
    }

    /**
     * Build Admin or CEO dashboard data with full organizational scope and filters.
     *
     * @param  array{start: Carbon, end: Carbon, label: string, key: string}  $dateRange
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function buildAdminCeoDashboard(User $user, string $role, array $dateRange, array $params, string $greeting): array
    {
        $companyId = ! empty($params['company_id']) ? (int) $params['company_id'] : null;
        $departmentId = ! empty($params['department_id']) ? (int) $params['department_id'] : null;
        $teamLeaderId = ! empty($params['team_leader_id']) ? (int) $params['team_leader_id'] : null;
        $employeeId = ! empty($params['employee_id']) ? (int) $params['employee_id'] : null;

        // Base scoped task query for Admin/CEO based on selected filters
        $baseQuery = Task::query();

        if ($companyId) {
            $baseQuery->where('tasks.company_id', $companyId);
        }

        if ($departmentId) {
            $baseQuery->where('tasks.department_id', $departmentId);
        }

        if ($teamLeaderId) {
            $tl = User::find($teamLeaderId);
            if ($tl && $tl->department_id) {
                $baseQuery->where('tasks.department_id', $tl->department_id);
            } else {
                $baseQuery->assignedTo($teamLeaderId);
            }
        }

        if ($employeeId) {
            $baseQuery->assignedTo($employeeId);
        }

        // Summary Cards (6 cards)
        $totalCompanies = Company::query()->where('status', 'active')->count();
        $totalDepartments = Department::query()->where('status', 'active');
        if ($companyId) {
            $totalDepartments->where('company_id', $companyId);
        }
        $totalDepartmentsCount = $totalDepartments->count();

        $scopedCounts = $this->calculateTaskCounts(clone $baseQuery);
        $sparklines = $this->calculateSparklines(clone $baseQuery);

        $kpis = [
            [
                'icon' => 'bi-building',
                'label' => 'Total Companies',
                'value' => (string) $totalCompanies,
                'meta' => 'Active organizations',
                'tone' => 'default',
                'meta_type' => 'default',
                'spark' => $this->getStaticTrendPoints($totalCompanies),
                'spark_color' => '#2563eb',
                'icon_style' => 'primary',
            ],
            [
                'icon' => 'bi-diagram-3',
                'label' => 'Total Departments',
                'value' => (string) $totalDepartmentsCount,
                'meta' => $companyId ? 'In selected company' : 'Across organization',
                'tone' => 'default',
                'meta_type' => 'default',
                'spark' => $this->getStaticTrendPoints($totalDepartmentsCount),
                'spark_color' => '#8b5cf6',
                'icon_style' => 'primary',
            ],
            [
                'icon' => 'bi-layers',
                'label' => 'Total Tasks',
                'value' => (string) $scopedCounts['total'],
                'meta' => $scopedCounts['total_trend'],
                'tone' => 'default',
                'meta_type' => $scopedCounts['total'] > 0 ? 'up' : 'default',
                'spark' => $sparklines['total'],
                'spark_color' => '#2563eb',
                'icon_style' => 'primary',
            ],
            [
                'icon' => 'bi-arrow-repeat',
                'label' => 'In Progress',
                'value' => (string) $scopedCounts['in_progress'],
                'meta' => $scopedCounts['total'] > 0 ? round(($scopedCounts['in_progress'] / $scopedCounts['total']) * 100).'% of tasks' : '0% of tasks',
                'tone' => 'default',
                'meta_type' => 'dot-orange',
                'spark' => $sparklines['in_progress'],
                'spark_color' => '#f97316',
                'icon_style' => 'primary',
            ],
            [
                'icon' => 'bi-exclamation-circle',
                'label' => 'Overdue',
                'value' => (string) $scopedCounts['overdue'],
                'meta' => $scopedCounts['overdue'] > 0 ? 'Requires attention' : 'No overdue tasks',
                'tone' => 'danger',
                'meta_type' => 'dot-danger',
                'spark' => $sparklines['overdue'],
                'spark_color' => '#ef4444',
                'icon_style' => 'danger',
            ],
            [
                'icon' => 'bi-exclamation-triangle',
                'label' => 'At Risk',
                'value' => (string) $scopedCounts['at_risk'],
                'meta' => $scopedCounts['at_risk'] > 0 ? 'Deadline or delivery risk' : 'No at-risk tasks',
                'tone' => 'warning',
                'meta_type' => 'dot-orange',
                'spark' => $this->getStaticTrendPoints($scopedCounts['at_risk']),
                'spark_color' => '#d97706',
                'icon_style' => 'warning',
            ],
            [
                'icon' => 'bi-check-circle',
                'label' => 'Completed',
                'value' => (string) $scopedCounts['completed'],
                'meta' => $scopedCounts['completion_rate'].'% completion rate',
                'tone' => 'success',
                'meta_type' => 'up',
                'spark' => $sparklines['completed'],
                'spark_color' => '#22c55e',
                'icon_style' => 'success',
            ],
        ];

        // Task Completion Graph
        $completionChart = $this->buildCompletionChartData(clone $baseQuery, $dateRange);

        // Task Status Donut
        $statusDistribution = $this->buildStatusDistribution(clone $baseQuery);

        // Priority Distribution
        $priorityDistribution = $this->buildPriorityDistribution(clone $baseQuery);

        // Management Analytics: Companies, Departments, Team Leaders, Employees
        $companyAnalytics = $this->buildCompanyAnalytics();
        $departmentAnalytics = $this->buildDepartmentAnalytics($companyId);
        $departmentPerformanceAnalytics = $this->buildDepartmentAnalytics();
        $employeeHeadcountByCompany = $this->buildEmployeeHeadcountByCompany();
        $teamLeaderAnalytics = $this->buildTeamLeaderAnalytics($companyId, $departmentId);
        $employeeAnalytics = $this->buildEmployeeAnalytics($companyId, $departmentId);

        // Recent Tasks, Upcoming Deadlines, Overdue Tasks
        $recentTasks = (clone $baseQuery)
            ->with(['company:id,name', 'department:id,name', 'assignee:id,name,designation', 'pocs:id,name'])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        $upcomingDeadlines = (clone $baseQuery)
            ->with(['company:id,name', 'department:id,name', 'assignee:id,name'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereRaw('COALESCE(due_date, original_deadline) IS NOT NULL')
            ->whereRaw('COALESCE(due_date, original_deadline) >= ?', [today()->toDateString()])
            ->orderByRaw('COALESCE(due_date, original_deadline) ASC')
            ->limit(8)
            ->get();

        $overdueTasks = (clone $baseQuery)
            ->with(['company:id,name', 'department:id,name', 'assignee:id,name'])
            ->overdue()
            ->orderByRaw('COALESCE(due_date, original_deadline) ASC')
            ->limit(8)
            ->get();

        // Filter options for Admin/CEO dropdowns
        $filterCompanies = Company::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $filterDepartments = Department::query()->where('status', 'active');
        if ($companyId) {
            $filterDepartments->where('company_id', $companyId);
        }
        $filterDepartments = $filterDepartments->orderBy('name')->get(['id', 'name', 'company_id']);

        $filterTeamLeaders = User::query()
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereHas('primaryRole', fn (Builder $roleQuery) => $roleQuery->where('name', 'Team Leader'))
                    ->orWhereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'Team Leader'));
            });
        if ($companyId) {
            $filterTeamLeaders->where('company_id', $companyId);
        }
        if ($departmentId) {
            $filterTeamLeaders->where('department_id', $departmentId);
        }
        $filterTeamLeaders = $filterTeamLeaders->orderBy('name')->get(['id', 'name', 'department_id', 'company_id']);

        $filterEmployees = User::query()
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereHas('primaryRole', fn (Builder $roleQuery) => $roleQuery->where('name', 'Employee'))
                    ->orWhereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'Employee'));
            });
        if ($companyId) {
            $filterEmployees->where('company_id', $companyId);
        }
        if ($departmentId) {
            $filterEmployees->where('department_id', $departmentId);
        }
        $filterEmployees = $filterEmployees->orderBy('name')->get(['id', 'name', 'department_id', 'company_id']);

        return [
            'role' => $role,
            'greeting' => $greeting,
            'user' => $user,
            'kpis' => $kpis,
            'dateRange' => $dateRange,
            'completionChart' => $completionChart,
            'statusDistribution' => $statusDistribution,
            'priorityDistribution' => $priorityDistribution,
            'companyAnalytics' => $companyAnalytics,
            'departmentAnalytics' => $departmentAnalytics,
            'departmentPerformanceAnalytics' => $departmentPerformanceAnalytics,
            'employeeHeadcountByCompany' => $employeeHeadcountByCompany,
            'teamLeaderAnalytics' => $teamLeaderAnalytics,
            'employeeAnalytics' => $employeeAnalytics,
            'recentTasks' => $recentTasks,
            'upcomingDeadlines' => $upcomingDeadlines,
            'overdueTasks' => $overdueTasks,
            'selectedFilters' => [
                'date_range' => $dateRange['key'],
                'from_date' => $params['from_date'] ?? null,
                'to_date' => $params['to_date'] ?? null,
                'company_id' => $companyId,
                'department_id' => $departmentId,
                'team_leader_id' => $teamLeaderId,
                'employee_id' => $employeeId,
            ],
            'filterOptions' => [
                'companies' => $filterCompanies,
                'departments' => $filterDepartments,
                'teamLeaders' => $filterTeamLeaders,
                'employees' => $filterEmployees,
            ],
        ];
    }

    /**
     * Build Team Leader dashboard strictly scoped to the TL's department.
     *
     * @param  array{start: Carbon, end: Carbon, label: string, key: string}  $dateRange
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function buildTeamLeaderDashboard(User $user, array $dateRange, array $params, string $greeting): array
    {
        $departmentId = $user->department_id;
        $employeeFilterId = ! empty($params['employee_id']) ? (int) $params['employee_id'] : null;

        // Base department query
        $deptQuery = Task::query();
        if ($departmentId) {
            $deptQuery->where('tasks.department_id', $departmentId);
        } else {
            $deptQuery->whereRaw('1 = 0');
        }

        // Apply employee filter if the employee is in the TL's department
        if ($employeeFilterId) {
            $isDeptEmployee = User::query()
                ->where('id', $employeeFilterId)
                ->where('department_id', $departmentId)
                ->exists();

            if ($isDeptEmployee) {
                $deptQuery->assignedTo($employeeFilterId);
            }
        }

        // Summary Cards: Department Employees, Department Total Tasks, In Progress, Overdue, Completed
        $totalDeptEmployees = $departmentId
            ? User::query()->where('department_id', $departmentId)->where('status', 'active')->count()
            : 0;

        $scopedCounts = $this->calculateTaskCounts(clone $deptQuery);
        $sparklines = $this->calculateSparklines(clone $deptQuery);

        $kpis = [
            [
                'icon' => 'bi-people',
                'label' => 'Department Employees',
                'value' => (string) $totalDeptEmployees,
                'meta' => $totalDeptEmployees.' active members',
                'tone' => 'default',
                'meta_type' => 'default',
                'spark' => $this->getStaticTrendPoints($totalDeptEmployees),
                'spark_color' => '#8b5cf6',
                'icon_style' => 'primary',
            ],
            [
                'icon' => 'bi-folder2',
                'label' => 'Department Total Tasks',
                'value' => (string) $scopedCounts['total'],
                'meta' => $scopedCounts['total_trend'],
                'tone' => 'default',
                'meta_type' => $scopedCounts['total'] > 0 ? 'up' : 'default',
                'spark' => $sparklines['total'],
                'spark_color' => '#2563eb',
                'icon_style' => 'primary',
            ],
            [
                'icon' => 'bi-arrow-repeat',
                'label' => 'In Progress',
                'value' => (string) $scopedCounts['in_progress'],
                'meta' => $scopedCounts['total'] > 0 ? round(($scopedCounts['in_progress'] / $scopedCounts['total']) * 100).'% of dept tasks' : '0% of tasks',
                'tone' => 'default',
                'meta_type' => 'dot-orange',
                'spark' => $sparklines['in_progress'],
                'spark_color' => '#f97316',
                'icon_style' => 'primary',
            ],
            [
                'icon' => 'bi-exclamation-circle',
                'label' => 'Overdue',
                'value' => (string) $scopedCounts['overdue'],
                'meta' => $scopedCounts['overdue'] > 0 ? 'Requires attention' : 'No overdue tasks',
                'tone' => 'danger',
                'meta_type' => 'dot-danger',
                'spark' => $sparklines['overdue'],
                'spark_color' => '#ef4444',
                'icon_style' => 'danger',
            ],
            [
                'icon' => 'bi-exclamation-triangle',
                'label' => 'At Risk',
                'value' => (string) $scopedCounts['at_risk'],
                'meta' => $scopedCounts['at_risk'] > 0 ? 'Deadline or delivery risk' : 'No at-risk tasks',
                'tone' => 'warning',
                'meta_type' => 'dot-orange',
                'spark' => $this->getStaticTrendPoints($scopedCounts['at_risk']),
                'spark_color' => '#d97706',
                'icon_style' => 'warning',
            ],
            [
                'icon' => 'bi-check-circle',
                'label' => 'Completed',
                'value' => (string) $scopedCounts['completed'],
                'meta' => $scopedCounts['completion_rate'].'% completion rate',
                'tone' => 'success',
                'meta_type' => 'up',
                'spark' => $sparklines['completed'],
                'spark_color' => '#22c55e',
                'icon_style' => 'success',
            ],
        ];

        // Department Task Completion Graph
        $completionChart = $this->buildCompletionChartData(clone $deptQuery, $dateRange);

        // Department Task Status Donut
        $statusDistribution = $this->buildStatusDistribution(clone $deptQuery);

        // Department Employees' Workload
        $departmentWorkload = $this->buildDepartmentEmployeesWorkload($departmentId);

        // Recent Department Tasks
        $recentDeptTasks = (clone $deptQuery)
            ->with(['assignee:id,name,designation', 'pocs:id,name', 'department:id,name'])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        // Team Leader's Own Tasks (My Tasks)
        $personalTasksQuery = Task::query()->assignedTo($user->id);
        $personalCounts = $this->calculateTaskCounts(clone $personalTasksQuery);
        $personalRecentTasks = (clone $personalTasksQuery)
            ->with(['company:id,name', 'department:id,name'])
            ->latest('updated_at')
            ->limit(6)
            ->get();

        // Upcoming Deadlines (Department-wide)
        $upcomingDeadlines = (clone $deptQuery)
            ->with(['assignee:id,name'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereRaw('COALESCE(due_date, original_deadline) IS NOT NULL')
            ->whereRaw('COALESCE(due_date, original_deadline) >= ?', [today()->toDateString()])
            ->orderByRaw('COALESCE(due_date, original_deadline) ASC')
            ->limit(8)
            ->get();

        $overdueTasks = (clone $deptQuery)
            ->with(['assignee:id,name'])
            ->overdue()
            ->orderByRaw('COALESCE(due_date, original_deadline) ASC')
            ->limit(8)
            ->get();

        // Department employees for filtering
        $deptEmployees = $departmentId
            ? User::query()->where('department_id', $departmentId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'designation'])
            : collect();

        return [
            'role' => 'Team Leader',
            'greeting' => $greeting,
            'user' => $user,
            'department' => $user->department,
            'kpis' => $kpis,
            'dateRange' => $dateRange,
            'completionChart' => $completionChart,
            'statusDistribution' => $statusDistribution,
            'departmentWorkload' => $departmentWorkload,
            'recentDeptTasks' => $recentDeptTasks,
            'personalCounts' => $personalCounts,
            'personalRecentTasks' => $personalRecentTasks,
            'upcomingDeadlines' => $upcomingDeadlines,
            'overdueTasks' => $overdueTasks,
            'selectedFilters' => [
                'date_range' => $dateRange['key'],
                'from_date' => $params['from_date'] ?? null,
                'to_date' => $params['to_date'] ?? null,
                'employee_id' => $employeeFilterId,
                'department_id' => $departmentId,
            ],
            'filterOptions' => [
                'employees' => $deptEmployees,
            ],
        ];
    }

    /**
     * Build Employee dashboard strictly locked to the authenticated employee's tasks.
     *
     * @param  array{start: Carbon, end: Carbon, label: string, key: string}  $dateRange
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function buildEmployeeDashboard(User $user, array $dateRange, array $params, string $greeting): array
    {
        $myTasksQuery = Task::query()->visibleTo($user);

        // Summary cards keep workflow states and deadline conditions separate.
        $scopedCounts = $this->calculateTaskCounts(clone $myTasksQuery);
        $sparklines = $this->calculateSparklines(clone $myTasksQuery);

        $kpis = [
            [
                'icon' => 'bi-layers',
                'label' => 'My Tasks',
                'value' => (string) $scopedCounts['total'],
                'meta' => $scopedCounts['total_trend'],
                'tone' => 'default',
                'meta_type' => $scopedCounts['total'] > 0 ? 'up' : 'default',
                'spark' => $sparklines['total'],
                'spark_color' => '#2563eb',
                'icon_style' => 'primary',
            ],
            [
                'icon' => 'bi-arrow-repeat',
                'label' => 'In Progress',
                'value' => (string) $scopedCounts['in_progress'],
                'meta' => $scopedCounts['total'] > 0 ? round(($scopedCounts['in_progress'] / $scopedCounts['total']) * 100).'% of my tasks' : '0% of tasks',
                'tone' => 'default',
                'meta_type' => 'dot-orange',
                'spark' => $sparklines['in_progress'],
                'spark_color' => '#f97316',
                'icon_style' => 'primary',
            ],
            [
                'icon' => 'bi-exclamation-circle',
                'label' => 'Overdue',
                'value' => (string) $scopedCounts['overdue'],
                'meta' => $scopedCounts['overdue'] > 0 ? 'Requires immediate action' : 'No overdue tasks',
                'tone' => 'danger',
                'meta_type' => 'dot-danger',
                'spark' => $sparklines['overdue'],
                'spark_color' => '#ef4444',
                'icon_style' => 'danger',
            ],
            [
                'icon' => 'bi-exclamation-triangle',
                'label' => 'At Risk',
                'value' => (string) $scopedCounts['at_risk'],
                'meta' => $scopedCounts['at_risk'] > 0 ? 'Deadline or delivery risk' : 'No at-risk tasks',
                'tone' => 'warning',
                'meta_type' => 'dot-orange',
                'spark' => $this->getStaticTrendPoints($scopedCounts['at_risk']),
                'spark_color' => '#d97706',
                'icon_style' => 'warning',
            ],
            [
                'icon' => 'bi-check-circle',
                'label' => 'Completed',
                'value' => (string) $scopedCounts['completed'],
                'meta' => $scopedCounts['completion_rate'].'% completion rate',
                'tone' => 'success',
                'meta_type' => 'up',
                'spark' => $sparklines['completed'],
                'spark_color' => '#22c55e',
                'icon_style' => 'success',
            ],
        ];

        // My Task Completion Graph
        $completionChart = $this->buildCompletionChartData(clone $myTasksQuery, $dateRange);

        // My Task Status Donut
        $statusDistribution = $this->buildStatusDistribution(clone $myTasksQuery);

        // My Workload Chart (Distribution across Weekdays / Statuses)
        $myWorkload = $this->buildEmployeeWorkloadData($user->id);

        // My Priority Overview
        $priorityDistribution = $this->buildPriorityDistribution(clone $myTasksQuery);

        // Recent Assigned Tasks
        $recentTasks = (clone $myTasksQuery)
            ->with(['company:id,name', 'department:id,name'])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        // Upcoming Deadlines
        $upcomingDeadlines = (clone $myTasksQuery)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereRaw('COALESCE(due_date, original_deadline) IS NOT NULL')
            ->whereRaw('COALESCE(due_date, original_deadline) >= ?', [today()->toDateString()])
            ->orderByRaw('COALESCE(due_date, original_deadline) ASC')
            ->limit(8)
            ->get();

        $overdueTasks = (clone $myTasksQuery)
            ->overdue()
            ->orderByRaw('COALESCE(due_date, original_deadline) ASC')
            ->limit(8)
            ->get();

        return [
            'role' => 'Employee',
            'greeting' => $greeting,
            'user' => $user,
            'kpis' => $kpis,
            'dateRange' => $dateRange,
            'completionChart' => $completionChart,
            'statusDistribution' => $statusDistribution,
            'myWorkload' => $myWorkload,
            'priorityDistribution' => $priorityDistribution,
            'recentTasks' => $recentTasks,
            'upcomingDeadlines' => $upcomingDeadlines,
            'overdueTasks' => $overdueTasks,
            'selectedFilters' => [
                'date_range' => $dateRange['key'],
                'from_date' => $params['from_date'] ?? null,
                'to_date' => $params['to_date'] ?? null,
                'employee_id' => $user->id,
            ],
        ];
    }

    /**
     * Compute task counts safely and efficiently using SQL aggregation.
     *
     * @return array<string, int|float|string>
     */
    public function calculateTaskCounts(Builder $query): array
    {
        $statusCounts = (clone $query)
            ->select('tasks.status')
            ->selectRaw('COUNT(DISTINCT tasks.id) as task_count')
            ->groupBy('tasks.status')
            ->pluck('task_count', 'tasks.status')
            ->all();
        $total = (clone $query)->count('tasks.id');
        $statusCounts = array_replace(
            array_fill_keys(Task::STATUSES, 0),
            array_intersect_key($statusCounts, array_fill_keys(Task::STATUSES, true)),
        );
        $overdueCount = (clone $query)->overdue()->count('tasks.id');
        $atRiskCount = (clone $query)->atRisk()->count('tasks.id');

        $completionRate = $total > 0 ? round(($statusCounts['completed'] / $total) * 100, 1) : 0.0;

        // Trend comparison: Count tasks created in the last 7 days
        $recentCreated = (clone $query)
            ->where('tasks.created_at', '>=', now()->subDays(7))
            ->count('tasks.id');

        $totalTrend = $recentCreated > 0 ? "+{$recentCreated} this week" : 'No new tasks this week';

        return [
            'total' => $total,
            ...$statusCounts,
            'overdue' => $overdueCount,
            'at_risk' => $atRiskCount,
            'completion_rate' => $completionRate,
            'total_trend' => $totalTrend,
        ];
    }

    /**
     * Compute real 7-day sparkline arrays for each summary card.
     *
     * @return array{total: array<int>, in_progress: array<int>, overdue: array<int>, completed: array<int>}
     */
    private function calculateSparklines(Builder $query): array
    {
        $sparklines = [
            'total' => [],
            'in_progress' => [],
            'overdue' => [],
            'completed' => [],
        ];

        $today = today();

        for ($i = 6; $i >= 0; $i--) {
            $dayDate = $today->copy()->subDays($i)->toDateString();
            $dayStart = $today->copy()->subDays($i)->startOfDay();
            $dayEnd = $today->copy()->subDays($i)->endOfDay();

            // Total tasks active up to that day
            $totalOnDay = (clone $query)
                ->where('tasks.created_at', '<=', $dayEnd)
                ->count('tasks.id');

            // Completed on that day
            $completedOnDay = (clone $query)
                ->where('tasks.status', 'completed')
                ->whereBetween(DB::raw('COALESCE(tasks.completed_at, tasks.updated_at)'), [$dayStart, $dayEnd])
                ->count('tasks.id');

            // In progress on that day
            $inProgressOnDay = (clone $query)
                ->where('tasks.status', 'in_progress')
                ->where('tasks.created_at', '<=', $dayEnd)
                ->count('tasks.id');

            // Overdue on that day
            $overdueOnDay = (clone $query)
                ->whereNotIn('tasks.status', ['completed', 'cancelled'])
                ->whereRaw('COALESCE(tasks.due_date, tasks.original_deadline) < ?', [$dayDate])
                ->where('tasks.created_at', '<=', $dayEnd)
                ->count('tasks.id');

            $sparklines['total'][] = max(0, $totalOnDay);
            $sparklines['in_progress'][] = max(0, $inProgressOnDay);
            $sparklines['completed'][] = max(0, $completedOnDay);
            $sparklines['overdue'][] = max(0, $overdueOnDay);
        }

        return $sparklines;
    }

    /**
     * Task completion chart dataset grouped by day within the specified date range.
     *
     * @param  array{start: Carbon, end: Carbon, label: string, key: string}  $dateRange
     * @return array{labels: array<string>, values: array<int>, peakDay: ?string, peakCount: int, totalCompleted: int}
     */
    public function buildCompletionChartData(Builder $query, array $dateRange): array
    {
        $start = $dateRange['start'];
        $end = $dateRange['end'];

        $completedRecords = (clone $query)
            ->where('tasks.status', 'completed')
            ->whereBetween(DB::raw('COALESCE(tasks.completed_at, tasks.updated_at)'), [$start, $end])
            ->selectRaw('DATE(COALESCE(tasks.completed_at, tasks.updated_at)) as comp_date, COUNT(DISTINCT tasks.id) as cnt')
            ->groupBy('comp_date')
            ->pluck('cnt', 'comp_date')
            ->all();

        $labels = [];
        $values = [];
        $peakCount = 0;
        $peakDay = null;
        $totalCompleted = 0;

        $diffDays = $start->diffInDays($end);

        if ($diffDays <= 1) {
            // Group by 4-hour intervals for today
            for ($h = 0; $h < 24; $h += 4) {
                $timeLabel = Carbon::createFromTime($h, 0)->format('g A');
                $labels[] = $timeLabel;
                $slotCount = (clone $query)
                    ->where('tasks.status', 'completed')
                    ->whereBetween(DB::raw('COALESCE(tasks.completed_at, tasks.updated_at)'), [
                        $start->copy()->addHours($h),
                        $start->copy()->addHours($h + 4),
                    ])
                    ->count('tasks.id');

                $values[] = $slotCount;
                $totalCompleted += $slotCount;
                if ($slotCount > $peakCount) {
                    $peakCount = $slotCount;
                    $peakDay = $timeLabel;
                }
            }
        } else {
            $current = $start->copy();
            while ($current->lte($end)) {
                $dateKey = $current->toDateString();
                $dayLabel = $diffDays <= 7 ? $current->format('D') : $current->format('M d');
                $labels[] = $dayLabel;

                $count = (int) ($completedRecords[$dateKey] ?? 0);
                $values[] = $count;
                $totalCompleted += $count;

                if ($count > $peakCount) {
                    $peakCount = $count;
                    $peakDay = $dayLabel;
                }

                $current->addDay();
            }
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'peakDay' => $peakDay,
            'peakCount' => $peakCount,
            'totalCompleted' => $totalCompleted,
        ];
    }

    /**
     * Task status breakdown for Donut Chart.
     *
     * @return array{total: int, items: array<array{label: string, count: int, percent: int, color: string, key: string}>}
     */
    public function buildStatusDistribution(Builder $query): array
    {
        $statusCounts = (clone $query)
            ->select('tasks.status')
            ->selectRaw('COUNT(DISTINCT tasks.id) as cnt')
            ->groupBy('tasks.status')
            ->pluck('cnt', 'tasks.status')
            ->all();

        $colors = [
            'notified' => '#64748b',
            'unassigned' => '#2563eb',
            'assigned' => '#0ea5e9',
            'in_progress' => '#f97316',
            'on_hold' => '#a16207',
            'submitted_for_review' => '#8b5cf6',
            'rework' => '#f97316',
            'completed' => '#22c55e',
            'cancelled' => '#64748b',
        ];
        $total = array_sum(array_map(
            static fn (string $status): int => (int) ($statusCounts[$status] ?? 0),
            Task::STATUSES,
        ));
        $items = [];

        foreach (Task::STATUSES as $status) {
            $count = (int) ($statusCounts[$status] ?? 0);
            $items[] = [
                'key' => $status,
                'label' => Task::STATUS_LABELS[$status],
                'count' => $count,
                'percent' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
                'color' => $colors[$status],
            ];
        }

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Priority overview breakdown.
     *
     * @return array<array{label: string, count: int, percent: int, tone: string}>
     */
    public function buildPriorityDistribution(Builder $query): array
    {
        $counts = (clone $query)
            ->selectRaw('tasks.priority, COUNT(DISTINCT tasks.id) as cnt')
            ->groupBy('tasks.priority')
            ->pluck('cnt', 'tasks.priority')
            ->all();

        $critical = (int) ($counts['critical'] ?? 0);
        $high = (int) ($counts['high'] ?? 0);
        $medium = (int) ($counts['medium'] ?? 0);
        $low = (int) ($counts['low'] ?? 0);

        $total = $critical + $high + $medium + $low;
        $calcPercent = fn (int $val) => $total > 0 ? (int) round(($val / $total) * 100) : 0;

        return [
            ['label' => 'Critical', 'count' => $critical, 'percent' => $calcPercent($critical), 'tone' => 'critical'],
            ['label' => 'High', 'count' => $high, 'percent' => $calcPercent($high), 'tone' => 'high'],
            ['label' => 'Medium', 'count' => $medium, 'percent' => $calcPercent($medium), 'tone' => 'medium'],
            ['label' => 'Low', 'count' => $low, 'percent' => $calcPercent($low), 'tone' => 'low'],
        ];
    }

    /**
     * Company-wise analytics for Admin and CEO.
     *
     * @return array<array<string, int|float|string>>
     */
    public function buildCompanyAnalytics(): array
    {
        $query = Company::query()
            ->where('companies.status', 'active')
            ->leftJoin('tasks', function ($join) {
                $join->on('tasks.company_id', '=', 'companies.id')
                    ->whereNull('tasks.deleted_at');
            })
            ->selectRaw('companies.id, companies.name')
            ->selectRaw('COUNT(DISTINCT tasks.id) as total_tasks');
        $this->addStatusCountColumns($query);
        $this->addDeadlineConditionCountColumns($query);
        $rows = $query
            ->groupBy('companies.id', 'companies.name')
            ->orderBy('companies.name')
            ->get();

        return $rows->map(function ($row): array {
            $total = (int) $row->total_tasks;
            $statusCounts = $this->statusCountsFromRow($row);
            $completed = $statusCounts['completed'];

            return [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'total_tasks' => $total,
                ...$statusCounts,
                'at_risk' => (int) $row->at_risk_count,
                'overdue' => (int) $row->overdue_count,
                'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
            ];
        })->all();
    }

    /**
     * Department-wise analytics for Admin and CEO.
     *
     * @return array<array<string, int|float|string>>
     */
    public function buildDepartmentAnalytics(?int $filterCompanyId = null): array
    {
        $query = Department::query()
            ->where('departments.status', 'active')
            ->join('companies', 'companies.id', '=', 'departments.company_id')
            ->where('companies.status', 'active')
            ->leftJoin('tasks', function ($join) {
                $join->on('tasks.department_id', '=', 'departments.id')
                    ->whereNull('tasks.deleted_at');
            });

        if ($filterCompanyId) {
            $query->where('departments.company_id', $filterCompanyId);
        }

        $query
            ->selectRaw('departments.id, departments.name, departments.company_id, companies.name as company_name')
            ->selectRaw('COUNT(DISTINCT tasks.id) as total_tasks');
        $this->addStatusCountColumns($query);
        $this->addDeadlineConditionCountColumns($query);
        $rows = $query
            ->groupBy('departments.id', 'departments.name', 'departments.company_id', 'companies.name')
            ->orderBy('companies.name')
            ->orderBy('departments.name')
            ->get();

        return $rows->map(function ($row): array {
            $total = (int) $row->total_tasks;
            $statusCounts = $this->statusCountsFromRow($row);
            $completed = $statusCounts['completed'];

            return [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'company_id' => (int) $row->company_id,
                'company_name' => (string) $row->company_name,
                'total_tasks' => $total,
                ...$statusCounts,
                'at_risk' => (int) $row->at_risk_count,
                'overdue' => (int) $row->overdue_count,
                'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
            ];
        })->all();
    }

    /**
     * Active employee headcount grouped by active company.
     *
     * @return array<array{id: int, name: string, employee_count: int}>
     */
    public function buildEmployeeHeadcountByCompany(): array
    {
        return Company::query()
            ->where('companies.status', 'active')
            ->withCount([
                'users as employee_count' => function (Builder $query): void {
                    $query->where('users.status', 'active')
                        ->where(function (Builder $query): void {
                            $query->whereHas('primaryRole', fn (Builder $roleQuery) => $roleQuery->where('name', 'Employee'))
                                ->orWhereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'Employee'));
                        });
                },
            ])
            ->orderBy('companies.name')
            ->get(['companies.id', 'companies.name'])
            ->map(fn (Company $company): array => [
                'id' => (int) $company->id,
                'name' => (string) $company->name,
                'employee_count' => (int) $company->employee_count,
            ])
            ->all();
    }

    /**
     * Team Leader-wise performance analytics.
     *
     * @return array<array{id: int, name: string, department_name: string, company_name: string, personal_tasks_count: int, department_tasks_count: int, in_progress: int, completed: int, at_risk: int, overdue: int, completion_rate: float}>
     */
    public function buildTeamLeaderAnalytics(?int $companyId = null, ?int $departmentId = null): array
    {
        $todayStr = today()->toDateString();

        $query = User::query()
            ->where('users.status', 'active')
            ->where(function (Builder $query): void {
                $query->whereHas('primaryRole', fn (Builder $roleQuery) => $roleQuery->where('name', 'Team Leader'))
                    ->orWhereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'Team Leader'));
            })
            ->with(['company:id,name', 'department:id,name']);

        if ($companyId) {
            $query->where('users.company_id', $companyId);
        }

        if ($departmentId) {
            $query->where('users.department_id', $departmentId);
        }

        $teamLeaders = $query->get();

        return $teamLeaders->map(function (User $tl) use ($todayStr): array {
            $personalStats = Task::query()
                ->assignedTo($tl->id)
                ->selectRaw('COUNT(id) as total')
                ->selectRaw("COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress")
                ->selectRaw("COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed")
                ->selectRaw("COUNT(CASE WHEN status NOT IN ('completed', 'cancelled') AND COALESCE(due_date, original_deadline) < ? THEN 1 END) as overdue", [$todayStr])
                ->selectRaw("COUNT(CASE WHEN status NOT IN ('completed', 'cancelled') AND (risk_reason IS NOT NULL OR COALESCE(due_date, original_deadline) BETWEEN ? AND ?) AND (COALESCE(due_date, original_deadline) IS NULL OR COALESCE(due_date, original_deadline) >= ?) THEN 1 END) as at_risk", [
                    $todayStr,
                    today()->addDays(max(0, (int) config('tasks.deadline_risk_days', 2)))->toDateString(),
                    $todayStr,
                ])
                ->first();

            $deptCount = $tl->department_id
                ? Task::query()->where('department_id', $tl->department_id)->count()
                : 0;

            $total = (int) ($personalStats?->total ?? 0);
            $completed = (int) ($personalStats?->completed ?? 0);

            return [
                'id' => $tl->id,
                'name' => $tl->name,
                'department_name' => $tl->department?->name ?? 'Unassigned',
                'company_name' => $tl->company?->name ?? 'Unassigned',
                'personal_tasks_count' => $total,
                'department_tasks_count' => $deptCount,
                'in_progress' => (int) ($personalStats?->in_progress ?? 0),
                'completed' => $completed,
                'overdue' => (int) ($personalStats?->overdue ?? 0),
                'at_risk' => (int) ($personalStats?->at_risk ?? 0),
                'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
            ];
        })->all();
    }

    /**
     * Employee-wise performance analytics.
     *
     * @return array<array<string, int|float|string>>
     */
    public function buildEmployeeAnalytics(?int $companyId = null, ?int $departmentId = null): array
    {
        $query = User::query()
            ->where('users.status', 'active')
            ->where(function (Builder $query): void {
                $query->whereHas('primaryRole', fn (Builder $roleQuery) => $roleQuery->where('name', 'Employee'))
                    ->orWhereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'Employee'));
            })
            ->with(['company:id,name', 'department:id,name']);

        if ($companyId) {
            $query->where('users.company_id', $companyId);
        }

        if ($departmentId) {
            $query->where('users.department_id', $departmentId);
        }

        $employees = $query->limit(25)->get();

        return $employees->map(function (User $emp): array {
            $statsQuery = Task::query()
                ->assignedTo($emp->id)
                ->selectRaw('COUNT(DISTINCT tasks.id) as total');
            $this->addStatusCountColumns($statsQuery);
            $this->addDeadlineConditionCountColumns($statsQuery);
            $stats = $statsQuery->first();

            $total = (int) ($stats?->total ?? 0);
            $statusCounts = $this->statusCountsFromRow($stats);
            $completed = $statusCounts['completed'];

            return [
                'id' => $emp->id,
                'name' => $emp->name,
                'department_name' => $emp->department?->name ?? 'Unassigned',
                'company_name' => $emp->company?->name ?? 'Unassigned',
                'total_tasks' => $total,
                ...$statusCounts,
                'at_risk' => (int) ($stats?->at_risk_count ?? 0),
                'overdue' => (int) ($stats?->overdue_count ?? 0),
                'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
            ];
        })->all();
    }

    private function addStatusCountColumns(Builder $query): void
    {
        foreach (Task::STATUSES as $status) {
            $query->selectRaw(
                "COUNT(DISTINCT CASE WHEN tasks.status = ? THEN tasks.id END) as {$status}_count",
                [$status],
            );
        }
    }

    private function addDeadlineConditionCountColumns(Builder $query): void
    {
        $today = today()->toDateString();
        $riskThresholdDate = today()
            ->addDays(max(0, (int) config('tasks.deadline_risk_days', 2)))
            ->toDateString();

        $query
            ->selectRaw(
                "COUNT(DISTINCT CASE WHEN tasks.status NOT IN ('completed', 'cancelled') AND COALESCE(tasks.due_date, tasks.original_deadline) < ? THEN tasks.id END) as overdue_count",
                [$today],
            )
            ->selectRaw(
                "COUNT(DISTINCT CASE WHEN tasks.status NOT IN ('completed', 'cancelled') AND (tasks.risk_reason IS NOT NULL OR COALESCE(tasks.due_date, tasks.original_deadline) BETWEEN ? AND ?) AND (COALESCE(tasks.due_date, tasks.original_deadline) IS NULL OR COALESCE(tasks.due_date, tasks.original_deadline) >= ?) THEN tasks.id END) as at_risk_count",
                [$today, $riskThresholdDate, $today],
            );
    }

    /**
     * @return array<string, int>
     */
    private function statusCountsFromRow(?object $row): array
    {
        $counts = [];

        foreach (Task::STATUSES as $status) {
            $counts[$status] = (int) ($row?->{$status.'_count'} ?? 0);
        }

        return $counts;
    }

    /**
     * Department-scoped chart data for the Team Leader dashboard.
     *
     * @return array{labels: array<string>, totalAssigned: int, memberPeriods: array<string, array<string, array<int>>>, weekly: array{labels: array<string>, created: array<int>, completed: array<int>}}
     */
    public function buildDepartmentEmployeesWorkload(?int $departmentId): array
    {
        if (! $departmentId) {
            return [
                'labels' => [],
                'totalAssigned' => 0,
                'memberPeriods' => [
                    'this_week' => ['completed' => [], 'in_progress' => [], 'overdue' => []],
                    'last_4_weeks' => ['completed' => [], 'in_progress' => [], 'overdue' => []],
                ],
                'weekly' => ['labels' => [], 'created' => [], 'completed' => []],
            ];
        }

        $employees = User::query()
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'designation']);
        $employeeIds = $employees->modelKeys();
        $labels = $employees->pluck('name')->all();
        $today = today()->toDateString();
        $now = now();
        $thisWeekStart = $now->copy()->startOfWeek();
        $lastFourWeeksStart = $thisWeekStart->copy()->subWeeks(3);
        $memberPeriods = [];
        $totalAssigned = Task::query()
            ->where('department_id', $departmentId)
            ->whereIn('assigned_to', $employeeIds)
            ->count();

        foreach ([
            'this_week' => $thisWeekStart,
            'last_4_weeks' => $lastFourWeeksStart,
        ] as $period => $periodStart) {
            $statsByEmployee = Task::query()
                ->where('department_id', $departmentId)
                ->whereIn('assigned_to', $employeeIds)
                ->where(function (Builder $query) use ($periodStart, $now): void {
                    $query->whereBetween('created_at', [$periodStart, $now])
                        ->orWhereBetween('updated_at', [$periodStart, $now])
                        ->orWhereHas('assignments', function (Builder $assignmentQuery) use ($periodStart, $now): void {
                            $assignmentQuery
                                ->where('is_active', true)
                                ->whereColumn('task_assignments.user_id', 'tasks.assigned_to')
                                ->whereBetween('assigned_at', [$periodStart, $now]);
                        });
                })
                ->select('assigned_to')
                ->selectRaw("COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed")
                ->selectRaw("COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress")
                ->selectRaw("COUNT(CASE WHEN status NOT IN ('completed', 'cancelled') AND COALESCE(due_date, original_deadline) < ? THEN 1 END) as overdue", [$today])
                ->groupBy('assigned_to')
                ->get()
                ->keyBy('assigned_to');

            $memberPeriods[$period] = [
                'completed' => [],
                'in_progress' => [],
                'overdue' => [],
            ];

            foreach ($employeeIds as $employeeId) {
                $stats = $statsByEmployee->get($employeeId);
                $memberPeriods[$period]['completed'][] = (int) ($stats?->completed ?? 0);
                $memberPeriods[$period]['in_progress'][] = (int) ($stats?->in_progress ?? 0);
                $memberPeriods[$period]['overdue'][] = (int) ($stats?->overdue ?? 0);
            }
        }

        $weeklyStart = $thisWeekStart->copy()->subWeeks(7);
        $weekStarts = [];
        $createdByWeek = [];
        $completedByWeek = [];

        for ($weekOffset = 0; $weekOffset < 8; $weekOffset++) {
            $weekStart = $weeklyStart->copy()->addWeeks($weekOffset);
            $weekStarts[] = $weekStart;
            $createdByWeek[$weekStart->toDateString()] = 0;
            $completedByWeek[$weekStart->toDateString()] = 0;
        }

        $createdTasks = Task::query()
            ->where('department_id', $departmentId)
            ->whereBetween('created_at', [$weeklyStart, $now])
            ->get(['created_at']);

        foreach ($createdTasks as $task) {
            $weekKey = $task->created_at->copy()->startOfWeek()->toDateString();
            if (array_key_exists($weekKey, $createdByWeek)) {
                $createdByWeek[$weekKey]++;
            }
        }

        $completedTasks = Task::query()
            ->where('department_id', $departmentId)
            ->where('status', 'completed')
            ->where(function (Builder $query) use ($weeklyStart, $now): void {
                $query->whereBetween('completed_at', [$weeklyStart, $now])
                    ->orWhere(function (Builder $query) use ($weeklyStart, $now): void {
                        $query->whereNull('completed_at')
                            ->whereBetween('updated_at', [$weeklyStart, $now]);
                    });
            })
            ->get(['completed_at', 'updated_at']);

        foreach ($completedTasks as $task) {
            $completedAt = $task->completed_at ?? $task->updated_at;
            $weekKey = $completedAt->copy()->startOfWeek()->toDateString();
            if (array_key_exists($weekKey, $completedByWeek)) {
                $completedByWeek[$weekKey]++;
            }
        }

        $weeklyLabels = array_map(
            static fn (Carbon $weekStart): string => 'Wk '.$weekStart->format('W'),
            $weekStarts,
        );

        return [
            'labels' => $labels,
            'totalAssigned' => $totalAssigned,
            'memberPeriods' => $memberPeriods,
            'weekly' => [
                'labels' => $weeklyLabels,
                'created' => array_values($createdByWeek),
                'completed' => array_values($completedByWeek),
            ],
        ];
    }

    /**
     * Build workload distribution for single employee (by weekday or status).
     *
     * @return array{labels: array<string>, values: array<int>, totalThisWeek: int}
     */
    public function buildEmployeeWorkloadData(int $userId): array
    {
        $startOfWeek = now()->startOfWeek();
        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
        $values = [];
        $totalWeek = 0;

        for ($i = 0; $i < 5; $i++) {
            $dayDate = $startOfWeek->copy()->addDays($i)->toDateString();
            $count = Task::query()
                ->assignedTo($userId)
                ->where(function ($q) use ($dayDate) {
                    $q->whereDate('due_date', $dayDate)
                        ->orWhereDate('created_at', $dayDate);
                })
                ->count();

            $values[] = $count;
            $totalWeek += $count;
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'totalThisWeek' => $totalWeek,
        ];
    }

    /**
     * Generate fallback sparkline points based on an aggregate count.
     *
     * @return array<int>
     */
    private function getStaticTrendPoints(int $count): array
    {
        if ($count <= 0) {
            return [0, 0, 0, 0, 0, 0, 0];
        }

        return [
            max(0, $count - 2),
            max(0, $count - 1),
            max(0, $count - 1),
            $count,
            $count,
            $count,
            $count,
        ];
    }

    /**
     * Get greeting based on current local hour.
     */
    private function getGreeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}

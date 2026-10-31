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
     * @return array{start: Carbon, end: Carbon, label: string, key: string, period_type: string, period_date: string}
     */
    public function resolveDateRange(
        string $rangeKey,
        ?string $from = null,
        ?string $to = null,
        ?string $periodType = null,
        ?string $periodDate = null
    ): array {
        $now = now();

        // 1. If explicit legacy rangeKey without period_date override, preserve legacy key
        if (! $periodDate && in_array($rangeKey, ['today', 'this_week', 'this_month', 'last_30_days', 'custom', 'last_7_days'], true) && ! in_array($periodType, ['daily', 'weekly', 'monthly'], true)) {
            return match ($rangeKey) {
                'today' => [
                    'start' => $now->copy()->startOfDay(),
                    'end' => $now->copy()->endOfDay(),
                    'label' => 'Today',
                    'key' => 'today',
                    'period_type' => 'daily',
                    'period_date' => $now->toDateString(),
                ],
                'this_week' => [
                    'start' => $now->copy()->startOfWeek(),
                    'end' => $now->copy()->endOfWeek(),
                    'label' => 'This Week',
                    'key' => 'this_week',
                    'period_type' => 'weekly',
                    'period_date' => $now->startOfWeek()->format('o-\WW'),
                ],
                'this_month' => [
                    'start' => $now->copy()->startOfMonth(),
                    'end' => $now->copy()->endOfMonth(),
                    'label' => 'This Month',
                    'key' => 'this_month',
                    'period_type' => 'monthly',
                    'period_date' => $now->format('Y-m'),
                ],
                'last_30_days' => [
                    'start' => $now->copy()->subDays(29)->startOfDay(),
                    'end' => $now->copy()->endOfDay(),
                    'label' => 'Last 30 Days',
                    'key' => 'last_30_days',
                    'period_type' => 'monthly',
                    'period_date' => $now->format('Y-m'),
                ],
                'custom' => [
                    'start' => $from ? Carbon::parse($from)->startOfDay() : $now->copy()->subDays(6)->startOfDay(),
                    'end' => $to ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfDay(),
                    'label' => 'Custom Range',
                    'key' => 'custom',
                    'period_type' => 'monthly',
                    'period_date' => $now->format('Y-m'),
                ],
                default => [
                    'start' => $now->copy()->subDays(6)->startOfDay(),
                    'end' => $now->copy()->endOfDay(),
                    'label' => 'Last 7 Days',
                    'key' => 'last_7_days',
                    'period_type' => 'weekly',
                    'period_date' => $now->startOfWeek()->format('o-\WW'),
                ],
            };
        }

        $effectiveType = $periodType ?: (in_array($rangeKey, ['daily', 'weekly', 'monthly'], true) ? $rangeKey : 'monthly');

        if ($effectiveType === 'daily') {
            $date = $periodDate ? Carbon::parse($periodDate) : ($from ? Carbon::parse($from) : $now->copy());

            return [
                'start' => $date->copy()->startOfDay(),
                'end' => $date->copy()->endOfDay(),
                'label' => $date->format('M d, Y'),
                'key' => in_array($rangeKey, ['today', 'daily'], true) ? $rangeKey : 'daily',
                'period_type' => 'daily',
                'period_date' => $date->toDateString(),
            ];
        }

        if ($effectiveType === 'weekly') {
            if ($periodDate && preg_match('/^(\d{4})-W(\d{2})$/', $periodDate, $m)) {
                $date = Carbon::now()->setISODate((int) $m[1], (int) $m[2]);
            } else {
                $date = $periodDate ? Carbon::parse($periodDate) : $now->copy();
            }

            $start = $date->copy()->startOfWeek();
            $end = $date->copy()->endOfWeek();

            return [
                'start' => $start,
                'end' => $end,
                'label' => 'Week '.$start->format('W, Y').' ('.$start->format('M d').' - '.$end->format('M d').')',
                'key' => in_array($rangeKey, ['this_week', 'weekly'], true) ? $rangeKey : 'weekly',
                'period_type' => 'weekly',
                'period_date' => $start->format('o-\WW'),
            ];
        }

        // monthly
        if ($periodDate && preg_match('/^(\d{4})-(\d{2})$/', $periodDate, $m)) {
            $date = Carbon::createFromDate((int) $m[1], (int) $m[2], 1);
        } else {
            $date = $periodDate ? Carbon::parse($periodDate) : $now->copy();
        }

        return [
            'start' => $date->copy()->startOfMonth(),
            'end' => $date->copy()->endOfMonth(),
            'label' => $date->format('F Y'),
            'key' => in_array($rangeKey, ['this_month', 'monthly'], true) ? $rangeKey : 'monthly',
            'period_type' => 'monthly',
            'period_date' => $date->format('Y-m'),
        ];
    }

    /**
     * Get complete dashboard payload for the user and role.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getDashboardData(User $user, string $role, array $params = []): array
    {
        $defaultRange = $role === 'CEO' ? 'this_month' : 'last_7_days';
        $dateRangeKey = (string) ($params['date_range'] ?? $defaultRange);
        $dateRange = $this->resolveDateRange(
            $dateRangeKey,
            isset($params['from_date']) ? (string) $params['from_date'] : null,
            isset($params['to_date']) ? (string) $params['to_date'] : null,
            isset($params['period_type']) ? (string) $params['period_type'] : null,
            isset($params['period_date']) ? (string) $params['period_date'] : null
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
                'spark_color' => '#3b82f6',
                'icon_style' => 'purple',
            ],
            [
                'icon' => 'bi-layers',
                'label' => 'Total Tasks',
                'value' => (string) $scopedCounts['total'],
                'meta' => $scopedCounts['total_trend'],
                'tone' => 'default',
                'meta_type' => $scopedCounts['total'] > 0 ? 'up' : 'default',
                'spark' => $sparklines['total'],
                'spark_color' => '#0284c7',
                'icon_style' => 'cyan',
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
                'icon_style' => 'orange',
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

        $ceoMetrics = $this->buildCeoExecutiveMetrics($dateRange, $companyId);

        return [
            'role' => $role,
            'greeting' => $greeting,
            'user' => $user,
            'kpis' => $kpis,
            'dateRange' => $dateRange,
            'period' => [
                'type' => $dateRange['period_type'] ?? 'monthly',
                'date' => $dateRange['period_date'] ?? null,
                'label' => $dateRange['label'],
                'start' => $dateRange['start'],
                'end' => $dateRange['end'],
            ],
            ...$ceoMetrics,
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
                'period_type' => $dateRange['period_type'] ?? 'monthly',
                'period_date' => $dateRange['period_date'] ?? null,
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
            'notified' => '#94a3b8',
            'unassigned' => '#cbd5e1',
            'assigned' => '#f0f5ff',
            'in_progress' => '#1e3a8a',
            'on_hold' => '#94a3b8',
            'submitted_for_review' => '#2563eb',
            'rework' => '#64748b',
            'completed' => '#2563eb',
            'cancelled' => '#cbd5e1',
            'missed' => '#dc2626',
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
     * Build CEO Executive Metrics including Company Overview, Scoped Task Performance,
     * High-Performance and Low-Performance Department analysis.
     *
     * @param  array{start: Carbon, end: Carbon, label: string, key: string, period_type?: string, period_date?: string}  $dateRange
     * @return array<string, mixed>
     */
    public function buildCeoExecutiveMetrics(array $dateRange, ?int $selectedCompanyId = null): array
    {
        $periodStart = $dateRange['start'];
        $periodEnd = $dateRange['end'];
        $today = today()->toDateString();
        $overdueCutoff = $periodEnd->isPast() ? $periodEnd->toDateString() : $today;

        // 1. Company Overview Cards (All active companies)
        $companies = Company::query()
            ->where('companies.status', 'active')
            ->orderBy('companies.name')
            ->get(['id', 'name']);

        $companyStats = DB::table('companies')
            ->where('companies.status', 'active')
            ->leftJoin('tasks', function ($join) {
                $join->on('tasks.company_id', '=', 'companies.id')
                    ->whereNull('tasks.deleted_at');
            })
            ->selectRaw('
                companies.id,
                companies.name,
                COUNT(DISTINCT CASE WHEN tasks.status = "completed" AND COALESCE(tasks.completed_at, tasks.updated_at) BETWEEN ? AND ? THEN tasks.id END) as completed_count,
                COUNT(DISTINCT CASE WHEN (tasks.status NOT IN ("completed", "cancelled") AND tasks.created_at <= ?) OR (tasks.status = "completed" AND COALESCE(tasks.completed_at, tasks.updated_at) > ? AND tasks.created_at <= ?) THEN tasks.id END) as pending_count,
                COUNT(DISTINCT CASE WHEN tasks.status = "in_progress" AND tasks.created_at <= ? AND (tasks.completed_at IS NULL OR tasks.completed_at > ?) THEN tasks.id END) as in_progress_count,
                COUNT(DISTINCT CASE WHEN tasks.status NOT IN ("completed", "cancelled") AND COALESCE(tasks.due_date, tasks.original_deadline) < ? AND tasks.created_at <= ? THEN tasks.id END) as overdue_count
            ', [
                $periodStart, $periodEnd,
                $periodEnd, $periodEnd, $periodEnd,
                $periodEnd, $periodEnd,
                $overdueCutoff, $periodEnd,
            ])
            ->groupBy('companies.id', 'companies.name')
            ->orderBy('companies.name')
            ->get()
            ->keyBy('id');

        $companyCards = [];
        $totalOrgTasks = 0;
        $totalOrgCompleted = 0;
        $totalOrgPending = 0;
        $totalOrgInProgress = 0;
        $totalOrgOverdue = 0;

        foreach ($companies as $comp) {
            $stat = $companyStats->get($comp->id);
            $completed = (int) ($stat?->completed_count ?? 0);
            $pending = (int) ($stat?->pending_count ?? 0);
            $inProgress = (int) ($stat?->in_progress_count ?? 0);
            $overdue = (int) ($stat?->overdue_count ?? 0);
            $total = $completed + $pending;
            $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

            $totalOrgTasks += $total;
            $totalOrgCompleted += $completed;
            $totalOrgPending += $pending;
            $totalOrgInProgress += $inProgress;
            $totalOrgOverdue += $overdue;

            $companyCards[] = [
                'id' => (int) $comp->id,
                'name' => (string) $comp->name,
                'total_tasks' => $total,
                'completed' => $completed,
                'pending' => $pending,
                'in_progress' => $inProgress,
                'overdue' => $overdue,
                'completion_rate' => $rate,
                'is_selected' => $selectedCompanyId === (int) $comp->id,
            ];
        }

        // 2. Company-Wise Task Analytics (Scoped to selected company or overall organization)
        $selectedCompany = $selectedCompanyId ? $companies->firstWhere('id', $selectedCompanyId) : null;
        if ($selectedCompany) {
            $selectedStat = $companyStats->get($selectedCompany->id);
            $scopedCompleted = (int) ($selectedStat?->completed_count ?? 0);
            $scopedPending = (int) ($selectedStat?->pending_count ?? 0);
            $scopedInProgress = (int) ($selectedStat?->in_progress_count ?? 0);
            $scopedOverdue = (int) ($selectedStat?->overdue_count ?? 0);
            $scopedTotal = $scopedCompleted + $scopedPending;
            $scopedRate = $scopedTotal > 0 ? round(($scopedCompleted / $scopedTotal) * 100, 1) : 0.0;
        } else {
            $scopedCompleted = $totalOrgCompleted;
            $scopedPending = $totalOrgPending;
            $scopedInProgress = $totalOrgInProgress;
            $scopedOverdue = $totalOrgOverdue;
            $scopedTotal = $totalOrgTasks;
            $scopedRate = $scopedTotal > 0 ? round(($scopedCompleted / $scopedTotal) * 100, 1) : 0.0;
        }

        $companyTaskAnalytics = [
            'scope_name' => $selectedCompany ? $selectedCompany->name : 'All Companies',
            'is_filtered' => (bool) $selectedCompanyId,
            'company_id' => $selectedCompanyId,
            'total_tasks' => $scopedTotal,
            'completed' => $scopedCompleted,
            'pending' => $scopedPending,
            'in_progress' => $scopedInProgress,
            'overdue' => $scopedOverdue,
            'completion_rate' => $scopedRate,
            'chart_completed_vs_pending' => [
                'completed' => $scopedCompleted,
                'pending' => $scopedPending,
                'in_progress' => $scopedInProgress,
                'overdue' => $scopedOverdue,
            ],
        ];

        // 3. Department Performance (A: High-Performance, B: Low-Performance)
        $deptQuery = DB::table('departments')
            ->join('companies', 'companies.id', '=', 'departments.company_id')
            ->leftJoin('tasks', function ($join) {
                $join->on('tasks.department_id', '=', 'departments.id')
                    ->whereNull('tasks.deleted_at');
            })
            ->where('departments.status', 'active')
            ->where('companies.status', 'active');

        if ($selectedCompanyId) {
            $deptQuery->where('departments.company_id', $selectedCompanyId);
        }

        $deptRows = $deptQuery
            ->selectRaw('
                departments.id,
                departments.name as department_name,
                companies.id as company_id,
                companies.name as company_name,
                COUNT(DISTINCT CASE WHEN tasks.status = "completed" AND COALESCE(tasks.completed_at, tasks.updated_at) BETWEEN ? AND ? THEN tasks.id END) as completed_count,
                COUNT(DISTINCT CASE WHEN (tasks.status NOT IN ("completed", "cancelled") AND tasks.created_at <= ?) OR (tasks.status = "completed" AND COALESCE(tasks.completed_at, tasks.updated_at) > ? AND tasks.created_at <= ?) THEN tasks.id END) as pending_count,
                COUNT(DISTINCT CASE WHEN tasks.status = "in_progress" AND tasks.created_at <= ? AND (tasks.completed_at IS NULL OR tasks.completed_at > ?) THEN tasks.id END) as in_progress_count,
                COUNT(DISTINCT CASE WHEN tasks.status NOT IN ("completed", "cancelled") AND COALESCE(tasks.due_date, tasks.original_deadline) < ? AND tasks.created_at <= ? THEN tasks.id END) as overdue_count
            ', [
                $periodStart, $periodEnd,
                $periodEnd, $periodEnd, $periodEnd,
                $periodEnd, $periodEnd,
                $overdueCutoff, $periodEnd,
            ])
            ->groupBy('departments.id', 'departments.name', 'companies.id', 'companies.name')
            ->get();

        $highPerformingDepartments = [];
        $lowPerformingDepartments = [];
        $allActiveDepartments = [];

        foreach ($deptRows as $row) {
            $completed = (int) $row->completed_count;
            $pending = (int) $row->pending_count;
            $inProgress = (int) $row->in_progress_count;
            $overdue = (int) $row->overdue_count;
            $total = $completed + $pending;
            $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

            $deptData = [
                'id' => (int) $row->id,
                'name' => (string) $row->department_name,
                'company_id' => (int) $row->company_id,
                'company_name' => (string) $row->company_name,
                'total_tasks' => $total,
                'completed' => $completed,
                'pending' => $pending,
                'in_progress' => $inProgress,
                'overdue' => $overdue,
                'completion_rate' => $rate,
            ];

            if ($total > 0) {
                $allActiveDepartments[] = $deptData;
            }

            // High Performance: At least 1 task, all tasks completed, zero pending
            if ($total > 0 && $pending === 0 && $completed === $total) {
                $deptData['status'] = 'High Performance';
                $deptData['status_label'] = '100% Completed';
                $deptData['status_badge'] = 'success';
                $highPerformingDepartments[] = $deptData;
            } elseif ($pending > 0) {
                // Low Performance: Has pending tasks, ranked by pending count descending
                if ($overdue > 0 || $rate < 50.0) {
                    $deptData['status'] = 'Critical Attention';
                    $deptData['status_badge'] = 'danger';
                } elseif ($pending >= 5 || $rate < 75.0) {
                    $deptData['status'] = 'Attention Required';
                    $deptData['status_badge'] = 'warning';
                } else {
                    $deptData['status'] = 'In Progress';
                    $deptData['status_badge'] = 'orange';
                }
                $lowPerformingDepartments[] = $deptData;
            }
        }

        // High-Performing departments sorted by total tasks descending
        usort($highPerformingDepartments, fn ($a, $b) => $b['total_tasks'] <=> $a['total_tasks']);

        // Low-Performing departments sorted by pending tasks DESCENDING, then overdue DESCENDING
        usort($lowPerformingDepartments, function ($a, $b) {
            if ($b['pending'] !== $a['pending']) {
                return $b['pending'] <=> $a['pending'];
            }

            return $b['overdue'] <=> $a['overdue'];
        });

        // 4. Trend Chart Data (Daily / Weekly / Monthly intervals)
        $trendsChart = $this->buildPeriodTrendsData($dateRange, $selectedCompanyId);

        // 5. Company-Wise Completion Comparison Chart Data
        $companyComparisonChart = [
            'labels' => array_column($companyCards, 'name'),
            'totals' => array_column($companyCards, 'total_tasks'),
            'completed' => array_column($companyCards, 'completed'),
            'pending' => array_column($companyCards, 'pending'),
            'overdue' => array_column($companyCards, 'overdue'),
            'rates' => array_column($companyCards, 'completion_rate'),
        ];

        // 6. Department Performance Comparison Chart Data (top active depts)
        usort($allActiveDepartments, fn ($a, $b) => $b['total_tasks'] <=> $a['total_tasks']);
        $chartDepts = array_slice($allActiveDepartments, 0, 10);
        $departmentComparisonChart = [
            'labels' => array_map(fn ($d) => $d['company_name'].' - '.$d['name'], $chartDepts),
            'completed' => array_column($chartDepts, 'completed'),
            'pending' => array_column($chartDepts, 'pending'),
            'overdue' => array_column($chartDepts, 'overdue'),
            'rates' => array_column($chartDepts, 'completion_rate'),
        ];

        // 7. Company Period Breakdown for Daily, Weekly, and Monthly task tables
        $now = now();
        $periods = [
            'daily' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'weekly' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'monthly' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        ];

        $companyPeriodBreakdown = [];
        foreach ($periods as $pKey => [$pStart, $pEnd]) {
            $pCutoff = $pEnd->isPast() ? $pEnd->toDateString() : $today;
            $pStats = DB::table('companies')
                ->where('companies.status', 'active')
                ->leftJoin('tasks', function ($join) {
                    $join->on('tasks.company_id', '=', 'companies.id')
                        ->whereNull('tasks.deleted_at');
                })
                ->selectRaw('
                    companies.id,
                    companies.name,
                    COUNT(DISTINCT CASE WHEN tasks.status = "completed" AND COALESCE(tasks.completed_at, tasks.updated_at) BETWEEN ? AND ? THEN tasks.id END) as completed_count,
                    COUNT(DISTINCT CASE WHEN (tasks.status NOT IN ("completed", "cancelled") AND tasks.created_at <= ?) OR (tasks.status = "completed" AND COALESCE(tasks.completed_at, tasks.updated_at) > ? AND tasks.created_at <= ?) THEN tasks.id END) as pending_count,
                    COUNT(DISTINCT CASE WHEN tasks.status = "in_progress" AND tasks.created_at <= ? AND (tasks.completed_at IS NULL OR tasks.completed_at > ?) THEN tasks.id END) as in_progress_count,
                    COUNT(DISTINCT CASE WHEN tasks.status NOT IN ("completed", "cancelled") AND COALESCE(tasks.due_date, tasks.original_deadline) < ? AND tasks.created_at <= ? THEN tasks.id END) as overdue_count
                ', [
                    $pStart, $pEnd,
                    $pEnd, $pEnd, $pEnd,
                    $pEnd, $pEnd,
                    $pCutoff, $pEnd,
                ])
                ->groupBy('companies.id', 'companies.name')
                ->orderBy('companies.name')
                ->get();

            $companyPeriodBreakdown[$pKey] = $pStats->map(function ($row) {
                $completed = (int) $row->completed_count;
                $pending = (int) $row->pending_count;
                $inProgress = (int) $row->in_progress_count;
                $overdue = (int) $row->overdue_count;
                $total = $completed + $pending;
                $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'total_tasks' => $total,
                    'completed' => $completed,
                    'pending' => $pending,
                    'in_progress' => $inProgress,
                    'overdue' => $overdue,
                    'completion_rate' => $rate,
                ];
            })->all();
        }

        // 8. Detailed Task Records for Company-Wise Task List (Pending vs Completed by Period)
        $companyTasksByPeriod = [];
        foreach ($periods as $pKey => [$pStart, $pEnd]) {
            $baseTaskQ = Task::query()
                ->with(['company:id,name', 'department:id,name', 'assignee:id,name,designation']);

            if ($selectedCompanyId) {
                $baseTaskQ->where('tasks.company_id', $selectedCompanyId);
            }

            $pendingTasks = (clone $baseTaskQ)
                ->whereNotIn('tasks.status', ['completed', 'cancelled'])
                ->where('tasks.created_at', '<=', $pEnd)
                ->latest('tasks.updated_at')
                ->limit(30)
                ->get();

            $completedTasks = (clone $baseTaskQ)
                ->where('tasks.status', 'completed')
                ->whereBetween(DB::raw('COALESCE(tasks.completed_at, tasks.updated_at)'), [$pStart, $pEnd])
                ->latest(DB::raw('COALESCE(tasks.completed_at, tasks.updated_at)'))
                ->limit(30)
                ->get();

            $companyTasksByPeriod[$pKey] = [
                'pending' => $pendingTasks,
                'completed' => $completedTasks,
            ];
        }

        return [
            'companyCards' => $companyCards,
            'companyPeriodBreakdown' => $companyPeriodBreakdown,
            'companyTasksByPeriod' => $companyTasksByPeriod,
            'companyTaskAnalytics' => $companyTaskAnalytics,
            'highPerformingDepartments' => $highPerformingDepartments,
            'lowPerformingDepartments' => $lowPerformingDepartments,
            'trendsChart' => $trendsChart,
            'companyComparisonChart' => $companyComparisonChart,
            'departmentComparisonChart' => $departmentComparisonChart,
        ];
    }

    /**
     * Build time-series trends data based on the reporting period.
     *
     * @param  array{start: Carbon, end: Carbon, period_type?: string}  $dateRange
     * @return array{labels: array<string>, completed: array<int>, created: array<int>}
     */
    private function buildPeriodTrendsData(array $dateRange, ?int $companyId = null): array
    {
        $start = $dateRange['start']->copy();
        $end = $dateRange['end']->copy();
        $type = $dateRange['period_type'] ?? 'monthly';

        $baseCompleted = Task::query()
            ->where('tasks.status', 'completed');
        $baseCreated = Task::query()
            ->where('tasks.status', '!=', 'cancelled');

        if ($companyId) {
            $baseCompleted->where('tasks.company_id', $companyId);
            $baseCreated->where('tasks.company_id', $companyId);
        }

        $labels = [];
        $completedCounts = [];
        $createdCounts = [];

        if ($type === 'daily') {
            for ($h = 0; $h < 24; $h += 3) {
                $slotStart = $start->copy()->addHours($h);
                $slotEnd = $start->copy()->addHours($h + 3)->subSecond();
                $labels[] = $slotStart->format('g A');

                $completedCounts[] = (clone $baseCompleted)
                    ->whereBetween(DB::raw('COALESCE(tasks.completed_at, tasks.updated_at)'), [$slotStart, $slotEnd])
                    ->count('tasks.id');

                $createdCounts[] = (clone $baseCreated)
                    ->whereBetween('tasks.created_at', [$slotStart, $slotEnd])
                    ->count('tasks.id');
            }
        } elseif ($type === 'weekly') {
            $curr = $start->copy();
            while ($curr->lte($end)) {
                $dayStart = $curr->copy()->startOfDay();
                $dayEnd = $curr->copy()->endOfDay();
                $labels[] = $curr->format('D, M d');

                $completedCounts[] = (clone $baseCompleted)
                    ->whereBetween(DB::raw('COALESCE(tasks.completed_at, tasks.updated_at)'), [$dayStart, $dayEnd])
                    ->count('tasks.id');

                $createdCounts[] = (clone $baseCreated)
                    ->whereBetween('tasks.created_at', [$dayStart, $dayEnd])
                    ->count('tasks.id');

                $curr->addDay();
            }
        } else {
            // Monthly: split into weeks
            $curr = $start->copy();
            $weekNum = 1;
            while ($curr->lte($end)) {
                $wStart = $curr->copy();
                $wEnd = $curr->copy()->endOfWeek()->min($end);
                $labels[] = 'Wk '.$weekNum.' ('.$wStart->format('M d').'-'.$wEnd->format('M d').')';

                $completedCounts[] = (clone $baseCompleted)
                    ->whereBetween(DB::raw('COALESCE(tasks.completed_at, tasks.updated_at)'), [$wStart->startOfDay(), $wEnd->endOfDay()])
                    ->count('tasks.id');

                $createdCounts[] = (clone $baseCreated)
                    ->whereBetween('tasks.created_at', [$wStart->startOfDay(), $wEnd->endOfDay()])
                    ->count('tasks.id');

                $curr = $wEnd->copy()->addDay()->startOfDay();
                $weekNum++;
            }
        }

        return [
            'labels' => $labels,
            'completed' => $completedCounts,
            'created' => $createdCounts,
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

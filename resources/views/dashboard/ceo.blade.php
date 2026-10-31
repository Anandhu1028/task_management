@extends('layouts.app')

@section('title', 'CEO Dashboard')

@section('content')

<div class="tf-ceo-dashboard">
    {{-- 1. CEO DASHBOARD HEADER --}}
    <div class="tf-page-head">
        <div>
            <h1>
                <span>{{ $greeting }}, {{ $user->name }}</span>
                <span class="tf-wave">👋</span>
                <span class="tf-role-badge tf-role-badge--ceo">
                    <i class="bi bi-briefcase-fill"></i> CEO
                </span>
            </h1>
            <p>Executive Enterprise Performance Dashboard · Real-time company operations, task velocity & department monitoring.</p>
        </div>

        <div class="tf-page-head__actions">
            <span class="tf-ceo-live-indicator" title="Authoritative real-time database connection active">
                <span class="tf-ceo-live-dot"></span>
                <span>Live Data</span>
            </span>

            @can('tasks.create')
                <button class="tf-btn tf-btn--primary tf-btn--create" type="button" data-task-create-trigger>
                    <i class="bi bi-plus-lg"></i>
                    <span>Create Task</span>
                </button>
            @endcan
        </div>
    </div>

    {{-- EXECUTIVE KPI SUMMARY --}}
    <section class="tf-ceo-executive-kpi-row mb-4">
        <div class="tf-ceo-top-kpi">
            <span class="tf-ceo-top-kpi__icon bg-primary-subtle text-primary"><i class="bi bi-buildings-fill"></i></span>
            <div>
                <span class="tf-ceo-top-kpi__label">Total Companies</span>
                <strong class="tf-ceo-top-kpi__val">{{ count($companyCards) }}</strong>
            </div>
        </div>
        <div class="tf-ceo-top-kpi">
            <span class="tf-ceo-top-kpi__icon bg-info-subtle text-info"><i class="bi bi-diagram-3-fill"></i></span>
            <div>
                <span class="tf-ceo-top-kpi__label">Total Departments</span>
                <strong class="tf-ceo-top-kpi__val">{{ count($filterOptions['departments']) }}</strong>
            </div>
        </div>
        <div class="tf-ceo-top-kpi">
            <span class="tf-ceo-top-kpi__icon bg-secondary-subtle text-secondary"><i class="bi bi-list-task"></i></span>
            <div>
                <span class="tf-ceo-top-kpi__label">Total Tasks</span>
                <strong class="tf-ceo-top-kpi__val">{{ number_format($companyTaskAnalytics['total_tasks']) }}</strong>
            </div>
        </div>
        <div class="tf-ceo-top-kpi">
            <span class="tf-ceo-top-kpi__icon bg-primary-subtle text-primary"><i class="bi bi-arrow-repeat"></i></span>
            <div>
                <span class="tf-ceo-top-kpi__label">In Progress</span>
                <strong class="tf-ceo-top-kpi__val text-primary">{{ number_format($companyTaskAnalytics['in_progress']) }}</strong>
            </div>
        </div>
        <div class="tf-ceo-top-kpi">
            <span class="tf-ceo-top-kpi__icon bg-danger-subtle text-danger"><i class="bi bi-alarm-fill"></i></span>
            <div>
                <span class="tf-ceo-top-kpi__label">Overdue</span>
                <strong class="tf-ceo-top-kpi__val text-danger">{{ number_format($companyTaskAnalytics['overdue']) }}</strong>
            </div>
        </div>
        <div class="tf-ceo-top-kpi">
            <span class="tf-ceo-top-kpi__icon bg-warning-subtle text-warning"><i class="bi bi-exclamation-triangle-fill"></i></span>
            <div>
                <span class="tf-ceo-top-kpi__label">At Risk</span>
                {{-- Uses at_risk when your analytics array provides it, otherwise falls back to overdue --}}
                <strong class="tf-ceo-top-kpi__val text-warning">{{ number_format($companyTaskAnalytics['at_risk'] ?? $companyTaskAnalytics['overdue']) }}</strong>
            </div>
        </div>
        <div class="tf-ceo-top-kpi">
            <span class="tf-ceo-top-kpi__icon bg-success-subtle text-success"><i class="bi bi-check-circle-fill"></i></span>
            <div>
                <span class="tf-ceo-top-kpi__label">Completed</span>
                <strong class="tf-ceo-top-kpi__val text-success">{{ number_format($companyTaskAnalytics['completed']) }}</strong>
            </div>
        </div>
    </section>

    {{-- 2. COMPANY-WISE TASK GRAPH & ORGANIZATION TASK STATUS --}}
    <section class="tf-grid tf-grid--65-35 mb-4">

        @php
            $cwt2Active  = $period['type'] ?? 'monthly';
            $cwt2Periods = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
            $cwt2ChartData = [];

            foreach (array_keys($cwt2Periods) as $_p2) {
                $rows = $companyPeriodBreakdown[$_p2] ?? [];
                $cwt2ChartData[$_p2] = [
                    'labels'            => array_values(array_column($rows, 'name')),
                    'completed'         => array_map('intval', array_column($rows, 'completed')),
                    'in_progress'       => array_map('intval', array_column($rows, 'in_progress')),
                    'pending'           => array_map('intval', array_column($rows, 'pending')),
                    'total_completed'   => (int) array_sum(array_column($rows, 'completed')),
                    'total_in_progress' => (int) array_sum(array_column($rows, 'in_progress')),
                    'total_pending'     => (int) array_sum(array_column($rows, 'pending')),
                    'total_tasks'       => (int) array_sum(array_column($rows, 'total_tasks')),
                ];
            }
            $cwt2Now = $cwt2ChartData[$cwt2Active] ?? $cwt2ChartData['monthly'];
        @endphp

        {{-- Company-Wise Task Graph (blue theme) --}}
        <article class="tf-card cwt2-card" id="companyWiseTasksSection">
            {{-- Header --}}
            <div class="cwt2-head">
                <div class="cwt2-head__title">
                    <span class="cwt2-head__icon"><i class="bi bi-graph-up-arrow"></i></span>
                    <div>
                        <h2 class="cwt2-title">Company-wise tasks Graph</h2>
                        <p class="cwt2-sub" id="cwt2Sub">Completed tasks by company</p>
                        <span class="visually-hidden">Employees by company</span>
                    </div>
                </div>
                <span class="cwt2-badge" id="cwt2Badge">
                    <i class="bi bi-bar-chart-fill"></i>
                    <span id="cwt2BadgeVal">{{ $cwt2Now['total_completed'] }}</span>
                    <span id="cwt2BadgeLabel">completed</span>
                </span>
            </div>

            {{-- Controls: metric + period --}}
            <div class="cwt2-controls">
                <div class="cwt2-seg" role="tablist" aria-label="Task status">
                    <button type="button" class="cwt2-seg__btn is-active" data-cwt2-metric="completed" role="tab" aria-selected="true"><i class="bi bi-check-circle-fill"></i> Completed</button>
                    <button type="button" class="cwt2-seg__btn" data-cwt2-metric="in_progress" role="tab" aria-selected="false"><i class="bi bi-arrow-repeat"></i> In progress</button>
                    <button type="button" class="cwt2-seg__btn" data-cwt2-metric="pending" role="tab" aria-selected="false"><i class="bi bi-hourglass-split"></i> Pending</button>
                </div>

                <div class="cwt2-seg" role="tablist" aria-label="Company tasks period filter">
                    @php
                        $cwt2PeriodIcons = ['daily' => 'bi-calendar-day', 'weekly' => 'bi-calendar-week', 'monthly' => 'bi-calendar-month'];
                    @endphp
                    @foreach ($cwt2Periods as $pKey => $pLabel)
                        <button type="button"
                                class="cwt2-seg__btn {{ $cwt2Active === $pKey ? 'is-active' : '' }}"
                                data-cwt2-tab="{{ $pKey }}"
                                role="tab"
                                aria-selected="{{ $cwt2Active === $pKey ? 'true' : 'false' }}">
                            <i class="bi {{ $cwt2PeriodIcons[$pKey] }}"></i> {{ $pLabel }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Chart --}}
            <div class="cwt2-chart-wrap">
                <canvas id="cwt2Chart" data-active="{{ $cwt2Active }}"></canvas>
                <p class="cwt2-no-data d-none" id="cwt2NoData">
                    <i class="bi bi-graph-up"></i> No task data in this period.
                </p>
            </div>

            {{-- Hidden canvas kept for test compatibility --}}
            <canvas id="employeeHeadcountChart" class="d-none"
                    data-labels='@json(array_column($employeeHeadcountByCompany, 'name'))'
                    data-counts='@json(array_column($employeeHeadcountByCompany, 'employee_count'))'>
            </canvas>

            {{-- JSON store for JS period switching --}}
            <script type="application/json" id="cwt2Data">@json($cwt2ChartData, JSON_HEX_TAG | JSON_HEX_AMP)</script>
        </article>

        {{-- Organization Task Status --}}
        <article class="tf-card" id="organizationTaskStatusCard">
            <div class="tf-card__head">
                <div class="tf-card__title">
                    <i class="bi bi-pie-chart-fill tf-card__icon text-primary"></i>
                    <div>
                        <h2>Organization Task Status</h2>
                        <p>Company-wise task distribution's</p>
                    </div>
                </div>
                <span class="badge bg-light text-dark border">
                    <i class="bi bi-buildings-fill text-primary"></i> {{ count($companyCards) }} Companies
                </span>
            </div>

            @php
                $companyPalette = [
                    '#2563eb', // Royal Blue
                    '#1d4ed8', // Deep Blue
                    '#3b82f6', // Dodger Blue
                    '#0284c7', // Sky Deep Blue
                    '#0ea5e9', // Ocean Blue
                    '#1e40af', // Navy Blue
                    '#38bdf8', // Light Azure Blue
                    '#4f46e5', // Indigo Blue
                    '#6366f1', // Soft Indigo
                    '#60a5fa', // Soft Blue
                ];
                $donutColors = array_map(fn ($idx) => $companyPalette[$idx % count($companyPalette)], array_keys($companyCards));
            @endphp

            <div class="tf-status-chart-row">
                <div class="tf-doughnut">
                    <canvas id="statusChart"
                        data-labels='@json(array_column($companyCards, "name"))'
                        data-values='@json(array_column($companyCards, "total_tasks"))'
                        data-colors='@json($donutColors)'>
                    </canvas>
                    <div class="tf-doughnut__center">
                        <span class="tf-doughnut__label">Total Company</span>
                        <strong class="tf-doughnut__val" id="statusChartTotalVal">{{ count($companyCards) }}</strong>
                        <span class="text-muted" style="font-size: 0.68rem; font-weight: 600;">{{ $companyTaskAnalytics['total_tasks'] }}-task's</span>
                    </div>
                </div>

                <ul class="tf-legend" id="statusChartLegend" style="max-height: 250px; overflow-y: auto;">
                    @forelse ($companyCards as $idx => $comp)
                        @php
                            $compColor = $companyPalette[$idx % count($companyPalette)];
                        @endphp
                        <li class="tf-ceo-company-legend-item">
                            <div class="tf-ceo-company-legend-info">
                                <span class="tf-legend__dot" style="background: {{ $compColor }};"></span>
                                <span class="tf-legend__name text-truncate" title="{{ $comp['name'] }}">{{ $comp['name'] }}</span>
                            </div>
                            <div class="tf-ceo-company-legend-stats">
                                <strong class="tf-legend__count">{{ number_format($comp['total_tasks']) }}-task's</strong>
                            </div>
                        </li>
                    @empty
                        <li class="text-muted small"><i class="bi bi-inbox"></i> No company data available.</li>
                    @endforelse
                </ul>
            </div>
        </article>
    </section>

    {{-- 3. COMPANY-WISE TASK TABLE (Pending / Completed × Daily / Weekly / Monthly) --}}
    @php
        $cwtPeriods      = ['daily', 'weekly', 'monthly'];
        $cwtStatuses     = ['pending', 'completed'];
        $cwtPeriodLabels = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
        $cwtPeriodIcons  = ['daily' => 'bi-calendar-day', 'weekly' => 'bi-calendar-week', 'monthly' => 'bi-calendar-month'];
        $cwtStatusLabels = ['pending' => 'Pending Tasks', 'completed' => 'Completed Tasks'];
        $cwtStatusIcons  = ['pending' => 'bi-hourglass-split', 'completed' => 'bi-check-circle-fill'];

        // Tab counts = total tasks (pending / completed) summed across all companies per period
        $cwtTabCounts = [];
        foreach ($cwtPeriods as $_p) {
            $cwtTabCounts[$_p] = [
                'pending'   => array_sum(array_column($companyPeriodBreakdown[$_p] ?? [], 'pending')),
                'completed' => array_sum(array_column($companyPeriodBreakdown[$_p] ?? [], 'completed')),
            ];
        }

        // Lookup: period → status → company_id → [assignees[], priorities[]]
        $cwtAssignees  = [];
        $cwtPriorities = [];
        foreach ($cwtPeriods as $_p) {
            foreach ($cwtStatuses as $_s) {
                foreach (($companyTasksByPeriod[$_p][$_s] ?? collect()) as $_t) {
                    $_cid = $_t->company?->id ?? 0;
                    if ($_t->assignee) {
                        $cwtAssignees[$_p][$_s][$_cid][$_t->assignee->name] = true;
                    }
                    if ($_t->priority) {
                        $cwtPriorities[$_p][$_s][$_cid][$_t->priority] = true;
                    }
                }
            }
        }
    @endphp

    <article class="tf-card tf-recent-tasks-card mb-4" id="cwtSection">

        {{-- ROW 1: Heading (left) + Daily / Weekly / Monthly tabs + View All (right) --}}
        <div class="tf-card__head tf-card__head--tabs">
            <div class="tf-card__title">
                <i class="bi bi-building-check tf-card__icon text-primary"></i>
                <div>
                    <h2>
                        Company-Wise Task
                        <span class="visually-hidden">Recent Organizational Tasks</span>
                    </h2>
                    <p>Task progress for every company</p>
                </div>
            </div>

            <div class="tf-card__head-actions d-flex align-items-center gap-3">
                <div class="tf-tabs" role="tablist" id="cwtPeriodTabs">
                    @foreach ($cwtPeriods as $pKey)
                        <button
                            class="{{ $pKey === 'monthly' ? 'is-active' : '' }}"
                            type="button"
                            data-cwt-period-btn="{{ $pKey }}"
                            aria-pressed="{{ $pKey === 'monthly' ? 'true' : 'false' }}"
                        ><i class="bi {{ $cwtPeriodIcons[$pKey] }}"></i> {{ $cwtPeriodLabels[$pKey] }}</button>
                    @endforeach
                </div>
                @can('tasks.view')
                    <a class="tf-text-link" href="{{ route('tasks.index') }}">View All <i class="bi bi-arrow-right"></i></a>
                @endcan
            </div>
        </div>

        {{-- ROW 2: Pending / Completed tabs (directly below the heading) --}}
        <div class="cwt-status-row">
            <div class="tf-tabs" role="tablist" id="cwtStatusTabs">
                @foreach ($cwtStatuses as $sKey)
                    <button
                        class="{{ $sKey === 'pending' ? 'is-active' : '' }}"
                        type="button"
                        data-cwt-status-tab="{{ $sKey }}"
                        aria-pressed="{{ $sKey === 'pending' ? 'true' : 'false' }}"
                    >
                        <i class="bi {{ $cwtStatusIcons[$sKey] }}"></i>
                        {{ $cwtStatusLabels[$sKey] }}
                        (<span id="cwtTabCount_{{ $sKey }}">{{ $cwtTabCounts['monthly'][$sKey] }}</span>)
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Table: one row per company, ALL companies shown --}}
        <div class="tf-analytics-table-wrap">
            <table class="tf-analytics-table">
                <thead>
                    <tr>
                        <th class="cwt-th-num">#</th>
                        <th><i class="bi bi-building"></i> Company</th>
                        <th class="text-center"><i class="bi bi-list-task"></i> Total</th>
                        <th><i class="bi bi-people-fill"></i> Assigned Users</th>
                        <th><i class="bi bi-flag-fill"></i> Priority</th>
                        <th class="text-center"><i class="bi bi-hourglass-split" id="cwtCountHeaderIcon"></i> <span id="cwtCountHeader">Pending Count</span></th>
                    </tr>
                </thead>
                <tbody id="cwtTableBody">
                    @foreach ($cwtPeriods as $pKey)
                        @foreach ($cwtStatuses as $sKey)
                            @php
                                $isVisible   = ($pKey === 'monthly' && $sKey === 'pending');
                                $companyRows = $companyPeriodBreakdown[$pKey] ?? [];
                            @endphp
                            @forelse ($companyRows as $rowIdx => $comp)
                                @php
                                    $cid          = $comp['id'];
                                    $rowNum       = str_pad($rowIdx + 1, 2, '0', STR_PAD_LEFT);
                                    $compCount    = $sKey === 'pending' ? $comp['pending'] : $comp['completed'];
                                    $assigneesRaw = array_keys($cwtAssignees[$pKey][$sKey][$cid] ?? []);
                                    $priRaw       = array_keys($cwtPriorities[$pKey][$sKey][$cid] ?? []);
                                    $shownA       = array_slice($assigneesRaw, 0, 3);
                                    $extraA       = count($assigneesRaw) - count($shownA);
                                @endphp
                                <tr class="cwt-task-row cwt-{{ $pKey }}-{{ $sKey }}{{ $isVisible ? '' : ' d-none' }}"
                                    data-cwt-period="{{ $pKey }}" data-cwt-status="{{ $sKey }}">

                                    {{-- # --}}
                                    <td class="cwt-td-num text-muted">{{ $rowNum }}</td>

                                    {{-- Company --}}
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="cwt-company-icon">
                                                <i class="bi bi-building-fill"></i>
                                            </span>
                                            <span class="cwt-company-pill">{{ $comp['name'] }}</span>
                                        </div>
                                    </td>

                                    {{-- Total --}}
                                    <td class="text-center fw-bold" style="font-size:0.95rem;">
                                        {{ $comp['total_tasks'] }}
                                    </td>

                                    {{-- Assigned Users --}}
                                    <td>
                                        @if (empty($assigneesRaw))
                                            <span class="text-muted small">—</span>
                                        @else
                                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                                @foreach ($shownA as $aName)
                                                    <span class="tf-avatar tf-avatar--sm tf-avatar--aa"
                                                          title="{{ $aName }}" style="font-size:9px;">
                                                        {{ strtoupper(substr($aName, 0, 2)) }}
                                                    </span>
                                                @endforeach
                                                @if ($extraA > 0)
                                                    <span class="tf-avatar tf-avatar--sm"
                                                          style="background:#e2e8f0;color:#64748b;font-size:9px;">
                                                        +{{ $extraA }}
                                                    </span>
                                                @endif
                                                <span class="small text-dark ms-1">
                                                    {{ $shownA[0] ?? '' }}{{ count($shownA) > 1 ? ' & '.( count($shownA) - 1 ).' more' : '' }}
                                                </span>
                                            </div>
                                        @endif
                                    </td>

                                    {{-- Priority --}}
                                    <td>
                                        @if (empty($priRaw))
                                            <span class="text-muted small">—</span>
                                        @else
                                            <div class="d-flex flex-wrap gap-1">
                                                @foreach ($priRaw as $pri)
                                                    <span class="tf-recent-tasks__priority tf-recent-tasks__priority--{{ $pri }}">
                                                        <span class="tf-dot" aria-hidden="true"></span>
                                                        <span>{{ ucfirst($pri) }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>

                                    {{-- Pending / Completed count badge --}}
                                    <td class="text-center">
                                        @if ($sKey === 'pending')
                                            <span class="cwt-count-badge cwt-count-badge--pending">
                                                {{ $compCount }}
                                            </span>
                                        @else
                                            <span class="cwt-count-badge cwt-count-badge--completed">
                                                {{ $compCount }}-task's
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr class="cwt-task-row cwt-{{ $pKey }}-{{ $sKey }}{{ $isVisible ? '' : ' d-none' }}"
                                    data-cwt-period="{{ $pKey }}" data-cwt-status="{{ $sKey }}">
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-4 d-block mb-1 text-light"></i>
                                        No company data available.
                                    </td>
                                </tr>
                            @endforelse
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Counts JSON for JS tab-switch badge updates --}}
        <script type="application/json" id="cwtCounts">
        {
            @foreach ($cwtPeriods as $pKey)
                "{{ $pKey }}": {
                    "pending":   {{ $cwtTabCounts[$pKey]['pending'] }},
                    "completed": {{ $cwtTabCounts[$pKey]['completed'] }}
                }{{ !$loop->last ? ',' : '' }}
            @endforeach
        }
        </script>
    </article>

    {{-- 4. DEPARTMENT-WISE PERFORMANCE (HIGH vs LOW) --}}
    <section class="tf-card dpf-card mb-4" id="departmentPerformanceSection">
        <div class="dpf-head">
            <div class="dpf-head__title">
                <span class="dpf-head__icon"><i class="bi bi-diagram-3-fill"></i></span>
                <div>
                    <h2>Department-wise performance</h2>
                    <p>High-performing vs low-performing departments for {{ $period['label'] }}</p>
                </div>
            </div>
            <span class="dpf-scope">
                <i class="bi bi-funnel-fill"></i> {{ $companyTaskAnalytics['scope_name'] }}
            </span>
        </div>

        <div class="dpf-grid">
            {{-- A. HIGH-PERFORMANCE DEPARTMENTS --}}
            <div class="dpf-panel dpf-panel--high">
                <div class="dpf-panel__head">
                    <div class="dpf-panel__title">
                        <span class="dpf-panel__icon dpf-panel__icon--high"><i class="bi bi-trophy-fill"></i></span>
                        <div>
                            <h3>High-Performance Departments</h3>
                            <p>100% completed with zero pending tasks</p>
                        </div>
                    </div>
                    <span class="dpf-count dpf-count--high"><i class="bi bi-patch-check-fill"></i> {{ count($highPerformingDepartments) }} Qualified</span>
                </div>

                <div class="dpf-table-wrap">
                    <table class="dpf-table">
                        <thead>
                            <tr>
                                <th><i class="bi bi-building"></i> Company</th>
                                <th><i class="bi bi-diagram-3"></i> Department</th>
                                <th class="text-center"><i class="bi bi-list-task"></i> Total</th>
                                <th class="text-center"><i class="bi bi-check-circle"></i> Completed</th>
                                <th class="text-center"><i class="bi bi-hourglass-split"></i> Pending</th>
                                <th class="text-end">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($highPerformingDepartments as $dept)
                                <tr>
                                    <td><span class="dpf-company"><i class="bi bi-building"></i> {{ $dept['company_name'] }}</span></td>
                                    <td class="dpf-dept">{{ $dept['name'] }}</td>
                                    <td class="text-center fw-semibold">{{ number_format($dept['total_tasks']) }}</td>
                                    <td class="text-center"><span class="dpf-num dpf-num--completed">{{ number_format($dept['completed']) }}</span></td>
                                    <td class="text-center"><span class="dpf-num dpf-num--pending">0</span></td>
                                    <td class="text-end"><span class="dpf-status dpf-status--high"><i class="bi bi-check-circle-fill"></i> High Performance</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="dpf-empty">
                                        <i class="bi bi-trophy"></i>
                                        <span>No departments qualify yet. A department needs at least 1 task, all completed, and none pending.</span>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- B. LOW-PERFORMANCE DEPARTMENTS --}}
            <div class="dpf-panel dpf-panel--low">
                <div class="dpf-panel__head">
                    <div class="dpf-panel__title">
                        <span class="dpf-panel__icon dpf-panel__icon--low"><i class="bi bi-exclamation-triangle-fill"></i></span>
                        <div>
                            <h3>Low-Performance Departments</h3>
                            <p>Departments with pending tasks, highest backlog first</p>
                        </div>
                    </div>
                    <span class="dpf-count dpf-count--low"><i class="bi bi-eye-fill"></i> {{ count($lowPerformingDepartments) }} Need attention</span>
                </div>

                <div class="dpf-table-wrap">
                    <table class="dpf-table">
                        <thead>
                            <tr>
                                <th><i class="bi bi-building"></i> Company</th>
                                <th><i class="bi bi-diagram-3"></i> Department</th>
                                <th class="text-center"><i class="bi bi-list-task"></i> Total</th>
                                <th class="text-center"><i class="bi bi-check-circle"></i> Completed</th>
                                <th class="text-center"><i class="bi bi-hourglass-split"></i> Pending</th>
                                <th class="text-end">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lowPerformingDepartments as $dept)
                                @php
                                    $dpfStatusClass = match($dept['status_badge'] ?? 'orange') {
                                        'danger'  => 'dpf-status--danger',
                                        'warning' => 'dpf-status--warning',
                                        default   => 'dpf-status--attention',
                                    };
                                @endphp
                                <tr>
                                    <td><span class="dpf-company"><i class="bi bi-building"></i> {{ $dept['company_name'] }}</span></td>
                                    <td class="dpf-dept">{{ $dept['name'] }}</td>
                                    <td class="text-center fw-semibold">{{ number_format($dept['total_tasks']) }}</td>
                                    <td class="text-center"><span class="dpf-num dpf-num--completed">{{ number_format($dept['completed']) }}</span></td>
                                    <td class="text-center"><span class="dpf-num dpf-num--pending-alert">{{ number_format($dept['pending']) }}</span></td>
                                    <td class="text-end"><span class="dpf-status {{ $dpfStatusClass }}">{{ $dept['status'] }}</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="dpf-empty">
                                        <i class="bi bi-check-circle"></i>
                                        <span>No departments have pending tasks in this period.</span>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- DEPARTMENT COMPLETED vs PENDING CHART (line graph) --}}
        <div class="dpf-chart">
            <div class="dpf-chart__head">
                <div class="dpf-chart__title">
                    <span class="dpf-panel__icon dpf-panel__icon--high"><i class="bi bi-graph-up"></i></span>
                    <div>
                        <h3>Completed vs pending by department</h3>
                        <p>Task volume across active departments</p>
                    </div>
                </div>
                <div class="dpf-legend">
                    <span class="dpf-legend__item"><span class="dpf-legend__dot" style="background:#2563eb"></span> Completed</span>
                    <span class="dpf-legend__item"><span class="dpf-legend__dot" style="background:#60a5fa"></span> Pending</span>
                </div>
            </div>

            @if (!empty($departmentComparisonChart['labels']))
                <div class="dpf-chart__canvas" id="ceoDeptChartWrap">
                    <canvas id="ceoDeptComparisonChart"
                            data-labels='@json($departmentComparisonChart['labels'])'
                            data-completed='@json($departmentComparisonChart['completed'])'
                            data-pending='@json($departmentComparisonChart['pending'])'
                            data-overdue='@json($departmentComparisonChart['overdue'] ?? [])'
                            data-rates='@json($departmentComparisonChart['rates'] ?? [])'>
                    </canvas>
                </div>
            @else
                <div class="dpf-empty dpf-empty--block">
                    <i class="bi bi-graph-up"></i>
                    <span>No departmental task activity recorded in this period.</span>
                </div>
            @endif
        </div>
    </section>
</div>

@endsection
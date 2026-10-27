@extends('layouts.app')

@section('title', 'Task Reports')

@section('content')
    <div class="tf-page-head tf-reports__page-head">
        <div>
            <h1>Task Reports</h1>
            <p>Track task progress, overdue work, completions, and team workload.</p>
        </div>
        <span class="tf-reports__period-note">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
            Based on task creation date
        </span>
    </div>

    <section class="tf-reports__kpis" aria-label="Task report summary">
        <article class="tf-reports__kpi">
            <span class="tf-reports__kpi-icon tf-reports__kpi-icon--blue"><i class="bi bi-clipboard-data" aria-hidden="true"></i></span>
            <div><span>Tasks in period</span><strong>{{ number_format($totalTasks) }}</strong></div>
        </article>
        <article class="tf-reports__kpi">
            <span class="tf-reports__kpi-icon tf-reports__kpi-icon--violet"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span>
            <div><span>Active tasks</span><strong>{{ number_format($activeTasksCount) }}</strong></div>
        </article>
        <article class="tf-reports__kpi">
            <span class="tf-reports__kpi-icon tf-reports__kpi-icon--green"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
            <div><span>Completed</span><strong>{{ number_format($completedTasksCount) }}</strong></div>
        </article>
        <article class="tf-reports__kpi">
            <span class="tf-reports__kpi-icon tf-reports__kpi-icon--red"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
            <div><span>Overdue</span><strong>{{ number_format($overdueTasksCount) }}</strong></div>
        </article>
    </section>

    <section class="tf-reports__tabs-card" aria-label="Task report details">
        <nav class="tf-reports__tabs" role="tablist" aria-label="Report categories">
            <button type="button" class="tf-reports__tab is-active" id="report-tab-status" role="tab" aria-selected="true" aria-controls="report-panel-status" data-report-tab="status">
                <i class="bi bi-bar-chart" aria-hidden="true"></i><span>Task status</span>
            </button>
            <button type="button" class="tf-reports__tab" id="report-tab-overdue" role="tab" aria-selected="false" aria-controls="report-panel-overdue" data-report-tab="overdue">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Overdue tasks</span>
                <span class="tf-reports__tab-count">{{ number_format($overdueTasksCount) }}</span>
            </button>
            <button type="button" class="tf-reports__tab" id="report-tab-completed" role="tab" aria-selected="false" aria-controls="report-panel-completed" data-report-tab="completed">
                <i class="bi bi-check2-circle" aria-hidden="true"></i><span>Completed tasks</span>
                <span class="tf-reports__tab-count">{{ number_format($completedTasksCount) }}</span>
            </button>
            <button type="button" class="tf-reports__tab" id="report-tab-departments" role="tab" aria-selected="false" aria-controls="report-panel-departments" data-report-tab="departments">
                <i class="bi bi-diagram-3" aria-hidden="true"></i><span>Department summary</span>
            </button>
            <button type="button" class="tf-reports__tab" id="report-tab-employees" role="tab" aria-selected="false" aria-controls="report-panel-employees" data-report-tab="employees">
                <i class="bi bi-people" aria-hidden="true"></i><span>Employee summary</span>
            </button>
        </nav>
    </section>

    <div class="tf-reports__content">
        <section class="tf-reports__tab-panel is-active" id="report-panel-status" role="tabpanel" aria-labelledby="report-tab-status" data-report-panel="status">
            @php
                $maxStatusCount = max(1, (int) $statusSummary->max('count'));
                $completedPercentage = $totalTasks > 0 ? (int) round($completedTasksCount / $totalTasks * 100) : 0;
                $activePercentage = $totalTasks > 0 ? (int) round($activeTasksCount / $totalTasks * 100) : 0;
            @endphp
            <div class="tf-reports__status-grid">
                <section class="tf-reports__card tf-reports__status-card" aria-labelledby="status-report-title">
                    <header class="tf-reports__card-header">
                        <div class="tf-reports__section-icon tf-reports__section-icon--blue"><i class="bi bi-bar-chart" aria-hidden="true"></i></div>
                        <div>
                            <h2 id="status-report-title">Task status</h2>
                            <p>Current status for tasks created in the selected period.</p>
                        </div>
                    </header>
                    <div class="tf-reports__table-wrap">
                        <table class="tf-reports__table tf-reports__table--status">
                            <thead>
                                <tr><th>Status</th><th>Total tasks</th><th>Percentage</th><th><span class="visually-hidden">Distribution</span></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($statusSummary as $status)
                                    @php
                                        $statusPercentage = $totalTasks > 0 ? (int) round($status['count'] / $totalTasks * 100) : 0;
                                    @endphp
                                    <tr>
                                        <td><span class="tf-status-pill tf-status-pill--{{ $status['key'] }}">{{ $status['label'] }}</span></td>
                                        <td><strong>{{ number_format($status['count']) }}</strong></td>
                                        <td>{{ $statusPercentage }}%</td>
                                        <td>
                                            <span class="tf-reports__status-track" aria-hidden="true">
                                                <span class="tf-reports__status-progress tf-reports__status-progress--{{ $status['key'] }}" style="width: {{ $statusPercentage }}%"></span>
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="tf-reports__card tf-reports__distribution-card" aria-labelledby="status-distribution-title">
                    <header class="tf-reports__card-header">
                        <div class="tf-reports__section-icon tf-reports__section-icon--blue"><i class="bi bi-pie-chart" aria-hidden="true"></i></div>
                        <div>
                            <h2 id="status-distribution-title">Task status distribution</h2>
                            <p>Visual representation of task status in the selected period.</p>
                        </div>
                    </header>
                    <div class="tf-reports__chart" role="img" aria-label="Task status distribution bar chart">
                        <div class="tf-reports__chart-grid" aria-hidden="true">
                            @foreach ($statusSummary as $status)
                                @php
                                    $barHeight = $status['count'] > 0 ? max(4, (int) round($status['count'] / $maxStatusCount * 100)) : 0;
                                @endphp
                                <div class="tf-reports__chart-column">
                                    <strong>{{ number_format($status['count']) }}</strong>
                                    <div class="tf-reports__chart-plot">
                                        <span class="tf-reports__chart-bar tf-reports__chart-bar--{{ $status['key'] }}" style="height: {{ $barHeight }}%"></span>
                                    </div>
                                    <span class="tf-reports__chart-label">{{ $status['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="tf-reports__status-summary">
                        <i class="bi bi-lightbulb" aria-hidden="true"></i>
                        <div>
                            <strong>Summary</strong>
                            <p>
                                Out of {{ number_format($totalTasks) }} tasks,
                                {{ number_format($completedTasksCount) }} have been completed ({{ $completedPercentage }}%),
                                {{ number_format($overdueTasksCount) }} are overdue, and
                                {{ number_format($activeTasksCount) }} are currently active ({{ $activePercentage }}%).
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </section>

        <section class="tf-reports__tab-panel" id="report-panel-overdue" role="tabpanel" aria-labelledby="report-tab-overdue" data-report-panel="overdue" hidden>
            <article class="tf-reports__card" aria-labelledby="overdue-report-title">
            <header class="tf-reports__card-header">
                <div class="tf-reports__section-icon tf-reports__section-icon--red"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
                <div>
                    <h2 id="overdue-report-title">Overdue tasks</h2>
                    <p>Open tasks past their deadline, ordered by oldest deadline.</p>
                </div>
                <span class="tf-reports__table-count">{{ number_format($overdueTasksCount) }}</span>
                <a class="tf-reports__view-all" href="{{ route('tasks.index', ['condition' => 'overdue']) }}">View tasks</a>
            </header>
            @if ($overdueTasks->isNotEmpty())
                <div class="tf-reports__table-wrap">
                    <table class="tf-reports__table">
                        <thead><tr><th>Task name</th><th>Description</th><th>Department</th><th>Assignee</th><th>Deadline</th></tr></thead>
                        <tbody>
                            @foreach ($overdueTasks as $task)
                                @php
                                    $taskAssignees = $task->activeAssignees;
                                    if ($taskAssignees->isEmpty() && $task->assignee) {
                                        $taskAssignees = collect([$task->assignee]);
                                    }
                                @endphp
                                <tr>
                                    <td>
                                        <a class="tf-reports__task-link" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                        <small>{{ $task->task_code }}</small>
                                    </td>
                                    <td class="tf-reports__description">{{ $task->description ?: 'No description provided.' }}</td>
                                    <td>{{ $task->department?->name ?? '—' }}</td>
                                    <td>{{ $taskAssignees->pluck('name')->join(', ') ?: 'Unassigned' }}</td>
                                    <td><span class="tf-reports__deadline">{{ ($task->due_date ?? $task->original_deadline)?->format('M d, Y') ?? 'No deadline' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="tf-reports__empty">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    <strong>No overdue tasks</strong>
                    <span>There are no overdue tasks in this report period.</span>
                </div>
            @endif
            </article>
        </section>

        <section class="tf-reports__tab-panel" id="report-panel-completed" role="tabpanel" aria-labelledby="report-tab-completed" data-report-panel="completed" hidden>
            <article class="tf-reports__card" aria-labelledby="completed-report-title">
            <header class="tf-reports__card-header">
                <div class="tf-reports__section-icon tf-reports__section-icon--green"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
                <div>
                    <h2 id="completed-report-title">Completed tasks</h2>
                    <p>Tasks created in this period that are now completed.</p>
                </div>
                <span class="tf-reports__table-count">{{ number_format($completedTasksCount) }}</span>
                <a class="tf-reports__view-all" href="{{ route('tasks.index', ['status' => 'completed']) }}">View tasks</a>
            </header>
            @if ($completedTasks->isNotEmpty())
                <div class="tf-reports__table-wrap">
                    <table class="tf-reports__table">
                        <thead><tr><th>Task name</th><th>Description</th><th>Department</th><th>Assignee</th><th>Deadline</th></tr></thead>
                        <tbody>
                            @foreach ($completedTasks as $task)
                                @php
                                    $taskAssignees = $task->activeAssignees;
                                    if ($taskAssignees->isEmpty() && $task->assignee) {
                                        $taskAssignees = collect([$task->assignee]);
                                    }
                                @endphp
                                <tr>
                                    <td>
                                        <a class="tf-reports__task-link" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                        <small>{{ $task->task_code }}</small>
                                    </td>
                                    <td class="tf-reports__description">{{ $task->description ?: 'No description provided.' }}</td>
                                    <td>{{ $task->department?->name ?? '—' }}</td>
                                    <td>{{ $taskAssignees->pluck('name')->join(', ') ?: 'Unassigned' }}</td>
                                    <td>{{ ($task->due_date ?? $task->original_deadline)?->format('M d, Y') ?? 'No deadline' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="tf-reports__empty">
                    <i class="bi bi-clipboard-check" aria-hidden="true"></i>
                    <strong>No completed tasks</strong>
                    <span>No tasks created in this period have been completed yet.</span>
                </div>
            @endif
            </article>
        </section>

        <section class="tf-reports__tab-panel" id="report-panel-departments" role="tabpanel" aria-labelledby="report-tab-departments" data-report-panel="departments" hidden>
            <article class="tf-reports__card" aria-labelledby="department-summary-title">
            <header class="tf-reports__card-header">
                <div class="tf-reports__section-icon tf-reports__section-icon--violet"><i class="bi bi-diagram-3" aria-hidden="true"></i></div>
                <div>
                    <h2 id="department-summary-title">Department summary</h2>
                    <p>Task workload and outcomes by department.</p>
                </div>
            </header>
            @if ($departmentSummary->isNotEmpty())
                <div class="tf-reports__table-wrap">
                    <table class="tf-reports__table">
                        <thead><tr><th>Department</th><th>Total tasks</th><th>Completed</th><th>Overdue</th></tr></thead>
                        <tbody>
                            @foreach ($departmentSummary as $department)
                                <tr>
                                    <td><strong>{{ $department->name }}</strong></td>
                                    <td>{{ number_format($department->total_tasks) }}</td>
                                    <td><span class="tf-reports__number tf-reports__number--green">{{ number_format($department->completed_tasks) }}</span></td>
                                    <td><span class="tf-reports__number tf-reports__number--red">{{ number_format($department->overdue_tasks) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="tf-reports__empty tf-reports__empty--compact"><strong>No department data for this period.</strong></div>
            @endif
            </article>
        </section>

        <section class="tf-reports__tab-panel" id="report-panel-employees" role="tabpanel" aria-labelledby="report-tab-employees" data-report-panel="employees" hidden>
            <article class="tf-reports__card" aria-labelledby="employee-summary-title">
            <header class="tf-reports__card-header">
                <div class="tf-reports__section-icon tf-reports__section-icon--blue"><i class="bi bi-people" aria-hidden="true"></i></div>
                <div>
                    <h2 id="employee-summary-title">Employee summary</h2>
                    <p>Top 20 employees by current assignments in this period.</p>
                </div>
            </header>
            @if ($employeeSummary->isNotEmpty())
                <div class="tf-reports__table-wrap">
                    <table class="tf-reports__table">
                        <thead><tr><th>Employee</th><th>Department</th><th>Assigned</th><th>Completed</th><th>Overdue</th></tr></thead>
                        <tbody>
                            @foreach ($employeeSummary as $employee)
                                <tr>
                                    <td><strong>{{ $employee->name }}</strong></td>
                                    <td>{{ $employee->department_name ?? '—' }}</td>
                                    <td>{{ number_format($employee->total_tasks) }}</td>
                                    <td><span class="tf-reports__number tf-reports__number--green">{{ number_format($employee->completed_tasks) }}</span></td>
                                    <td><span class="tf-reports__number tf-reports__number--red">{{ number_format($employee->overdue_tasks) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="tf-reports__empty tf-reports__empty--compact"><strong>No employee assignments for this period.</strong></div>
            @endif
            </article>
        </section>
    </div>

    <script>
        (() => {
            const tabs = document.querySelectorAll('[data-report-tab]');
            const panels = document.querySelectorAll('[data-report-panel]');

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    tabs.forEach((item) => {
                        const isActive = item === tab;
                        item.classList.toggle('is-active', isActive);
                        item.setAttribute('aria-selected', String(isActive));
                    });

                    panels.forEach((panel) => {
                        const isActive = panel.dataset.reportPanel === tab.dataset.reportTab;
                        panel.hidden = !isActive;
                        panel.classList.toggle('is-active', isActive);
                    });
                });
            });
        })();
    </script>
@endsection

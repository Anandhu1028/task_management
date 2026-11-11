@extends('layouts.app')

@section('title', 'Daily Work Report Dashboard')

@section('content')
    @php
        $creatableTasks = $tasks->filter(fn ($task) => auth()->user()->can('createSubtask', $task));
        $priorityLabels = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
        $priorityIcons = [
            'critical' => 'bi-exclamation-triangle',
            'high' => 'bi-chevron-double-up',
            'medium' => 'bi-layers',
            'low' => 'bi-chevron-double-down',
        ];
        $taskStatusColors = [
            'notified' => '#8b5cf6',
            'unassigned' => '#94a3b8',
            'assigned' => '#94a3b8',
            'in_progress' => '#f87171',
            'on_hold' => '#f59e0b',
            'submitted_for_review' => '#60a5fa',
            'rework' => '#a855f7',
            'completed' => '#22c55e',
            'cancelled' => '#94a3b8',
            'progress' => '#64748b',
        ];
        $mainTaskStatusLabels = [
            'assigned' => 'Assigned',
            'in_progress' => 'In-progress',
            'on_hold' => 'On-hold',
            'submitted_for_review' => 'Review',
            'rework' => 'Rework',
            'completed' => 'Completed',
            'cancelled' => 'Canceled',
            'unassigned' => 'Unassigned',
            'progress' => 'Progress',
            'notified' => 'Notified',
        ];
        $mainTaskStatusMaxCount = (int) max($mainTaskStatusBreakdown->all() ?: [0]);

        // Build a readable, data-driven horizontal axis starting at zero.
        $rawAxisStep = max(1, (int) ceil($mainTaskStatusMaxCount / 4));
        $axisMagnitude = 10 ** (int) floor(log10($rawAxisStep));
        $normalizedAxisStep = $rawAxisStep / $axisMagnitude;

        $niceAxisMultiplier = match (true) {
            $normalizedAxisStep <= 1 => 1,
            $normalizedAxisStep <= 2 => 2,
            $normalizedAxisStep <= 2.5 => 2.5,
            $normalizedAxisStep <= 4 => 4,
            $normalizedAxisStep <= 5 => 5,
            default => 10,
        };

        $mainTaskStatusAxisStep = max(1, (int) round($niceAxisMultiplier * $axisMagnitude));
        $mainTaskStatusAxisMax = max(
            $mainTaskStatusAxisStep * 4,
            (int) (ceil($mainTaskStatusMaxCount / $mainTaskStatusAxisStep) * $mainTaskStatusAxisStep)
        );

        // Five axis labels: zero, three intermediate values, and the calculated maximum.
        $mainTaskStatusAxisTicks = collect(range(0, 4))->map(
            fn (int $tick): int => (int) round(($mainTaskStatusAxisMax / 4) * $tick)
        );
        $dashboardStatusGroups = [
            'completed' => ['label' => 'Completed', 'icon' => 'bi-check-circle', 'statuses' => ['completed']],
            'in_progress' => ['label' => 'In Progress', 'icon' => 'bi-arrow-repeat', 'statuses' => ['in_progress']],
            'on_hold' => ['label' => 'On Hold', 'icon' => 'bi-pause-circle', 'statuses' => ['on_hold']],
            'pending' => ['label' => 'Pending', 'icon' => 'bi-hourglass-split', 'statuses' => ['todo', 'pending', 'assigned', 'in_review']],
        ];
        $summaryStartDate = \Illuminate\Support\Carbon::parse($filters['from_date']);
        $summaryEndDate = \Illuminate\Support\Carbon::parse($filters['to_date']);
        $summaryDate = match ($filters['date_range']) {
            'today' => 'Today · '.$summaryStartDate->format('F j, Y'),
            'yesterday' => 'Yesterday · '.$summaryStartDate->format('F j, Y'),
            default => $summaryStartDate->isSameDay($summaryEndDate)
                ? $summaryStartDate->format('F j, Y')
                : $summaryStartDate->format('F j').' – '.$summaryEndDate->format('F j, Y'),
        };
        $statusCountTotal = collect($subtaskStatuses)->count();
        $totalSubtasks = $metrics['total_subtasks'];
        $metricCards = [
            [
                'label' => 'Total Main Tasks',
                'value' => $metrics['total_main_tasks'],
                'icon' => 'bi-kanban',
                'tone' => 'tasks',
                'percentage' => $metrics['total_main_tasks'] > 0
                    ? round(($metrics['completed_tasks'] / $metrics['total_main_tasks']) * 100)
                    : 0,
                'detail' => number_format($metrics['completed_tasks']).' completed / '.number_format($metrics['total_main_tasks']).' total',
            ],
            [
                'label' => 'Critical Subtasks',
                'value' => $metrics['critical_subtasks'],
                'icon' => 'bi-lightning-charge',
                'tone' => 'critical',
                'percentage' => $totalSubtasks > 0 ? round(($metrics['critical_subtasks'] / $totalSubtasks) * 100) : 0,
                'detail' => number_format($metrics['critical_subtasks']).' / '.number_format($totalSubtasks).' subtasks',
            ],
            [
                'label' => 'Completed Tasks',
                'value' => $metrics['completed_tasks'],
                'icon' => 'bi-check2-circle',
                'tone' => 'completed',
                'percentage' => $metrics['total_main_tasks'] > 0
                    ? round(($metrics['completed_tasks'] / $metrics['total_main_tasks']) * 100)
                    : 0,
                'detail' => number_format($metrics['completed_tasks']).' / '.number_format($metrics['total_main_tasks']).' tasks',
            ],
            [
                'label' => 'Completed Subtasks',
                'value' => $metrics['completed_subtasks'],
                'icon' => 'bi-check-circle',
                'tone' => 'subtasks',
                'percentage' => $totalSubtasks > 0 ? round(($metrics['completed_subtasks'] / $totalSubtasks) * 100) : 0,
                'detail' => number_format($metrics['completed_subtasks']).' / '.number_format($totalSubtasks).' subtasks',
            ],
        ];

        // Overall subtask status summary (all main tasks together).
        $allSubtasks = $mainTaskSubtasks->collapse();
        $allSubtaskTotal = $allSubtasks->count();
        $allSubtaskBreakdown = $allSubtasks->countBy(
            fn ($subtask) => strtolower($subtask->status ?? 'pending')
        );
        $dashboardStatusMetrics = collect($dashboardStatusGroups)->mapWithKeys(
            function (array $statusGroup, string $status) use ($allSubtaskBreakdown, $allSubtaskTotal): array {
                $count = collect($statusGroup['statuses'])
                    ->sum(fn (string $subtaskStatus): int => $allSubtaskBreakdown[$subtaskStatus] ?? 0);

                return [$status => [
                    'count' => $count,
                    'percentage' => $allSubtaskTotal > 0 ? round(($count / $allSubtaskTotal) * 100) : 0,
                ]];
            }
        );
        $completedSubtaskCount = $dashboardStatusMetrics['completed']['count'];
        $overallSubtaskProgress = $allSubtaskTotal > 0
            ? round(($completedSubtaskCount / $allSubtaskTotal) * 100)
            : 0;
    @endphp

    <div class="tf-dwr">
        <header class="tf-dwr__header">
            <div>
                <h1>DWR Dashboard</h1>
            </div>
            <div class="tf-dwr__header-actions">
                <form class="tf-dwr-header-filters" id="dwrHeaderFilters" method="GET" action="{{ route('dwr.index') }}">
                    <label class="tf-dwr-header-filters__date-range">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        <span class="visually-hidden">Date</span>
                        <select class="form-select form-select-sm" name="date_range" id="dwrDateRange">
                            <option value="today" @selected($filters['date_range'] === 'today')>Today</option>
                            <option value="yesterday" @selected($filters['date_range'] === 'yesterday')>Yesterday</option>
                            <option value="custom" @selected($filters['date_range'] === 'custom')>Choose date</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-dwr-header-filters__custom-date tf-dwr-custom-date" @if ($filters['date_range'] !== 'custom') hidden @endif>
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        <span class="visually-hidden">Select date</span>
                        <input class="form-control form-control-sm" type="date" id="dwrSelectedDate" value="{{ $filters['from_date'] }}" @required($filters['date_range'] === 'custom')>
                    </label>
                    <input type="hidden" name="from_date" id="dwrFromDate" value="{{ $filters['from_date'] }}">
                    <input type="hidden" name="to_date" id="dwrToDate" value="{{ $filters['to_date'] }}">
                    @if ($canMonitorEmployees)
                        <label class="tf-dwr-header-filters__employee">
                            <i class="bi bi-people" aria-hidden="true"></i>
                            <span class="visually-hidden">Employee</span>
                            <select class="form-select form-select-sm" name="employee_id">
                                <option value="">All employees</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected((string) ($filters['employee_id'] ?? '') === (string) $employee->id)>
                                        {{ $employee->name }}
                                    </option>
                                @endforeach
                            </select>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </label>
                    @endif
                    <button class="btn btn-sm btn-primary tf-dwr-header-filters__apply" type="submit">
                        <i class="bi bi-funnel"></i><span>Apply</span>
                    </button>
                </form>
            </div>
        </header>

        {{-- KPI cards --}}
        <section class="tf-dwr__metrics" aria-label="Daily work report summary">
            @foreach ($metricCards as $metric)
                <article class="tf-dwr-metric tf-dwr-metric--{{ $metric['tone'] }}">
                    <div class="tf-dwr-metric__content">
                        <div class="tf-dwr-metric__heading">
                            <span class="tf-dwr-metric__icon" aria-hidden="true"><i class="bi {{ $metric['icon'] }}"></i></span>
                            <h2 class="tf-dwr-metric__title">{{ $metric['label'] }}</h2>
                        </div>
                        <strong class="tf-dwr-metric__value">{{ number_format($metric['value']) }}</strong>
                        <span class="tf-dwr-metric__detail">{{ $metric['detail'] }}</span>
                    </div>
                    <div class="tf-dwr-metric__gauge" style="--tf-dwr-progress: {{ $metric['percentage'] }}%" role="img" aria-label="{{ $metric['percentage'] }} percent: {{ $metric['detail'] }}">
                        <span>{{ $metric['percentage'] }}%</span>
                    </div>
                </article>
            @endforeach
        </section>

        @if ($missingAccountabilities->isNotEmpty())
            <section class="tf-dwr-warning" aria-labelledby="dwr-warning-title">
                <div class="tf-dwr-warning__title">
                    <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                    <div>
                        <h2 id="dwr-warning-title">No Work Activity Recorded</h2>
                        <p>Daily accountability warnings are evaluated after 11:59 PM.</p>
                    </div>
                </div>
                <div class="tf-dwr-warning__list">
                    @foreach ($missingAccountabilities as $accountability)
                        <article>
                            <strong>{{ $accountability->employee?->name ?? $accountability->employee_name }}</strong>
                            <span>{{ $accountability->activity_date->format('d/m/Y') }}</span>
                            <span>No work activity was submitted for this date.</span>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Main task priority (left) and main task status (right) --}}
        <section class="tf-dwr-overviews" aria-label="Main task priority and status">
            <article class="tf-dwr-panel tf-dwr-breakdown">
                <div class="tf-dwr-panel__heading">
                    <div><h2>Main Task Priority</h2><p>Critical priority tasks are highlighted first.</p></div>
                    <span>{{ number_format($priorityTaskCountTotal) }} - {{ \Illuminate\Support\Pluralizer::plural('Task', $priorityTaskCountTotal) }}</span>
                </div>
                @foreach ($priorityLabels as $priority => $label)
                    @php
                        $priorityCount = $priorityBreakdown[$priority] ?? 0;
                        $priorityPercent = $priorityTaskCountTotal > 0
                            ? round(($priorityCount / $priorityTaskCountTotal) * 100)
                            : 0;
                    @endphp
                    <div class="tf-dwr-bar-row tf-dwr-bar-row--{{ $priority }}">
                        <div class="tf-dwr-bar-row__label">
                            <span class="tf-dwr-priority-icon tf-dwr-priority-icon--{{ $priority }}" aria-hidden="true">
                                <i class="bi {{ $priorityIcons[$priority] }}"></i>
                            </span>
                            <strong class="tf-dwr-priority-label">{{ $label }}</strong>
                            <span class="tf-dwr-priority-percent">{{ $priorityPercent }}%</span>
                            <div class="tf-dwr-bar" role="progressbar" aria-label="{{ $label }} priority tasks" aria-valuenow="{{ $priorityPercent }}" aria-valuemin="0" aria-valuemax="100">
                                <span style="width: {{ $priorityPercent }}%"></span>
                            </div>
                            <strong>{{ number_format($priorityCount) }}</strong>
                        </div>
                    </div>
                @endforeach
            </article>

            {{-- Main Task Status: right-side horizontal bar chart --}}
            <article class="tf-dwr-panel tf-dwr-breakdown tf-dwr-task-status-breakdown" aria-label="Main task status breakdown">
                <div class="tf-dwr-panel__heading">
                    <div>
                        <h2>Main Task Status</h2>
                        <p>Distribution of main tasks by workflow status.</p>
                    </div>
                    <span>{{ number_format($mainTaskStatusCountTotal) }} {{ \Illuminate\Support\Pluralizer::plural('Task', $mainTaskStatusCountTotal) }}</span>
                </div>

                <div class="tf-dwr-task-status-chart" role="img" aria-label="Horizontal bar chart showing the number of main tasks in each status">
                    <div class="tf-dwr-task-status-chart__rows">
                        @foreach ($mainTaskStatusLabels as $taskStatus => $taskStatusLabel)
                            @php
                                $taskStatusCount = (int) ($mainTaskStatusBreakdown[$taskStatus] ?? 0);
                                $taskStatusPercent = $mainTaskStatusAxisMax > 0
                                    ? round(($taskStatusCount / $mainTaskStatusAxisMax) * 100, 2)
                                    : 0;
                            @endphp
                            <div class="tf-dwr-task-status-chart__row"
                                 style="--tf-dwr-task-status-color: {{ $taskStatusColors[$taskStatus] ?? '#64748b' }}">
                                <span class="tf-dwr-task-status-chart__label">{{ $taskStatusLabel }}</span>
                                <div class="tf-dwr-task-status-chart__plot">
                                    <span class="tf-dwr-task-status-chart__bar"
                                          style="width: {{ $taskStatusPercent }}%"
                                          role="presentation"></span>
                                    <strong class="tf-dwr-task-status-chart__value"
                                            style="left: calc({{ $taskStatusPercent }}% + 5px)">{{ number_format($taskStatusCount) }}</strong>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="tf-dwr-task-status-chart__axis" aria-hidden="true">
                        <span class="tf-dwr-task-status-chart__axis-spacer"></span>
                        <div class="tf-dwr-task-status-chart__axis-plot">
                            @foreach ($mainTaskStatusAxisTicks as $tick)
                                <span>{{ number_format($tick) }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="tf-dwr-task-status-chart__axis-title">Number of main tasks</div>
                </div>
            </article>
        </section>

        {{-- Subtask Status overview and task-by-task progress --}}
        <section class="tf-dwr-panel tf-dwr-subtask-status-breakdown" aria-labelledby="dwrSubtaskStatusTitle">
            <h2 class="visually-hidden" id="dwrSubtaskStatusTitle">Subtask Status</h2>

            <h3 class="tf-dwr-subtask-list-heading">
                <span><i class="bi bi-layers" aria-hidden="true"></i> Subtasks by Main Task</span>
                <span>{{ number_format($mainTaskSummaries->sum('total_subtasks')) }} Total</span>
            </h3>

            <div class="tf-dwr-subtask-overview">
                <article class="tf-dwr-subtask-progress">
                    <svg class="tf-dwr-subtask-progress__chart" viewBox="0 0 360 112" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        <defs>
                            <linearGradient id="dwrProgressAreaGradient" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#3b82f6" stop-opacity=".38" />
                                <stop offset="100%" stop-color="#3b82f6" stop-opacity=".04" />
                            </linearGradient>
                        </defs>
                        <path class="tf-dwr-subtask-progress__chart-area" d="M0 104 C34 101 47 95 78 86 S130 65 166 48 S214 28 244 39 S288 30 314 12 S347 1 360 0 V112 H0 Z" />
                        <path class="tf-dwr-subtask-progress__chart-line" d="M0 104 C34 101 47 95 78 86 S130 65 166 48 S214 28 244 39 S288 30 314 12 S347 1 360 0" />
                    </svg>
                    <div class="tf-dwr-subtask-progress__copy">
                        <span class="tf-dwr-subtask-progress__label">Overall Progress</span>
                        <strong class="tf-dwr-subtask-progress__value">{{ $overallSubtaskProgress }}%</strong>
                        <span class="tf-dwr-subtask-progress__detail">
                            {{ number_format($completedSubtaskCount) }} of {{ number_format($allSubtaskTotal) }} subtasks completed
                        </span>
                    </div>
                    <div class="tf-dwr-subtask-progress__track" role="progressbar"
                         aria-label="Overall subtask progress" aria-valuenow="{{ $overallSubtaskProgress }}"
                         aria-valuemin="0" aria-valuemax="100">
                        <span style="width: {{ $overallSubtaskProgress }}%"></span>
                    </div>
                </article>

                <div class="tf-dwr-subtask-status-cards" aria-label="Subtask counts by status">
                    @foreach ($dashboardStatusGroups as $status => $statusGroup)
                        @php
                            $statusMetric = $dashboardStatusMetrics[$status];
                        @endphp
                        <article class="tf-dwr-subtask-status-card tf-dwr-subtask-status-card--{{ $status }}">
                            <span class="tf-dwr-subtask-status-card__icon" aria-hidden="true">
                                <i class="bi {{ $statusGroup['icon'] }}"></i>
                            </span>
                            <div class="tf-dwr-subtask-status-card__content">
                                <span>{{ $statusGroup['label'] }}</span>
                            </div>
                            <span class="tf-dwr-subtask-status-card__percent">{{ $statusMetric['percentage'] }}%</span>
                            <div class="tf-dwr-subtask-status-card__progress" role="progressbar"
                                 aria-label="{{ $statusGroup['label'] }} subtasks"
                                 aria-valuenow="{{ $statusMetric['percentage'] }}" aria-valuemin="0" aria-valuemax="100">
                                <span style="width: {{ $statusMetric['percentage'] }}%"></span>
                            </div>
                            <strong class="tf-dwr-subtask-status-card__count">{{ number_format($statusMetric['count']) }}</strong>
                        </article>
                    @endforeach
                </div>
            </div>

            <div class="tf-dwr-subtask-status-groups">
                <div class="tf-dwr-subtask-table-scroll" role="region" aria-label="Subtasks by main task" tabindex="0">
                    <table class="tf-dwr-subtask-table">
                        <thead>
                            <tr>
                                <th scope="col">Subtask</th>
                                <th scope="col">Description</th>
                                <th scope="col">Assignee</th>
                                <th scope="col">Status</th>
                                <th scope="col">Main Task</th>
                            </tr>
                        </thead>
                        @forelse ($mainTaskSummaries as $statusTaskSummary)
                            @php
                                $statusTaskSubtasks = $mainTaskSubtasks
                                    ->get($statusTaskSummary->task_id, collect())
                                    ->values();
                                $mainTaskUrl = route('tasks.show', ['task' => $statusTaskSummary->task_id]);
                            @endphp

                            <tbody class="tf-dwr-subtask-table__group">
                                <tr class="tf-dwr-subtask-table__group-heading">
                                    <th colspan="5" scope="rowgroup">
                                        <div class="tf-dwr-subtask-table__group-label">
                                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                            <i class="bi bi-folder2-open tf-dwr-subtask-table__folder" aria-hidden="true"></i>
                                            <div class="tf-dwr-subtask-table__group-content">
                                                <div class="tf-dwr-subtask-table__group-title">
                                                    <a href="{{ $mainTaskUrl }}" title="{{ $statusTaskSummary->task_title }}">{{ \Illuminate\Support\Str::limit($statusTaskSummary->task_title, 20) }}</a>
                                                    <span class="tf-dwr-subtask-table__main-task-label">(Main task)</span>
                                                </div>
                                                <span class="tf-dwr-subtask-status-group__count">
                                                    {{ number_format($statusTaskSubtasks->count()) }}
                                                    {{ \Illuminate\Support\Pluralizer::plural('Subtask', $statusTaskSubtasks->count()) }}
                                                </span>
                                            </div>
                                        </div>
                                    </th>
                                </tr>
                                @foreach ($statusTaskSubtasks as $ssSubtask)
                                    @php
                                        $ssStatus = strtolower($ssSubtask->status ?? 'pending');
                                        $ssStatusKey = \Illuminate\Support\Str::slug($ssStatus, '_');
                                        $ssStatusLabel = \App\Models\Subtask::STATUS_LABELS[$ssStatus]
                                            ?? \Illuminate\Support\Str::headline($ssStatus);
                                    @endphp

                                    <tr class="tf-dwr-subtask-table__row tf-dwr-subtask-row--{{ $ssStatusKey }}">
                                        <th scope="row" class="tf-dwr-subtask-table__subtask-title">
                                            <span class="tf-dwr-subtask-table__tree" aria-hidden="true"></span>
                                            <span title="{{ $ssSubtask->title }}">{{ $ssSubtask->title }}</span>
                                        </th>
                                        <td class="tf-dwr-subtask-table__description">
                                            {{ $ssSubtask->description ?: '—' }}
                                        </td>
                                        <td>
                                            @if ($ssSubtask->assignee)
                                                <span class="tf-dwr-subtask-table__assignee">
                                                    <span class="tf-dwr-subtask-table__avatar" aria-hidden="true">
                                                        {{ mb_strtoupper(mb_substr($ssSubtask->assignee->name, 0, 1)) }}
                                                    </span>
                                                    {{ $ssSubtask->assignee->name }}
                                                </span>
                                            @else
                                                <span class="tf-dwr-subtask-table__unassigned">Unassigned</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="tf-dwr-subtask-status tf-dwr-subtask-status--{{ $ssStatusKey }}">
                                                <span class="tf-dwr-subtask-status__dot" aria-hidden="true"></span>
                                                {{ $ssStatusLabel }}
                                            </span>
                                        </td>
                                        <td>
                                            <a class="tf-dwr-subtask-table__main-task-link" href="{{ $mainTaskUrl }}">
                                                {{ $statusTaskSummary->task_title }}
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @empty
                            <tbody>
                                <tr>
                                    <td colspan="5" class="tf-dwr-subtask-table__empty">
                                        No main tasks have subtasks matching these filters.
                                    </td>
                                </tr>
                            </tbody>
                        @endforelse
                    </table>
                </div>
            </div>
        </section>

      
    </div>

    {{-- Create subtask modal --}}
    @if ($creatableTasks->isNotEmpty())
        <div class="modal fade" id="dwrCreateSubtask" tabindex="-1" aria-labelledby="dwrCreateSubtaskTitle" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form id="dwrCreateSubtaskForm" method="POST" data-action-template="{{ route('subtasks.store', ['task' => '__TASK__']) }}">
                        @csrf
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="dwrCreateSubtaskTitle">Create subtask</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body tf-dwr-create-form">
                            <label><span>Main task</span>
                                <select name="task_id" id="dwrCreateTask" class="form-select" required>
                                    <option value="">Select a main task</option>
                                    @foreach ($creatableTasks as $task)
                                        <option value="{{ $task->id }}" data-department="{{ $task->department_id }}" data-company="{{ $task->company_id }}">
                                            {{ $task->task_code }} · {{ $task->title }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <label><span>Subtask title</span><input class="form-control" name="title" maxlength="500" required></label>
                            <label class="tf-dwr-create-form__wide"><span>Description</span><textarea class="form-control" name="description" rows="3" maxlength="5000"></textarea></label>
                            <label><span>Assigned employee</span>
                                <select class="form-select" name="assigned_to" id="dwrCreateAssignee">
                                    @if ($canMonitorEmployees)
                                        <option value="">Unassigned</option>
                                        @foreach ($employees as $employee)
                                            <option value="{{ $employee->id }}" data-company="{{ $employee->company_id }}" data-department="{{ $employee->department_id }}">
                                                {{ $employee->name }}
                                            </option>
                                        @endforeach
                                    @else
                                        <option value="{{ auth()->id() }}" data-company="{{ auth()->user()->company_id }}" data-department="{{ auth()->user()->department_id }}">
                                            {{ auth()->user()->name }}
                                        </option>
                                    @endif
                                </select>
                            </label>
                            <label><span>Main task priority</span><input class="form-control" value="Inherited from main task" disabled></label>
                            @if ($canMonitorEmployees)
                                <label><span>Initial status</span>
                                    <select class="form-select" name="status">
                                        @foreach ($subtaskStatuses as $status)
                                            <option value="{{ $status }}">{{ \App\Models\Subtask::STATUS_LABELS[$status] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label><span>Subtask priority</span>
                                    <select class="form-select" name="priority">
                                        @foreach (['critical', 'high', 'medium', 'low'] as $priority)
                                            <option value="{{ $priority }}" @selected($priority === 'medium')>{{ $priorityLabels[$priority] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label><span>Start date</span><input class="form-control" type="date" name="start_date"></label>
                                <label><span>Due date</span><input class="form-control" type="date" name="due_date"></label>
                                <label><span>Estimated hours</span><input class="form-control" type="number" name="estimated_hours" min="0.01" step="0.01"></label>
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Create subtask</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        (() => {
            const dateRange = document.getElementById('dwrDateRange');
            const dateField = document.querySelector('.tf-dwr-header-filters__custom-date');
            const selectedDate = document.getElementById('dwrSelectedDate');
            const fromDate = document.getElementById('dwrFromDate');
            const toDate = document.getElementById('dwrToDate');
            const filterForm = document.getElementById('dwrHeaderFilters');
            const syncDateFields = () => {
                const customDateSelected = dateRange?.value === 'custom';
                if (dateField) {
                    dateField.hidden = !customDateSelected;
                }
                if (selectedDate) {
                    selectedDate.required = customDateSelected;
                }
                if (customDateSelected && selectedDate?.value) {
                    fromDate.value = selectedDate.value;
                    toDate.value = selectedDate.value;
                }
            };
            dateRange?.addEventListener('change', syncDateFields);
            selectedDate?.addEventListener('change', syncDateFields);
            filterForm?.addEventListener('submit', syncDateFields);
            syncDateFields();

            const form = document.getElementById('dwrCreateSubtaskForm');
            const taskSelect = document.getElementById('dwrCreateTask');
            const assigneeSelect = document.getElementById('dwrCreateAssignee');
            if (form && taskSelect) {
                const template = form.dataset.actionTemplate;
                taskSelect.addEventListener('change', () => {
                    form.action = template.replace('__TASK__', taskSelect.value);
                    const selectedTask = taskSelect.selectedOptions[0];
                    [...assigneeSelect.options].forEach(option => {
                        if (!option.value) {
                            option.hidden = false;
                            return;
                        }
                        option.hidden = option.dataset.company !== selectedTask.dataset.company
                            || option.dataset.department !== selectedTask.dataset.department;
                    });
                    if (assigneeSelect.selectedOptions[0]?.hidden) {
                        assigneeSelect.value = '';
                    }
                });
                form.addEventListener('submit', event => {
                    if (!taskSelect.value) {
                        event.preventDefault();
                        taskSelect.focus();
                    }
                });
            }
        })();
    </script>
@endpush

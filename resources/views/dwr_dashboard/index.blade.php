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
        // Round the maximum up to a sensible interval and leave room for value labels.
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
            'on_hold' => ['label' => 'On Hold', 'icon' => 'bi-pause-circle', 'statuses' => ['on_hold']],
            'in_progress' => ['label' => 'In Progress', 'icon' => 'bi-arrow-repeat', 'statuses' => ['in_progress']],
            'pending' => ['label' => 'Pending', 'icon' => 'bi-hourglass-split', 'statuses' => ['todo', 'assigned', 'in_review']],
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
    @endphp

    <div class="tf-dwr">
        <header class="tf-dwr__header">
            <div>
                <h1>DWR Dashboard</h1>
                <p>Monitor subtask progress and employee work updates across authorized tasks.</p>
            </div>
            <div class="tf-dwr__header-actions">
                <form class="tf-dwr-header-filters" id="dwrHeaderFilters" method="GET" action="{{ route('dwr.index') }}">
                    <label class="tf-dwr-header-filters__date-range">
                        <span>Date</span>
                        <select class="form-select form-select-sm" name="date_range" id="dwrDateRange">
                            <option value="today" @selected($filters['date_range'] === 'today')>Today</option>
                            <option value="yesterday" @selected($filters['date_range'] === 'yesterday')>Yesterday</option>
                            <option value="custom" @selected($filters['date_range'] === 'custom')>Choose date</option>
                        </select>
                    </label>
                    <label class="tf-dwr-header-filters__custom-date tf-dwr-custom-date" @if ($filters['date_range'] !== 'custom') hidden @endif>
                        <span>Select date</span>
                        <input class="form-control form-control-sm" type="date" id="dwrSelectedDate" value="{{ $filters['from_date'] }}" @required($filters['date_range'] === 'custom')>
                    </label>
                    <input type="hidden" name="from_date" id="dwrFromDate" value="{{ $filters['from_date'] }}">
                    <input type="hidden" name="to_date" id="dwrToDate" value="{{ $filters['to_date'] }}">
                    @if ($canMonitorEmployees)
                        <label class="tf-dwr-header-filters__employee">
                            <span>Employee</span>
                            <select class="form-select form-select-sm" name="employee_id">
                                <option value="">All employees</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected((string) ($filters['employee_id'] ?? '') === (string) $employee->id)>
                                        {{ $employee->name }}
                                    </option>
                                @endforeach
                            </select>
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

        {{-- Subtask status --}}
        <section class="tf-dwr-panel tf-dwr-breakdown tf-dwr-subtask-status-breakdown" aria-label="Subtask status breakdown">
            <div class="tf-dwr-panel__heading">
                <div><h2>Subtask Status</h2><p>See how work is progressing across the selected filters.</p></div>
                <span>{{ number_format($metrics['total_subtasks']) }} total</span>
            </div>
            <div class="tf-dwr-subtask-status-rows">
                @foreach ($dashboardStatusGroups as $status => $statusGroup)
                    @php
                        $statusCount = collect($statusGroup['statuses'])
                            ->sum(fn (string $subtaskStatus): int => $statusBreakdown[$subtaskStatus] ?? 0);
                        $statusPercent = $metrics['total_subtasks'] > 0
                            ? round(($statusCount / $metrics['total_subtasks']) * 100)
                            : 0;
                    @endphp
                    <div class="tf-dwr-bar-row tf-dwr-bar-row--status-{{ $status }}">
                        <div class="tf-dwr-bar-row__label">
                            <span class="tf-dwr-status-icon tf-dwr-status-icon--{{ $status }}" aria-hidden="true">
                                <i class="bi {{ $statusGroup['icon'] }}"></i>
                            </span>
                            <strong class="tf-dwr-priority-label">{{ $statusGroup['label'] }}</strong>
                            <span class="tf-dwr-priority-percent">{{ $statusPercent }}%</span>
                            <div class="tf-dwr-bar" role="progressbar" aria-label="{{ $statusGroup['label'] }} subtasks" aria-valuenow="{{ $statusPercent }}" aria-valuemin="0" aria-valuemax="100">
                                <span style="width: {{ $statusPercent }}%"></span>
                            </div>
                            <strong>{{ number_format($statusCount) }}</strong>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Subtasks by Main Task --}}
        <section class="tf-dwr-panel tf-dwr-main-task-panel" aria-labelledby="dwr-main-subtasks-title">
            <div class="tf-dwr-panel__heading tf-dwr-main-task-panel__heading">
                <div>
                    <h2 id="dwr-main-subtasks-title">Subtasks by Main Task</h2>
                    <p>Priority, progress and workflow status of every subtask for <strong>{{ $summaryDate }}</strong>.</p>
                </div>
                <span>{{ number_format($mainTaskSummaries->count()) }} {{ \Illuminate\Support\Pluralizer::plural('main task', $mainTaskSummaries->count()) }}</span>
            </div>

            <div class="tf-dwr-main-task-list">
                @forelse ($mainTaskSummaries as $taskSummary)
                    @php
                        $summaryPriority = strtolower($taskSummary->task_priority ?? 'low');
                        $taskSubtasks = $mainTaskSubtasks
                            ->get($taskSummary->task_id, collect())
                            ->sortBy(function ($subtask) {
                                $priorityOrder = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];

                                return sprintf(
                                    '%02d-%010d',
                                    $priorityOrder[strtolower($subtask->priority ?? 'low')] ?? 5,
                                    (int) $subtask->id
                                );
                            })
                            ->values();

                        $summaryTotal = $taskSubtasks->count();
                        $summaryCompleted = $taskSubtasks->where('status', 'completed')->count();
                        $summaryProgress = $summaryTotal > 0
                            ? (int) round(($summaryCompleted / $summaryTotal) * 100)
                            : 0;
                    @endphp

                    <article class="tf-dwr-main-task-card">
                        <header class="tf-dwr-main-task-card__header">
                            <span class="tf-dwr-main-task-card__number" aria-label="Main task {{ $loop->iteration }}">
                                #{{ $loop->iteration }}
                            </span>

                            <a class="tf-dwr-main-task-card__identity"
                               href="{{ route('tasks.show', ['task' => $taskSummary->task_id]) }}">
                                <span class="tf-dwr-code">{{ $taskSummary->task_code }}</span>
                                <span class="tf-dwr-main-task-card__title">{{ $taskSummary->task_title }}</span>
                            </a>

                            <div class="tf-dwr-main-task-card__progress"
                                 role="img"
                                 aria-label="{{ $summaryCompleted }} of {{ $summaryTotal }} subtasks completed">
                                <div class="tf-dwr-main-task-card__progress-label">
                                    <strong>{{ $summaryCompleted }} / {{ $summaryTotal }} subtasks</strong>
                                    <span>{{ $summaryProgress }}%</span>
                                </div>
                                <div class="tf-dwr-main-task-card__progress-track">
                                    <span style="width: {{ $summaryProgress }}%"></span>
                                </div>
                            </div>

                            <span class="tf-dwr-priority tf-dwr-priority--{{ in_array($summaryPriority, ['critical', 'high', 'medium', 'low'], true) ? $summaryPriority : 'low' }}">
                                <i class="bi {{ $priorityIcons[$summaryPriority] ?? 'bi-chevron-double-down' }}" aria-hidden="true"></i>
                                {{ $priorityLabels[$summaryPriority] ?? 'Low' }} priority
                            </span>
                        </header>

                        <div class="tf-dwr-main-task-card__body">
                            @if ($taskSubtasks->isNotEmpty())
                                <div class="tf-dwr-subtask-table-wrap">
                                    <table class="tf-dwr-subtask-table">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="tf-dwr-subtask-table__number-heading">#</th>
                                                <th scope="col">Subtask title</th>
                                                <th scope="col">Priority</th>
                                                <th scope="col">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($taskSubtasks as $summarySubtask)
                                                @php
                                                    $subtaskPriority = strtolower($summarySubtask->priority ?? 'low');
                                                    $subtaskStatus = strtolower($summarySubtask->status ?? 'pending');
                                                    $subtaskStatusLabel = \App\Models\Subtask::STATUS_LABELS[$subtaskStatus]
                                                        ?? \Illuminate\Support\Str::headline($subtaskStatus);
                                                @endphp
                                                <tr>
                                                    <td class="tf-dwr-subtask-table__number">
                                                        {{ $loop->parent->iteration }}.{{ $loop->iteration }}
                                                    </td>
                                                    <td class="tf-dwr-subtask-table__title">
                                                        <span class="tf-dwr-subtask-table__icon" aria-hidden="true">
                                                            <i class="bi bi-list-check"></i>
                                                        </span>
                                                        <strong>{{ $summarySubtask->title }}</strong>
                                                    </td>
                                                    <td>
                                                        <span class="tf-dwr-priority tf-dwr-priority--{{ in_array($subtaskPriority, ['critical', 'high', 'medium', 'low'], true) ? $subtaskPriority : 'low' }}">
                                                            <i class="bi {{ $priorityIcons[$subtaskPriority] ?? 'bi-chevron-double-down' }}" aria-hidden="true"></i>
                                                            {{ $priorityLabels[$subtaskPriority] ?? 'Low' }}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="tf-dwr-subtask-status tf-dwr-subtask-status--{{ \Illuminate\Support\Str::slug($subtaskStatus, '-') }}">
                                                            <span class="tf-dwr-subtask-status__dot" aria-hidden="true"></span>
                                                            {{ $subtaskStatusLabel }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <div class="tf-dwr-main-task-card__empty">
                                    <i class="bi bi-inbox" aria-hidden="true"></i>
                                    <span>No subtasks found for this main task.</span>
                                </div>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="tf-dwr-empty">No main tasks have subtasks matching these filters.</div>
                @endforelse
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
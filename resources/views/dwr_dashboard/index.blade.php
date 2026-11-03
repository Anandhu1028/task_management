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

        {{-- Priority + Status breakdown --}}
        <section class="tf-dwr-overviews" aria-label="Subtask breakdown">
            <article class="tf-dwr-panel tf-dwr-breakdown">
                <div class="tf-dwr-panel__heading">
                    <div><h2>Main Task Priority</h2><p>Critical priority tasks are highlighted first.</p></div>
                    <span>{{ number_format($metrics['total_subtasks']) }} subtasks</span>
                </div>
                @foreach ($priorityLabels as $priority => $label)
                    @php
                        $priorityCount = $priorityBreakdown[$priority] ?? 0;
                        $priorityPercent = $metrics['total_subtasks'] > 0
                            ? round(($priorityCount / $metrics['total_subtasks']) * 100)
                            : 0;
                    @endphp
                    <div class="tf-dwr-bar-row tf-dwr-bar-row--{{ $priority }}">
                        <div class="tf-dwr-bar-row__label">
                            <span class="tf-dwr-priority-icon tf-dwr-priority-icon--{{ $priority }}" aria-hidden="true">
                                <i class="bi {{ $priorityIcons[$priority] }}"></i>
                            </span>
                            <strong class="tf-dwr-priority-label">{{ $label }}</strong>
                            <span class="tf-dwr-priority-percent">{{ $priorityPercent }}%</span>
                            <div class="tf-dwr-bar" role="progressbar" aria-label="{{ $label }} priority subtasks" aria-valuenow="{{ $priorityPercent }}" aria-valuemin="0" aria-valuemax="100">
                                <span style="width: {{ $priorityPercent }}%"></span>
                            </div>
                            <strong>{{ number_format($priorityCount) }}</strong>
                        </div>
                    </div>
                @endforeach
            </article>

            <article class="tf-dwr-panel tf-dwr-breakdown">
                <div class="tf-dwr-panel__heading">
                    <div><h2>Subtask Status</h2><p>See how work is progressing across the selected filters.</p></div>
                    <span>{{ number_format($metrics['total_subtasks']) }} total</span>
                </div>
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
            </article>
        </section>

        {{-- Subtasks by Main Task --}}
        <section class="tf-dwr-panel">
            <div class="tf-dwr-panel__heading">
                <div>
                    <h2>Subtasks by Main Task</h2>
                    <p>Subtask status by main task for <strong>{{ $summaryDate }}</strong>.</p>
                </div>
                <span>{{ number_format($mainTaskSummaries->count()) }} {{ \Illuminate\Support\Pluralizer::plural('main task', $mainTaskSummaries->count()) }}</span>
            </div>
            <div class="tf-dwr-main-task-summaries">
                @forelse ($mainTaskSummaries as $taskSummary)
                    @php
                        $summaryPriority = $taskSummary->task_priority ?? 'low';
                        $summaryTaskStatus = $taskSummary->task_status ?? 'unassigned';
                        $summaryTotal = (int) $taskSummary->total_subtasks;
                    @endphp
                    <article class="tf-dwr-main-task-summary">
                        <div class="tf-dwr-main-task-summary__content">
                            <header class="tf-dwr-main-task-summary__header">
                                <span class="tf-dwr-main-task-summary__order" aria-label="Task {{ $loop->iteration }}">#{{ $loop->iteration }}</span>
                                <span class="tf-dwr-main-task-summary__subtask-count">
                                    <strong>{{ number_format($summaryTotal) }}</strong>
                                    <span>{{ \Illuminate\Support\Pluralizer::plural('subtask', $summaryTotal) }}</span>
                                </span>
                                <a href="{{ route('tasks.show', ['task' => $taskSummary->task_id]) }}">
                                    <span class="tf-dwr-code">{{ $taskSummary->task_code }}</span>
                                    <strong>{{ $taskSummary->task_title }}</strong>
                                </a>
                                <span class="tf-dwr-task-status tf-dwr-task-status--{{ $summaryTaskStatus }}">
                                    {{ \App\Models\Task::STATUS_LABELS[$summaryTaskStatus] ?? ucwords(str_replace('_', ' ', $summaryTaskStatus)) }}
                                </span>
                                <span class="tf-dwr-priority tf-dwr-priority--{{ $summaryPriority }}">
                                    {{ $priorityLabels[$summaryPriority] ?? 'Low' }} priority
                                </span>
                            </header>

                            <div class="tf-dwr-main-task-summary__statuses">
                                @foreach ($dashboardStatusGroups as $status => $statusGroup)
                                    @php
                                        $statusCount = collect($statusGroup['statuses'])
                                            ->sum(fn (string $subtaskStatus): int => (int) $taskSummary->{'status_'.$subtaskStatus});
                                        $statusPercent = $summaryTotal > 0
                                            ? round(($statusCount / $summaryTotal) * 100)
                                            : 0;
                                    @endphp
                                    <div class="tf-dwr-main-task-summary__status tf-dwr-main-task-summary__status--{{ $status }} {{ $statusCount > 0 ? 'has-subtasks' : '' }}">
                                        <span>{{ $statusGroup['label'] }}</span>
                                        <div
                                            class="tf-dwr-main-task-summary__status-progress"
                                            role="progressbar"
                                            aria-label="{{ $statusGroup['label'] }} subtasks"
                                            aria-valuenow="{{ $statusPercent }}"
                                            aria-valuemin="0"
                                            aria-valuemax="100"
                                        >
                                            <span style="width: {{ $statusPercent }}%"></span>
                                        </div>
                                        <strong>{{ number_format($statusCount) }}</strong>
                                    </div>
                                @endforeach
                            </div>
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
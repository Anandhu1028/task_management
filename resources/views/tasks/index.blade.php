@extends('layouts.app')

@section('title', 'Tasks')

@section('content')
    <header class="tf-page-head">
        <div>
            <h1>Tasks</h1>
            <p>Comprehensive register of tasks across companies, departments, and teams.</p>
        </div>
    </header>

    @if (session('error'))
        <div class="tf-management__notice tf-management__notice--error" role="alert">{{ session('error') }}</div>
    @endif

    {{-- Summary Cards --}}
    <section class="tf-user-summary tf-task-summary" aria-label="Task summary statistics">
        <article class="tf-user-summary__card tf-user-summary__card--total">
            <span class="tf-user-summary__icon"><i class="bi bi-check2-square"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Total Tasks</span>
                <strong>{{ number_format($counts->total ?? 0) }}</strong>
                <small>All authorized tasks</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-layers"></i></span>
        </article>

        <article class="tf-user-summary__card tf-user-summary__card--active">
            <span class="tf-user-summary__icon"><i class="bi bi-arrow-repeat"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">In Progress</span>
                <strong>{{ number_format($counts->in_progress_count ?? 0) }}</strong>
                <small>Currently active</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-hourglass-split"></i></span>
        </article>

        <article class="tf-user-summary__card tf-user-summary__card--rate">
            <span class="tf-user-summary__icon"><i class="bi bi-check2-circle"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Completed</span>
                <strong>{{ number_format($counts->completed_count ?? 0) }}</strong>
                <small>Successfully delivered</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-trophy"></i></span>
        </article>

        <article class="tf-user-summary__card tf-user-summary__card--inactive">
            <span class="tf-user-summary__icon"><i class="bi bi-exclamation-octagon"></i></span>
            <div class="tf-user-summary__content">
                <span class="tf-user-summary__label">Overdue</span>
                <strong>{{ number_format($counts->overdue_count ?? 0) }}</strong>
                <small>Requires immediate action</small>
            </div>
            <span class="tf-user-summary__trend"><i class="bi bi-alarm"></i></span>
        </article>
    </section>

    {{-- Main Card --}}
    <section class="tf-card tf-user-management tf-task-management">
        @if (auth()->user()->can('tasks.view') || auth()->user()->can('tasks.create'))
            <div class="tf-user-management__top tf-task-management__top">
                <div class="tf-user-page-actions tf-user-management__actions tf-task-management__actions">
                    @can('tasks.view')
                        <a href="{{ route('tasks.export', request()->except(['page'])) }}" class="tf-btn tf-btn--ghost tf-user-action-btn tf-user-action-btn--export">
                            <i class="bi bi-download"></i>
                            Export
                        </a>
                    @endcan

                    @can('tasks.create')
                        <button type="button" class="tf-btn tf-btn--primary tf-user-action-btn tf-user-action-btn--add" data-task-create-trigger>
                            <i class="bi bi-plus-lg"></i>
                            Create Task
                        </button>
                    @endcan
                </div>
            </div>
        @endif

        <div class="tf-user-toolbar">
            <form id="taskFilterForm" method="GET" action="{{ route('tasks.index') }}" class="tf-user-toolbar__search-form tf-task-toolbar__search-form" data-task-filter-form>
                <input type="hidden" name="task_type" value="{{ request('task_type') }}">
                <input type="hidden" name="per_page" value="{{ request('per_page') }}">
                <input type="hidden" name="assigned_to" value="{{ request('assigned_to') }}">
                <input type="hidden" name="sort" value="{{ $filters['sort'] ?? 'deadline_priority' }}" data-kanban-sort-value>
                <label class="tf-user-search" for="taskSearch">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="taskSearch" type="search" name="search" value="{{ request('search') }}" placeholder="Search by title, code, employee or department" autocomplete="off">
                </label>
                <div class="tf-task-toolbar-filters">
                    <label class="tf-user-toolbar__select" for="taskCompanyFilter">
                        <select id="taskCompanyFilter" name="company_id" aria-label="Filter by company" data-task-company-filter data-task-auto-submit>
                            <option value="">All companies</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}" @selected(request('company_id') == $company->id)>{{ $company->name }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="taskDepartmentFilter">
                        <select id="taskDepartmentFilter" name="department_id" aria-label="Filter by department" data-task-department-filter data-task-auto-submit>
                            <option value="">All departments</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" data-company-id="{{ $department->company_id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="taskStatusFilter">
                        <select id="taskStatusFilter" name="status" aria-label="Filter by task status" data-task-auto-submit>
                            <option value="">All statuses</option>
                            @foreach (\App\Models\Task::STATUSES as $statusValue)
                                <option value="{{ $statusValue }}" @selected(request('status') === $statusValue)>{{ \App\Models\Task::STATUS_LABELS[$statusValue] }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="taskConditionFilter">
                        <select id="taskConditionFilter" name="condition" aria-label="Filter by deadline condition" data-task-auto-submit>
                            <option value="">All deadline conditions</option>
                            @foreach (\App\Models\Task::DEADLINE_CONDITIONS as $condition)
                                <option value="{{ $condition }}" @selected(request('condition') === $condition)>{{ \App\Models\Task::DEADLINE_CONDITION_LABELS[$condition] }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                </div>
            </form>
            <div class="tf-user-toolbar__end">
                @if (request()->hasAny(['search', 'company_id', 'department_id', 'assigned_to', 'status', 'condition', 'priority', 'due_date', 'task_type']) || (request()->filled('sort') && request('sort') !== 'deadline_priority'))
                    <a href="{{ route('tasks.index') }}" class="tf-text-link tf-user-toolbar__reset">Reset</a>
                @endif
                <div class="tf-task-sort-filter" data-task-sort-filter>
                    <button
                        type="button"
                        class="tf-task-sort-filter__trigger"
                        aria-label="Open task sorting and filters"
                        aria-expanded="false"
                        aria-controls="taskSortFilterPopover"
                        data-task-sort-filter-toggle
                    >
                        <i class="bi bi-sliders" aria-hidden="true"></i>
                        <span>Sort by</span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                    <section
                        id="taskSortFilterPopover"
                        class="tf-task-sort-filter__popover"
                        role="dialog"
                        aria-labelledby="taskSortFilterTitle"
                        hidden
                        data-task-sort-filter-popover
                    >
                        <h2 id="taskSortFilterTitle">Sort and filter tasks</h2>
                        <label class="tf-task-sort-filter__field" for="taskKanbanSort">
                            <span>Sort tasks by</span>
                            <select id="taskKanbanSort" aria-label="Sort tasks by" data-task-sort-filter-control>
                                <option value="deadline_priority" @selected(($filters['sort'] ?? 'deadline_priority') === 'deadline_priority')>Deadline → Priority</option>
                                <option value="priority_deadline" @selected(($filters['sort'] ?? '') === 'priority_deadline')>Priority → Deadline</option>
                                <option value="nearest_deadline" @selected(($filters['sort'] ?? '') === 'nearest_deadline')>Nearest Deadline</option>
                                <option value="oldest_deadline" @selected(($filters['sort'] ?? '') === 'oldest_deadline')>Oldest Deadline</option>
                                <option value="recently_created" @selected(($filters['sort'] ?? '') === 'recently_created')>Recently Created</option>
                                <option value="recently_updated" @selected(($filters['sort'] ?? '') === 'recently_updated')>Recently Updated</option>
                            </select>
                        </label>
                        <label class="tf-task-sort-filter__field" for="taskDueDateFilter">
                            <span>Deadline date</span>
                            <input
                                id="taskDueDateFilter"
                                type="date"
                                name="due_date"
                                form="taskFilterForm"
                                value="{{ $filters['due_date'] ?? '' }}"
                                aria-label="Filter by deadline date"
                                data-task-sort-filter-control
                            >
                        </label>
                        <label class="tf-task-sort-filter__field" for="taskPriorityFilter">
                            <span>Priority</span>
                            <select
                                id="taskPriorityFilter"
                                name="priority"
                                form="taskFilterForm"
                                aria-label="Filter by priority"
                                data-task-sort-filter-control
                            >
                                <option value="">All priorities</option>
                                <option value="critical" @selected(request('priority') === 'critical')>Critical</option>
                                <option value="high" @selected(request('priority') === 'high')>High</option>
                                <option value="medium" @selected(request('priority') === 'medium')>Medium</option>
                                <option value="low" @selected(request('priority') === 'low')>Low</option>
                            </select>
                        </label>
                        <div class="tf-task-sort-filter__actions">
                            <button type="button" class="tf-btn tf-btn--ghost" data-task-sort-filter-clear>Clear</button>
                            <button type="button" class="tf-btn tf-btn--primary" data-task-sort-filter-apply>Apply</button>
                        </div>
                    </section>
                </div>
                {{-- View toggle --}}
                <div class="tf-view-toggle" role="group" aria-label="View mode">
                    <button type="button" id="taskViewList" class="tf-view-toggle__btn is-active" data-view-toggle="list" aria-pressed="true">
                        <i class="bi bi-list-task" aria-hidden="true"></i>

                    </button>
                    <button type="button" id="taskViewKanban" class="tf-view-toggle__btn" data-view-toggle="kanban" aria-pressed="false">
                        <i class="bi bi-kanban" aria-hidden="true"></i>

                    </button>
                </div>
            </div>
        </div>

        {{-- List view --}}
        <div id="taskListView" class="tf-table-wrap tf-user-table-wrap">
            <table class="tf-table tf-user-table tf-task-table">
                <thead>
                    <tr>
                        <th class="tf-task-table__check">
                            <input class="tf-check" type="checkbox" aria-label="Select all tasks on this page" data-task-select-all>
                        </th>
                        <th>Task</th>
                        <th>Assignees</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Deadline</th>
                        <th>Progress</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tasks as $task)
                        @php
                            $isOverdue = $task->isOverdue();
                            $status = $task->display_status;
                            $deadlineCondition = $task->deadline_condition;
                            $priority = in_array($task->priority, ['critical', 'high', 'medium', 'low'], true)
                                ? $task->priority
                                : 'medium';
                            $taskAssignees = $task->activeAssignees;
                            if ($taskAssignees->isEmpty() && $task->assignee) {
                                $taskAssignees = collect([$task->assignee]);
                            }
                            $assigneeNames = $taskAssignees->pluck('name')->implode(', ');
                        @endphp
                        <tr class="tf-task-table__row">
                            <td class="tf-task-table__check">
                                <input
                                    class="tf-check"
                                    type="checkbox"
                                    value="{{ $task->id }}"
                                    aria-label="Select {{ $task->title }}"
                                    data-task-checkbox
                                >
                            </td>
                            {{-- Task Title & Code --}}
                            <td>
                                <div class="tf-task-identity">
                                    <div class="tf-task-identity__title">
                                        <a href="{{ route('tasks.show', $task) }}" class="tf-task-identity__link">
                                            {{ $task->title }}
                                        </a>
                                    </div>
                                    <small class="tf-task-identity__company">
                                        {{ $task->company?->name ?? '—' }}
                                        <span aria-hidden="true">·</span>
                                        {{ $task->department?->name ?? '—' }}
                                        @if ($task->pocs->isNotEmpty())
                                            <span aria-hidden="true">·</span>
                                            <span class="tf-task-poc" title="Department POCs: {{ $task->pocs->pluck('name')->implode(', ') }}">
                                                <i class="bi bi-people-fill" aria-hidden="true"></i>{{ $task->pocs->count() }} POC{{ $task->pocs->count() > 1 ? 's' : '' }}
                                            </span>
                                        @endif
                                    </small>
                                </div>
                            </td>

                            {{-- Assignees --}}
                            <td>
                                @if ($taskAssignees->isNotEmpty())
                                    <div class="tf-task-assignee">
                                        <span class="tf-user-row__avatar">
                                            {{ strtoupper(substr($taskAssignees->first()->name, 0, 2)) }}
                                        </span>
                                        <div class="tf-task-assignee__identity">
                                            <span title="{{ $assigneeNames }}">{{ $taskAssignees->first()->name }}</span>
                                            <small>
                                                @if ($taskAssignees->count() > 1)
                                                    + {{ $taskAssignees->count() - 1 }} more assignee{{ $taskAssignees->count() > 2 ? 's' : '' }}
                                                @else
                                                    {{ $taskAssignees->first()->designation ?? 'Employee' }}
                                                @endif
                                            </small>
                                        </div>
                                    </div>
                                @else
                                    <span class="tf-management__secondary">Unassigned</span>
                                @endif
                            </td>

                            {{-- Priority --}}
                            <td>
                                <span class="tf-task-priority tf-task-priority--{{ $priority }}"
                                      aria-label="Priority: {{ ucfirst($priority) }}">
                                    <span class="tf-task-priority__dot" aria-hidden="true"></span>
                                    {{ ucfirst($priority) }}
                                </span>
                            </td>

                            {{-- Status --}}
                            <td>
                                <div class="d-flex flex-column align-items-start gap-1">
                                    <span class="tf-task-status tf-task-status--{{ $status }}" @if ($status === 'on_hold') title="{{ $task->hold_reason }}" @endif>
                                        {{ \App\Models\Task::STATUS_LABELS[$status] ?? ucwords(str_replace('_', ' ', $status)) }}
                                    </span>
                                    @if ($deadlineCondition !== 'normal')
                                        <span class="tf-task-status tf-task-status--{{ $deadlineCondition }}" @if ($deadlineCondition === 'at_risk' && $task->risk_reason) title="{{ $task->risk_reason }}" @endif>
                                            Deadline: {{ \App\Models\Task::DEADLINE_CONDITION_LABELS[$deadlineCondition] }}
                                        </span>
                                    @endif
                                </div>
                            </td>

                            {{-- Deadline --}}
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="tf-task-deadline {{ $isOverdue ? 'is-overdue' : '' }}">
                                        {{ $task->original_deadline?->format('M d, Y') ?? '—' }}
                                    </span>
                                    @if ($task->due_date && $task->due_date->ne($task->original_deadline))
                                        <small class="tf-task-deadline__revised">
                                            Revised: {{ $task->due_date->format('M d, Y') }}
                                        </small>
                                    @endif
                                </div>
                            </td>

                            {{-- Progress Percentage & Bar --}}
                            <td>
                                <div class="tf-task-progress">
                                    <div class="tf-task-progress__label">
                                        <span>{{ (int) $task->progress_percentage }}%</span>
                                    </div>
                                    <div class="progress tf-task-progress__track">
                                        <div class="progress-bar tf-task-progress__fill {{ $status === 'completed' ? 'tf-task-progress__fill--completed' : ($deadlineCondition === 'at_risk' ? 'tf-task-progress__fill--at-risk' : ($isOverdue ? 'tf-task-progress__fill--overdue' : 'tf-task-progress__fill--default')) }}"
                                             role="progressbar"
                                             style="width: {{ $task->progress_percentage }}%;"
                                             aria-valuenow="{{ $task->progress_percentage }}"
                                             aria-valuemin="0"
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                </div>
                            </td>

                            {{-- Actions Dropdown --}}
                            <td class="text-center">
                                <div class="dropdown">
                                    <button class="tf-icon-btn tf-icon-btn--sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Task actions" data-task-action-toggle>
                                        <i class="bi bi-three-dots"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end tf-menu shadow-sm">
                                        <li>
                                            <a class="dropdown-item py-2" href="{{ route('tasks.show', $task) }}">
                                                <i class="bi bi-eye me-2 text-primary"></i> View Details
                                            </a>
                                        </li>
                                        @can('requestDeadlineExtension', $task)
                                            <li>
                                                <button
                                                    type="button"
                                                    class="dropdown-item py-2"
                                                    data-deadline-extension-trigger
                                                    data-task-id="{{ $task->id }}"
                                                    data-task-title="{{ $task->title }}"
                                                    data-current-deadline="{{ ($task->due_date ?? $task->original_deadline)?->format('Y-m-d') ?? '' }}"
                                                    data-action="{{ route('tasks.deadline-extension-requests.store', $task) }}"
                                                >
                                                    <i class="bi bi-calendar2-plus me-2 text-primary"></i> Request Deadline Extension
                                                </button>
                                            </li>
                                        @endcan
                                        @can('tasks.update')
                                            <li>
                                                <a
                                                    class="dropdown-item py-2"
                                                    href="{{ route('tasks.index', array_merge(request()->all(), ['edit' => $task->id])) }}"
                                                    data-task-edit
                                                    data-task="{{ json_encode([
                                                        'id' => $task->id,
                                                        'title' => $task->title,
                                                        'company_id' => $task->company_id,
                                                        'department_id' => $task->department_id,
                                                        'poc_ids' => $task->pocs->modelKeys(),
                                                        'assigned_to' => $taskAssignees->pluck('id')->all(),
                                                        'priority' => $task->priority,
                                                        'task_type' => $task->task_type,
                                                        'original_deadline' => $task->original_deadline?->format('Y-m-d'),
                                                        'due_date' => $task->due_date?->format('Y-m-d'),
                                                        'estimated_hours' => $task->estimated_hours,
                                                        'expected_output' => $task->expected_output,
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                                                >
                                                    <i class="bi bi-pencil me-2 text-secondary"></i> Edit Task
                                                </a>
                                            </li>
                                            <li>
                                                <button
                                                    type="button"
                                                    class="dropdown-item py-2"
                                                    data-reassign-trigger
                                                    data-task-id="{{ $task->id }}"
                                                    data-task-code="{{ $task->task_code }}"
                                                    data-task-title="{{ $task->title }}"
                                                    data-task-company-id="{{ $task->company_id }}"
                                                    data-task-company-name="{{ $task->company?->name }}"
                                                    data-task-department-id="{{ $task->department_id }}"
                                                    data-task-department-name="{{ $task->department?->name }}"
                                                    data-task-assignee-ids="{{ json_encode($taskAssignees->pluck('id')->all()) }}"
                                                    data-task-assignee-name="{{ $assigneeNames ?: 'Unassigned' }}"
                                                    data-reassign-action="{{ route('tasks.reassign', $task) }}"
                                                >
                                                    <i class="bi bi-arrow-repeat me-2 text-info"></i> Re-assign
                                                </button>
                                            </li>
                                        @endcan
                                        @can('tasks.delete')
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form action="{{ route('tasks.destroy', $task) }}" method="POST" onsubmit="return confirm('Delete task {{ $task->task_code }}: {{ $task->title }}?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="dropdown-item py-2 text-danger">
                                                        <i class="bi bi-trash3 me-2"></i> Delete Task
                                                    </button>
                                                </form>
                                            </li>
                                        @endcan
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="tf-task-table__empty-cell">
                                @include('components.empty-state', [
                                    'icon' => 'bi-check2-square',
                                    'title' => 'No tasks found',
                                    'message' => 'No tasks matched your search or filters. Create a new task or reset filters.',
                                    'action' => 'Create Task',
                                    'actionAttributes' => ['data-task-create-trigger' => true],
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>{{-- /.tf-table-wrap --}}

        <footer id="taskListFooter" class="tf-management__table-footer">
            <span>
                Showing {{ $tasks->firstItem() ?? 0 }}–{{ $tasks->lastItem() ?? 0 }} of {{ $tasks->total() }} tasks
            </span>
            {{ $tasks->links('pagination::bootstrap-5') }}
        </footer>

        {{-- Kanban view --}}
        @php
            $kanbanStatuses = \App\Models\Task::STATUSES;
            $statusLabels   = \App\Models\Task::STATUS_LABELS;
        @endphp
        <div id="taskKanbanView" class="tf-kanban" role="region" aria-label="Kanban board" data-today="{{ today()->toDateString() }}">
            @foreach ($kanbanStatuses as $statusKey)
                @php
                    $colTasks = $kanbanTasks->get($statusKey, collect());
                @endphp
                <div class="tf-kanban-col tf-kanban-col--{{ $statusKey }}">
                    <div class="tf-kanban-col__header">
                        <div class="tf-kanban-col__header-left">
                            <span class="tf-kanban-col__dot" aria-hidden="true"></span>
                            <span class="tf-kanban-col__title">{{ $statusLabels[$statusKey] }}</span>
                        </div>
                        <span class="tf-kanban-col__count" aria-label="{{ $colTasks->count() }} tasks">
                            {{ $colTasks->count() }}
                        </span>
                    </div>
                    <div class="tf-kanban-col__body">
                        @forelse ($colTasks as $kTask)
                            @php
                                $kPriority  = in_array($kTask->priority, ['critical', 'high', 'medium', 'low'], true) ? $kTask->priority : 'medium';
                                $kOverdue   = $kTask->isOverdue();
                                $kDeadline  = $kTask->due_date ?? $kTask->original_deadline;
                                $kDeadlineState = $kOverdue
                                    ? 'overdue'
                                    : ($kDeadline?->toDateString() === today()->toDateString()
                                        && ! in_array($kTask->status, ['completed', 'cancelled'], true)
                                        ? 'today'
                                        : ($kDeadline ? 'upcoming' : 'none'));
                                $kAssignees = $kTask->activeAssignees;
                                if ($kAssignees->isEmpty() && $kTask->assignee) {
                                    $kAssignees = collect([$kTask->assignee]);
                                }
                                $kFirst     = $kAssignees->first();
                                $kProgress  = (int) $kTask->progress_percentage;
                            @endphp
                            <a
                                href="{{ route('tasks.show', $kTask) }}"
                                class="tf-kanban-card"
                                data-task-id="{{ $kTask->id }}"
                                data-deadline="{{ $kDeadline?->format('Y-m-d') ?? '' }}"
                                data-priority="{{ $kTask->priority ?? '' }}"
                                data-created-at="{{ $kTask->created_at?->getTimestamp() ?? 0 }}"
                                data-updated-at="{{ $kTask->updated_at?->getTimestamp() ?? 0 }}"
                            >
                                {{-- Code + Priority --}}
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <span class="tf-kanban-card__code">{{ $kTask->task_code }}</span>
                                    <span class="tf-task-priority tf-task-priority--{{ $kPriority }}" style="font-size:10.5px;padding:2px 7px;">
                                        <span class="tf-task-priority__dot" aria-hidden="true"></span>
                                        {{ ucfirst($kPriority) }}
                                    </span>
                                </div>

                                {{-- Title --}}
                                <span class="tf-kanban-card__title">{{ $kTask->title }}</span>

                                {{-- Department chip --}}
                                @if ($kTask->department)
                                    <span class="tf-kanban-card__meta">
                                        <i class="bi bi-building" style="font-size:10px;color:#94a3b8;"></i>
                                        <span style="font-size:11px;color:#64748b;">{{ $kTask->department->name }}</span>
                                    </span>
                                @endif

                                {{-- Progress bar --}}
                                <div class="tf-kanban-card__progress" aria-label="Progress: {{ $kProgress }}%" title="Progress: {{ $kProgress }}%">
                                    <div class="tf-kanban-card__progress-bar
                                        {{ $kProgress === 100 ? 'tf-kanban-card__progress-bar--success' : ($kOverdue ? 'tf-kanban-card__progress-bar--danger' : '') }}"
                                        style="width:{{ $kProgress }}%"
                                    ></div>
                                </div>

                                {{-- Footer: assignee + deadline --}}
                                <div class="tf-kanban-card__footer">
                                    @if ($kFirst)
                                        <div class="tf-kanban-card__assignee">
                                            <span class="tf-kanban-card__avatar" aria-hidden="true">
                                                {{ strtoupper(substr($kFirst->name, 0, 2)) }}
                                            </span>
                                            <span class="tf-kanban-card__assignee-name" title="{{ $kAssignees->pluck('name')->implode(', ') }}">
                                                {{ $kFirst->name }}
                                                @if ($kAssignees->count() > 1)
                                                    <em style="font-style:normal;color:#94a3b8;">+{{ $kAssignees->count() - 1 }}</em>
                                                @endif
                                            </span>
                                        </div>
                                    @else
                                        <span class="tf-kanban-card__assignee-name" style="color:#94a3b8;">Unassigned</span>
                                    @endif
                                    <span
                                        class="tf-kanban-card__deadline tf-kanban-card__deadline--{{ $kDeadlineState }} {{ $kOverdue ? 'is-overdue' : '' }}"
                                        @if ($kDeadline) aria-label="{{ $kDeadlineState === 'overdue' ? 'Overdue deadline: ' : 'Deadline: ' }}{{ $kDeadline->format('F j, Y') }}" @else aria-label="No deadline" @endif
                                    >
                                        @if ($kDeadlineState === 'overdue')
                                            <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                                            <span>Overdue</span>
                                        @elseif ($kDeadlineState === 'today')
                                            <i class="bi bi-calendar-day" aria-hidden="true"></i>
                                            <span>Today</span>
                                        @else
                                            <i class="bi bi-calendar3" aria-hidden="true"></i>
                                        @endif
                                        @if ($kDeadline)
                                            <time datetime="{{ $kDeadline->format('Y-m-d') }}">{{ $kDeadline->format('M d') }}</time>
                                        @else
                                            <span>No deadline</span>
                                        @endif
                                    </span>
                                </div>
                            </a>
                        @empty
                            <div class="tf-kanban-col__empty">
                                <i class="bi bi-inbox" aria-hidden="true"></i>
                                <span>No tasks</span>
                            </div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    @include('tasks._reassign-modal')
    <div class="modal fade" id="deadlineExtensionRequestModal" tabindex="-1" aria-labelledby="deadlineExtensionRequestTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content tf-modal">
                <form
                    method="POST"
                    id="deadlineExtensionRequestForm"
                    data-action-template="{{ route('tasks.deadline-extension-requests.store', ['task' => 'TASK_ID_PLACEHOLDER']) }}"
                >
                    @csrf
                    <input type="hidden" name="task_id" id="deadlineExtensionTaskId" value="{{ old('task_id') }}">
                    <input type="hidden" name="task_name" id="deadlineExtensionTaskNameValue" value="{{ old('task_name') }}">
                    <input type="hidden" name="current_deadline" id="deadlineExtensionCurrentDeadlineValue" value="{{ old('current_deadline') }}">
                    <div class="modal-header">
                        <h2 class="modal-title" id="deadlineExtensionRequestTitle">Request deadline extension</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body d-grid gap-3">
                        @if ($errors->has('requested_deadline') || $errors->has('reason') || $errors->has('deadline_extension'))
                            <div class="alert alert-danger mb-0" role="alert">
                                {{ $errors->first('requested_deadline') ?: $errors->first('reason') ?: $errors->first('deadline_extension') }}
                            </div>
                        @endif
                        <label class="tf-label">
                            Task name
                            <input type="text" class="form-control tf-input" id="deadlineExtensionTaskName" value="{{ old('task_name') }}" readonly>
                        </label>
                        <label class="tf-label">
                            Current deadline
                            <input type="text" class="form-control tf-input" id="deadlineExtensionCurrentDeadline" value="{{ old('current_deadline') ?: 'No deadline set' }}" readonly>
                        </label>
                        <label class="tf-label">
                            Requested deadline <span>*</span>
                            <input
                                type="date"
                                class="form-control tf-input"
                                name="requested_deadline"
                                id="deadlineExtensionRequestedDeadline"
                                value="{{ old('requested_deadline') }}"
                                required
                            >
                        </label>
                        <label class="tf-label">
                            Reason for extension <span>*</span>
                            <textarea class="form-control tf-input" name="reason" rows="4" maxlength="5000" required>{{ old('reason') }}</textarea>
                        </label>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="tf-btn tf-btn--ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="tf-btn tf-btn--primary">Submit request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            (() => {
                const modalElement = document.getElementById('deadlineExtensionRequestModal');
                const form = document.getElementById('deadlineExtensionRequestForm');
                const taskId = document.getElementById('deadlineExtensionTaskId');
                const taskNameValue = document.getElementById('deadlineExtensionTaskNameValue');
                const currentDeadlineValue = document.getElementById('deadlineExtensionCurrentDeadlineValue');
                const taskName = document.getElementById('deadlineExtensionTaskName');
                const currentDeadline = document.getElementById('deadlineExtensionCurrentDeadline');
                const requestedDeadline = document.getElementById('deadlineExtensionRequestedDeadline');
                const modal = window.bootstrap?.Modal.getOrCreateInstance(modalElement);

                const nextDate = (date) => {
                    const [year, month, day] = date.split('-').map(Number);
                    const next = new Date(year, month - 1, day + 1);
                    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`;
                };

                const openRequestModal = (values) => {
                    taskId.value = values.id;
                    taskNameValue.value = values.title;
                    currentDeadlineValue.value = values.deadline;
                    taskName.value = values.title;
                    currentDeadline.value = values.deadline || 'No deadline set';
                    form.action = values.action;
                    requestedDeadline.min = nextDate(values.deadline || '{{ today()->toDateString() }}');
                    modal?.show();
                };

                document.querySelectorAll('[data-deadline-extension-trigger]').forEach((button) => {
                    button.addEventListener('click', () => openRequestModal({
                        id: button.dataset.taskId,
                        title: button.dataset.taskTitle,
                        deadline: button.dataset.currentDeadline,
                        action: button.dataset.action,
                    }));
                });

                if (taskId.value) {
                    const action = form.dataset.actionTemplate.replace('TASK_ID_PLACEHOLDER', encodeURIComponent(taskId.value));
                    openRequestModal({
                        id: taskId.value,
                        title: taskNameValue.value,
                        deadline: currentDeadlineValue.value,
                        action,
                    });
                }
            })();
        </script>
        <script>
            /* ── View toggle (List / Kanban) ─────────────────────────── */
            const STORAGE_KEY = 'tf_tasks_view';
            const listView    = document.getElementById('taskListView');
            const listFooter  = document.getElementById('taskListFooter');
            const kanbanView  = document.getElementById('taskKanbanView');
            const kanbanSort = document.getElementById('taskKanbanSort');
            const kanbanSortValue = document.querySelector('[data-kanban-sort-value]');
            const viewBtns    = document.querySelectorAll('[data-view-toggle]');

            const applyView = (mode) => {
                const isKanban = mode === 'kanban';
                listView.hidden   = isKanban;
                listFooter.hidden = isKanban;
                kanbanView.classList.toggle('is-active', isKanban);
                viewBtns.forEach((btn) => {
                    const active = btn.dataset.viewToggle === mode;
                    btn.classList.toggle('is-active', active);
                    btn.setAttribute('aria-pressed', String(active));
                });
            };

            applyView(localStorage.getItem(STORAGE_KEY) ?? 'list');

            viewBtns.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const mode = btn.dataset.viewToggle;
                    localStorage.setItem(STORAGE_KEY, mode);
                    applyView(mode);
                });
            });

            const priorityOrder = { critical: 0, high: 1, medium: 2, low: 3 };
            const getDeadline = (card) => {
                const deadline = card.dataset.deadline ?? '';
                if (!/^\d{4}-\d{2}-\d{2}$/.test(deadline)) {
                    return null;
                }

                const timestamp = Date.parse(`${deadline}T00:00:00Z`);
                return Number.isFinite(timestamp) && new Date(timestamp).toISOString().slice(0, 10) === deadline
                    ? deadline
                    : null;
            };
            const compareStable = (left, right) => {
                const createdDifference = Number(left.dataset.createdAt || 0) - Number(right.dataset.createdAt || 0);
                if (createdDifference !== 0) {
                    return createdDifference;
                }

                return Number(left.dataset.taskId || 0) - Number(right.dataset.taskId || 0);
            };
            const compareDeadlines = (left, right) => {
                const leftDeadline = getDeadline(left);
                const rightDeadline = getDeadline(right);
                if (leftDeadline === null || rightDeadline === null) {
                    return leftDeadline === rightDeadline ? 0 : (leftDeadline === null ? 1 : -1);
                }

                return leftDeadline.localeCompare(rightDeadline);
            };
            const compareCards = (mode, left, right) => {
                const leftPriority = priorityOrder[left.dataset.priority] ?? 4;
                const rightPriority = priorityOrder[right.dataset.priority] ?? 4;
                let comparison = 0;

                if (mode === 'recently_created') {
                    comparison = Number(right.dataset.createdAt || 0) - Number(left.dataset.createdAt || 0);
                    return comparison || (Number(right.dataset.taskId || 0) - Number(left.dataset.taskId || 0));
                }

                if (mode === 'recently_updated') {
                    comparison = Number(right.dataset.updatedAt || 0) - Number(left.dataset.updatedAt || 0);
                    return comparison || (Number(right.dataset.taskId || 0) - Number(left.dataset.taskId || 0));
                }

                if (mode === 'priority_deadline') {
                    comparison = leftPriority - rightPriority;
                    if (comparison !== 0) {
                        return comparison;
                    }
                }

                if (mode === 'nearest_deadline') {
                    const today = kanbanView.dataset.today;
                    const leftDeadline = getDeadline(left);
                    const rightDeadline = getDeadline(right);
                    const getDistanceGroup = (deadline) => deadline === null ? 2 : (deadline < today ? 1 : 0);
                    comparison = getDistanceGroup(leftDeadline) - getDistanceGroup(rightDeadline);
                    if (comparison === 0) {
                        comparison = compareDeadlines(left, right);
                    }
                } else {
                    comparison = compareDeadlines(left, right);
                }

                if (comparison !== 0) {
                    return comparison;
                }

                if (mode === 'deadline_priority') {
                    comparison = leftPriority - rightPriority;
                    if (comparison !== 0) {
                        return comparison;
                    }
                }

                return compareStable(left, right);
            };
            const sortKanbanBoard = (mode = kanbanSort?.value || 'deadline_priority') => {
                kanbanView.querySelectorAll('.tf-kanban-col').forEach((column) => {
                    const body = column.querySelector('.tf-kanban-col__body');
                    const cards = [...body.children].filter((child) => child.matches('.tf-kanban-card'));
                    const orderedCards = [...cards].sort((left, right) => compareCards(mode, left, right));

                    if (cards.some((card, index) => card !== orderedCards[index])) {
                        const fragment = document.createDocumentFragment();
                        orderedCards.forEach((card) => fragment.append(card));
                        body.append(fragment);
                    }

                    const count = orderedCards.length;
                    const countElement = column.querySelector('.tf-kanban-col__count');
                    const emptyState = body.querySelector('.tf-kanban-col__empty');
                    if (countElement) {
                        if (countElement.textContent !== String(count)) {
                            countElement.textContent = String(count);
                        }
                        if (countElement.getAttribute('aria-label') !== `${count} tasks`) {
                            countElement.setAttribute('aria-label', `${count} tasks`);
                        }
                    }
                    if (emptyState && emptyState.hidden !== (count > 0)) {
                        emptyState.hidden = count > 0;
                    }
                });
            };

            sortKanbanBoard();
            let kanbanSortScheduled = false;
            const kanbanObserver = new MutationObserver(() => {
                if (kanbanSortScheduled) {
                    return;
                }

                kanbanSortScheduled = true;
                window.requestAnimationFrame(() => {
                    kanbanSortScheduled = false;
                    sortKanbanBoard();
                });
            });
            kanbanObserver.observe(kanbanView, {
                attributes: true,
                attributeFilter: ['data-deadline', 'data-priority', 'data-created-at', 'data-updated-at'],
                childList: true,
                subtree: true,
            });
            /* ─────────────────────────────────────────────────────────── */

            const taskFilterForm = document.querySelector('[data-task-filter-form]');
            const taskCompanyFilter = taskFilterForm?.querySelector('[data-task-company-filter]');
            const taskDepartmentFilter = taskFilterForm?.querySelector('[data-task-department-filter]');
            const taskToolbarFilters = taskFilterForm?.querySelectorAll('[data-task-auto-submit]');
            const taskSortFilter = document.querySelector('[data-task-sort-filter]');
            const taskSortFilterToggle = taskSortFilter?.querySelector('[data-task-sort-filter-toggle]');
            const taskSortFilterPopover = taskSortFilter?.querySelector('[data-task-sort-filter-popover]');
            const taskSortFilterApply = taskSortFilter?.querySelector('[data-task-sort-filter-apply]');
            const taskSortFilterClear = taskSortFilter?.querySelector('[data-task-sort-filter-clear]');
            const taskPriorityFilter = document.getElementById('taskPriorityFilter');
            const taskDueDateFilter = document.getElementById('taskDueDateFilter');

            const setTaskSortFilterOpen = (isOpen) => {
                if (!taskSortFilterToggle || !taskSortFilterPopover) {
                    return;
                }

                taskSortFilterPopover.hidden = !isOpen;
                taskSortFilterToggle.setAttribute('aria-expanded', String(isOpen));
                if (isOpen) {
                    kanbanSort?.focus();
                }
            };

            const updateTaskDepartmentOptions = () => {
                if (!taskCompanyFilter || !taskDepartmentFilter) {
                    return;
                }

                const selectedDepartment = taskDepartmentFilter.selectedOptions[0];
                if (taskCompanyFilter.value && selectedDepartment?.dataset.companyId
                    && selectedDepartment.dataset.companyId !== taskCompanyFilter.value) {
                    taskDepartmentFilter.value = '';
                }

                [...taskDepartmentFilter.options].forEach((option) => {
                    option.hidden = Boolean(taskCompanyFilter.value
                        && option.value
                        && option.dataset.companyId !== taskCompanyFilter.value);
                });
            };

            updateTaskDepartmentOptions();
            taskCompanyFilter?.addEventListener('change', updateTaskDepartmentOptions);
            taskToolbarFilters?.forEach((select) => {
                select.addEventListener('change', () => taskFilterForm.requestSubmit());
            });
            taskSortFilterToggle?.addEventListener('click', () => {
                setTaskSortFilterOpen(taskSortFilterPopover.hidden);
            });
            taskSortFilterApply?.addEventListener('click', () => {
                kanbanSortValue.value = kanbanSort.value;
                setTaskSortFilterOpen(false);
                taskFilterForm.requestSubmit();
            });
            taskSortFilterClear?.addEventListener('click', () => {
                kanbanSort.value = 'deadline_priority';
                kanbanSortValue.value = 'deadline_priority';
                taskPriorityFilter.value = '';
                taskDueDateFilter.value = '';
                setTaskSortFilterOpen(false);
                taskFilterForm.requestSubmit();
            });
            document.addEventListener('click', (event) => {
                if (taskSortFilter && !taskSortFilter.contains(event.target)) {
                    setTaskSortFilterOpen(false);
                }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && taskSortFilterPopover && !taskSortFilterPopover.hidden) {
                    setTaskSortFilterOpen(false);
                    taskSortFilterToggle?.focus();
                }
            });

            const syncTaskSelection = () => {
                const selectAllTasks = document.querySelector('[data-task-select-all]');
                const taskCheckboxes = [...document.querySelectorAll('[data-task-checkbox]')];
                if (!selectAllTasks) {
                    return;
                }

                const selectedCount = taskCheckboxes.filter((checkbox) => checkbox.checked).length;
                selectAllTasks.checked = selectedCount > 0 && selectedCount === taskCheckboxes.length;
                selectAllTasks.indeterminate = selectedCount > 0 && selectedCount < taskCheckboxes.length;
            };

            document.addEventListener('change', (event) => {
                if (!(event.target instanceof HTMLInputElement)) {
                    return;
                }

                if (event.target.matches('[data-task-select-all]')) {
                    document.querySelectorAll('[data-task-checkbox]').forEach((checkbox) => {
                        checkbox.checked = event.target.checked;
                    });
                    syncTaskSelection();
                } else if (event.target.matches('[data-task-checkbox]')) {
                    syncTaskSelection();
                }
            });

            const taskActionMenuOrigins = new WeakMap();

            document.addEventListener('shown.bs.dropdown', (event) => {
                const toggle = event.target.closest('[data-task-action-toggle]');
                if (!toggle) {
                    return;
                }

                const dropdown = toggle.closest('.dropdown');
                const menu = dropdown?.querySelector('.dropdown-menu');
                if (!menu) {
                    return;
                }

                const placeholder = document.createComment('Task action menu');
                menu.before(placeholder);
                document.body.append(menu);
                taskActionMenuOrigins.set(toggle, { menu, placeholder });
                bootstrap.Dropdown.getInstance(toggle)?.update();
            });

            document.addEventListener('hidden.bs.dropdown', (event) => {
                const toggle = event.target.closest('[data-task-action-toggle]');
                const origin = toggle && taskActionMenuOrigins.get(toggle);
                if (!origin) {
                    return;
                }

                origin.placeholder.before(origin.menu);
                origin.placeholder.remove();
                taskActionMenuOrigins.delete(toggle);
            });

            const taskUpdateForm = document.getElementById('createTaskForm');
            const taskUpdateModal = document.getElementById('createTaskModal');
            const clearTaskUpdateErrors = () => {
                taskUpdateForm.querySelectorAll('.invalid-feedback, [data-task-error-summary]').forEach((element) => element.remove());
                taskUpdateForm.querySelectorAll('.is-invalid').forEach((element) => element.classList.remove('is-invalid'));
            };
            const showTaskUpdateErrors = (errors) => {
                clearTaskUpdateErrors();
                const messages = Object.values(errors ?? {}).flat().filter((message) => typeof message === 'string');
                if (messages.length === 0) {
                    messages.push('The task could not be updated. Review the task details and try again.');
                }

                const summary = document.createElement('div');
                summary.className = 'tf-create-task__error-summary';
                summary.setAttribute('role', 'alert');
                summary.dataset.taskErrorSummary = '';
                const heading = document.createElement('strong');
                heading.textContent = 'Please review the task details.';
                const list = document.createElement('ul');
                messages.forEach((message) => {
                    const item = document.createElement('li');
                    item.textContent = message;
                    list.append(item);
                });
                summary.append(heading, list);
                taskUpdateForm.querySelector('.modal-body').prepend(summary);
            };
            const showTaskUpdateToast = (message) => {
                let toast = document.getElementById('tfToast');
                if (!toast) {
                    toast = document.createElement('div');
                    toast.className = 'tf-toast tf-toast--success';
                    toast.id = 'tfToast';
                    toast.setAttribute('role', 'status');
                    toast.setAttribute('aria-live', 'polite');
                    const messageElement = document.createElement('span');
                    messageElement.className = 'tf-toast__message';
                    messageElement.dataset.tfToastMessage = '';
                    toast.append(messageElement);
                    document.body.append(toast);
                }

                toast.querySelector('[data-tf-toast-message]').textContent = message;
                toast.hidden = false;
                window.setTimeout(() => {
                    toast.hidden = true;
                }, 2600);
            };
            const refreshTaskViews = async () => {
                const refreshUrl = new URL(window.location.href);
                refreshUrl.searchParams.delete('edit');
                const response = await fetch(refreshUrl, {
                    headers: { Accept: 'text/html' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    throw new Error(`The task was saved, but the board could not be refreshed (HTTP ${response.status}).`);
                }

                const refreshedDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
                const refreshedKanban = refreshedDocument.getElementById('taskKanbanView');
                const refreshedList = refreshedDocument.getElementById('taskListView');
                const refreshedFooter = refreshedDocument.getElementById('taskListFooter');
                const refreshedSummary = refreshedDocument.querySelector('.tf-user-summary');
                if (!refreshedKanban || !refreshedList || !refreshedFooter || !refreshedSummary) {
                    throw new Error('The task was saved, but the refreshed task views were incomplete.');
                }

                kanbanView.dataset.today = refreshedKanban.dataset.today;
                kanbanView.replaceChildren(...[...refreshedKanban.childNodes].map((node) => document.importNode(node, true)));
                listView.replaceChildren(...[...refreshedList.childNodes].map((node) => document.importNode(node, true)));
                listFooter.replaceChildren(...[...refreshedFooter.childNodes].map((node) => document.importNode(node, true)));
                document.querySelector('.tf-user-summary').replaceChildren(
                    ...[...refreshedSummary.childNodes].map((node) => document.importNode(node, true)),
                );
                sortKanbanBoard();
            };

            taskUpdateForm?.addEventListener('submit', async (event) => {
                if (event.defaultPrevented || taskUpdateModal.dataset.formMode !== 'edit'
                    || !kanbanView.classList.contains('is-active')) {
                    return;
                }

                event.preventDefault();
                clearTaskUpdateErrors();
                const submitButton = taskUpdateForm.querySelector('button[type="submit"]');
                submitButton.disabled = true;
                taskUpdateForm.setAttribute('aria-busy', 'true');
                let taskSaved = false;

                try {
                    const response = await fetch(taskUpdateForm.action, {
                        method: 'POST',
                        body: new FormData(taskUpdateForm),
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });
                    const payload = await response.json();
                    if (response.status === 422) {
                        showTaskUpdateErrors(payload.errors);
                        return;
                    }
                    if (!response.ok) {
                        throw new Error(payload.message || `The task could not be updated (HTTP ${response.status}).`);
                    }

                    taskSaved = true;
                    await refreshTaskViews();
                    const url = new URL(window.location.href);
                    url.searchParams.delete('edit');
                    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
                    window.bootstrap.Modal.getOrCreateInstance(taskUpdateModal).hide();
                    showTaskUpdateToast(payload.message || 'Task updated successfully.');
                } catch (error) {
                    console.error(error);
                    showTaskUpdateErrors({
                        task: [taskSaved
                            ? 'The task was saved, but its updated position could not be shown. Refresh the board to see the latest data.'
                            : 'The task could not be updated. Check your connection and try again.'],
                    });
                } finally {
                    submitButton.disabled = false;
                    taskUpdateForm.removeAttribute('aria-busy');
                }
            });
        </script>
    @endpush
@endsection

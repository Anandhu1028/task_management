@extends('layouts.app')

@section('title', 'All Tasks')

@section('content')
    <header class="tf-page-head">
        <div>
            <h1>All Tasks</h1>
            <p>Comprehensive register of tasks across companies, departments, and teams.</p>
        </div>
        <div class="tf-page-head__actions tf-user-page-actions">
            {{-- Export Report Button --}}
            @can('tasks.view')
                <a href="{{ route('tasks.export', request()->except(['page'])) }}" class="tf-btn tf-btn--ghost tf-user-action-btn tf-user-action-btn--export">
                    <i class="bi bi-download"></i>
                    Export Report
                </a>
            @endcan

            {{-- Create Task Button --}}
            @can('tasks.create')
                <button type="button" class="tf-btn tf-btn--primary tf-user-action-btn tf-user-action-btn--add" data-task-create-trigger>
                    <i class="bi bi-plus-lg"></i>
                    Create Task
                </button>
            @endcan
        </div>
    </header>

    @if (session('error'))
        <div class="tf-management__notice tf-management__notice--error" role="alert">{{ session('error') }}</div>
    @endif

    {{-- Summary Cards --}}
    <section class="tf-user-summary" aria-label="Task summary statistics">
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

    {{-- Main Table Card --}}
    <section class="tf-card tf-user-management">
        <div class="tf-user-toolbar">
            <form method="GET" action="{{ route('tasks.index') }}" class="tf-user-toolbar__search-form tf-task-toolbar__search-form" data-task-filter-form>
                <input type="hidden" name="task_type" value="{{ request('task_type') }}">
                <input type="hidden" name="per_page" value="{{ request('per_page') }}">
                <input type="hidden" name="assigned_to" value="{{ request('assigned_to') }}">
                <label class="tf-user-search" for="taskSearch">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="taskSearch" type="search" name="search" value="{{ request('search') }}" placeholder="Search by title, code, employee or department" autocomplete="off">
                </label>
                <div class="tf-task-toolbar-filters">
                    <label class="tf-user-toolbar__select" for="taskCompanyFilter">
                        <select id="taskCompanyFilter" name="company_id" aria-label="Filter by company" data-task-company-filter>
                            <option value="">All companies</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}" @selected(request('company_id') == $company->id)>{{ $company->name }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="taskDepartmentFilter">
                        <select id="taskDepartmentFilter" name="department_id" aria-label="Filter by department" data-task-department-filter>
                            <option value="">All departments</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" data-company-id="{{ $department->company_id }}" @selected(request('department_id') == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="taskPriorityFilter">
                        <select id="taskPriorityFilter" name="priority" aria-label="Filter by priority">
                            <option value="">All priorities</option>
                            <option value="critical" @selected(request('priority') === 'critical')>Critical</option>
                            <option value="high" @selected(request('priority') === 'high')>High</option>
                            <option value="medium" @selected(request('priority') === 'medium')>Medium</option>
                            <option value="low" @selected(request('priority') === 'low')>Low</option>
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="taskStatusFilter">
                        <select id="taskStatusFilter" name="status" aria-label="Filter by task status">
                            <option value="">All statuses</option>
                            @foreach (\App\Models\Task::STATUSES as $statusValue)
                                <option value="{{ $statusValue }}" @selected(request('status') === $statusValue)>{{ \App\Models\Task::STATUS_LABELS[$statusValue] }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                    <label class="tf-user-toolbar__select" for="taskConditionFilter">
                        <select id="taskConditionFilter" name="condition" aria-label="Filter by deadline condition">
                            <option value="">All deadline conditions</option>
                            @foreach (\App\Models\Task::DEADLINE_CONDITIONS as $condition)
                                <option value="{{ $condition }}" @selected(request('condition') === $condition)>{{ \App\Models\Task::DEADLINE_CONDITION_LABELS[$condition] }}</option>
                            @endforeach
                        </select>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </label>
                </div>
            </form>
            @if (request()->hasAny(['search', 'company_id', 'department_id', 'assigned_to', 'status', 'condition', 'priority', 'task_type']))
                <a href="{{ route('tasks.index') }}" class="tf-text-link tf-user-toolbar__reset">Reset</a>
            @endif
        </div>

        <div class="tf-table-wrap tf-user-table-wrap">
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
                                        <div class="progress-bar {{ $task->progress_percentage == 100 ? 'bg-success' : ($isOverdue ? 'bg-danger' : 'bg-primary') }}"
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
        </div>

        <footer class="tf-management__table-footer">
            <span>
                Showing {{ $tasks->firstItem() ?? 0 }}–{{ $tasks->lastItem() ?? 0 }} of {{ $tasks->total() }} tasks
            </span>
            {{ $tasks->links('pagination::bootstrap-5') }}
        </footer>
    </section>

    @include('tasks._reassign-modal')



    @push('scripts')
        <script>
            const taskFilterForm = document.querySelector('[data-task-filter-form]');
            const taskCompanyFilter = taskFilterForm?.querySelector('[data-task-company-filter]');
            const taskDepartmentFilter = taskFilterForm?.querySelector('[data-task-department-filter]');
            const taskToolbarFilters = taskFilterForm?.querySelectorAll('select');

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

            const selectAllTasks = document.querySelector('[data-task-select-all]');
            const taskCheckboxes = [...document.querySelectorAll('[data-task-checkbox]')];

            const syncTaskSelection = () => {
                if (!selectAllTasks) {
                    return;
                }

                const selectedCount = taskCheckboxes.filter((checkbox) => checkbox.checked).length;
                selectAllTasks.checked = selectedCount > 0 && selectedCount === taskCheckboxes.length;
                selectAllTasks.indeterminate = selectedCount > 0 && selectedCount < taskCheckboxes.length;
            };

            selectAllTasks?.addEventListener('change', () => {
                taskCheckboxes.forEach((checkbox) => {
                    checkbox.checked = selectAllTasks.checked;
                });
                syncTaskSelection();
            });

            taskCheckboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', syncTaskSelection);
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
        </script>
    @endpush
@endsection

@extends('layouts.app')

@section('title', 'Daily Tasks')

@section('content')
    <section class="tf-page">
        <header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Daily Tasks</h1>
                <p class="text-secondary mb-0">Employee-planned work and self-reported progress.</p>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('tasks.index') }}">Main Tasks</a>
        </header>

        @if (session('success'))
            <div class="alert alert-success" role="status">{{ session('success') }}</div>
        @endif

        <div class="row g-3 mb-4" aria-label="Daily Task summary">
            @foreach ([
                'total' => 'Total',
                'pending' => 'Pending',
                'in_progress' => 'In Progress',
                'on_hold' => 'On Hold',
                'completed' => 'Completed',
                'overdue' => 'Missed',
            ] as $key => $label)
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card h-100"><div class="card-body">
                        <div class="small text-secondary">{{ $label }}</div>
                        <div class="fs-4 fw-semibold">{{ $summary[$key] }}</div>
                    </div></div>
                </div>
            @endforeach
        </div>

        <form method="GET" action="{{ route('daily-tasks.index') }}" class="card card-body mb-4">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="dailyTaskSearch">Search</label>
                    <input class="form-control" id="dailyTaskSearch" name="search" value="{{ $filters['search'] ?? '' }}"
                        maxlength="255" placeholder="Title or description">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="dailyTaskDateFrom">From</label>
                    <input class="form-control" type="date" id="dailyTaskDateFrom" name="date_from"
                        value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="dailyTaskDateTo">To</label>
                    <input class="form-control" type="date" id="dailyTaskDateTo" name="date_to"
                        value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="dailyTaskEmployee">Employee</label>
                    <select class="form-select" id="dailyTaskEmployee" name="assigned_to">
                        <option value="">All employees</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected((string) ($filters['assigned_to'] ?? '') === (string) $employee->id)>
                                {{ $employee->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="dailyTaskDepartment">Department</label>
                    <select class="form-select" id="dailyTaskDepartment" name="department_id">
                        <option value="">All departments</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((string) ($filters['department_id'] ?? '') === (string) $department->id)>
                                {{ $department->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="dailyTaskMainTask">Main Task</label>
                    <select class="form-select" id="dailyTaskMainTask" name="task_id">
                        <option value="">All main tasks</option>
                        @foreach ($tasks as $task)
                            <option value="{{ $task->id }}" @selected((string) ($filters['task_id'] ?? '') === (string) $task->id)>
                                {{ $task->task_code }} · {{ $task->title }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="dailyTaskSubtask">Subtask</label>
                    <select class="form-select" id="dailyTaskSubtask" name="subtask_id">
                        <option value="">All subtasks</option>
                        @foreach ($subtasks as $subtask)
                            <option value="{{ $subtask->id }}" @selected((string) ($filters['subtask_id'] ?? '') === (string) $subtask->id)>
                                {{ $subtask->title }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="dailyTaskMilestone">Milestone</label>
                    <select class="form-select" id="dailyTaskMilestone" name="milestone_id">
                        <option value="">All milestones</option>
                        @foreach ($milestones as $milestone)
                            <option value="{{ $milestone->id }}" @selected((string) ($filters['milestone_id'] ?? '') === (string) $milestone->id)>
                                {{ $milestone->title }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label class="form-label" for="dailyTaskStatus">Status</label>
                    <select class="form-select" id="dailyTaskStatus" name="status">
                        <option value="">All</option>
                        @foreach (\App\Models\DailyTask::STATUSES as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                                {{ \App\Models\DailyTask::STATUS_LABELS[$status] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="dailyTaskPriority">Priority</label>
                    <select class="form-select" id="dailyTaskPriority" name="priority">
                        <option value="">All priorities</option>
                        @foreach (\App\Models\DailyTask::PRIORITIES as $priority)
                            <option value="{{ $priority }}" @selected(($filters['priority'] ?? '') === $priority)>
                                {{ \App\Models\DailyTask::PRIORITY_LABELS[$priority] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Apply filters</button>
                    <a class="btn btn-outline-secondary" href="{{ route('daily-tasks.index') }}">Clear</a>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th>Daily Task</th><th>Employee</th><th>Department</th><th>Main Task</th>
                        <th>Subtask / Milestone</th><th>Work Date</th><th>Planned</th><th>Priority</th>
                        <th>Progress</th><th>Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($dailyTasks as $dailyTask)
                            <tr>
                                <td>
                                    <a class="fw-semibold text-decoration-none"
                                        href="{{ route('daily-tasks.show', [$dailyTask->task_id, $dailyTask]) }}">
                                        {{ $dailyTask->title }}
                                    </a>
                                    @if ($dailyTask->description)
                                        <div class="small text-secondary">{{ \Illuminate\Support\Str::limit($dailyTask->description, 90) }}</div>
                                    @endif
                                </td>
                                <td>{{ $dailyTask->assignee?->name ?? 'Former employee' }}</td>
                                <td>{{ $dailyTask->task?->department?->name ?? '—' }}</td>
                                <td>
                                    @if ($dailyTask->task)
                                        <a href="{{ route('tasks.show', $dailyTask->task) }}">{{ $dailyTask->task->task_code }}</a>
                                        <div class="small text-secondary">{{ $dailyTask->task->title }}</div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    {{ $dailyTask->subtask?->title ?? '—' }}
                                    @if ($dailyTask->milestone)
                                        <div class="small text-secondary">Milestone: {{ $dailyTask->milestone->title }}</div>
                                    @endif
                                </td>
                                <td>{{ $dailyTask->work_date?->format('M j, Y') }}</td>
                                <td>{{ $dailyTask->formatTime($dailyTask->planned_start_time) }}–{{ $dailyTask->formatTime($dailyTask->planned_end_time) }}</td>
                                <td>{{ \App\Models\DailyTask::PRIORITY_LABELS[$dailyTask->priority] ?? ucfirst($dailyTask->priority) }}</td>
                                <td style="min-width: 120px">
                                    <div class="progress" role="progressbar" aria-label="Progress" aria-valuenow="{{ $dailyTask->progress_percentage }}" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar" style="width: {{ $dailyTask->progress_percentage }}%"></div>
                                    </div>
                                    <span class="small">{{ $dailyTask->progress_percentage }}%</span>
                                </td>
                                <td>{{ \App\Models\DailyTask::STATUS_LABELS[$dailyTask->status] ?? ucfirst($dailyTask->status) }}</td>
                                <td><a class="btn btn-sm btn-outline-primary" href="{{ route('daily-tasks.show', [$dailyTask->task_id, $dailyTask]) }}">View</a></td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-secondary py-5" colspan="11">No Daily Tasks match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($dailyTasks->hasPages())
                <div class="card-footer">{{ $dailyTasks->links() }}</div>
            @endif
        </div>
    </section>
@endsection

@extends('layouts.app')

@section('title', 'Team Leader Dashboard')

@section('content')

    {{-- PAGE HEADER --}}
    <div class="tf-page-head">
        <div>
            <h1>
                <span>{{ $greeting }}, {{ $user->name }}</span>
                <span class="tf-wave">👋</span>
            </h1>
            <p>
                Department: <strong>{{ $department->name ?? 'Unassigned' }}</strong>
                @if ($department?->company)
                    · Company: <strong>{{ $department->company->name }}</strong>
                @endif
            </p>
        </div>

        <div class="tf-page-head__actions">
            @can('tasks.create')
                <button class="tf-btn tf-btn--primary tf-btn--create" type="button" data-task-create-trigger>
                    <i class="bi bi-plus-lg"></i>
                    <span>Create Task</span>
                </button>
            @endcan
        </div>
    </div>

    {{-- 5 PRIMARY DEPARTMENT SUMMARY CARDS --}}
    <section class="tf-kpi-row--5">
        @foreach ($kpis as $kpi)
            @include('components.stat-card', $kpi)
        @endforeach
    </section>

    {{-- ROW 1: DEPARTMENT TASK COMPLETION + DEPARTMENT TASK STATUS --}}
    <section class="tf-grid tf-grid--65-35 mb-4">
        @include('dashboard.partials.completion-chart', [
            'completionChart' => $completionChart,
            'title' => 'Department Task Completion',
            'subtitle' => 'Tasks completed in ' . ($department->name ?? 'Department') . ' over ' . $dateRange['label'],
        ])

        @include('dashboard.partials.status-donut', [
            'statusDistribution' => $statusDistribution,
            'title' => 'Department Task Status',
        ])
    </section>

    {{-- DEPARTMENT EMPLOYEES' WORKLOAD --}}
    @include('dashboard.partials.department-workload', [
        'departmentWorkload' => $departmentWorkload,
    ])

    {{-- ROW 2: TEAM LEADER'S OWN TASKS (PERSONAL) --}}
    <section class="tf-card mb-4" id="teamLeaderPersonalTasksSection">
        <div class="tf-card__head">
            <div class="tf-card__title">
                <i class="bi bi-person-check tf-card__icon"></i>
                <div>
                    <h2>My Personal Tasks</h2>
                    <p>Tasks personally assigned to you as Team Leader</p>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-primary border">Total: {{ $personalCounts['total'] }}</span>
                <span class="badge bg-light text-warning border">In Progress: {{ $personalCounts['in_progress'] }}</span>
                <span class="badge bg-light text-success border">Completed: {{ $personalCounts['completed'] }}</span>
                @if ($personalCounts['overdue'] > 0)
                    <span class="badge bg-danger text-white">{{ $personalCounts['overdue'] }} Overdue</span>
                @endif
            </div>
        </div>

        @if ($personalRecentTasks->isNotEmpty())
            <div class="tf-analytics-table-wrap">
                <table class="tf-analytics-table">
                    <thead>
                        <tr>
                            <th>Task Code & Title</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Last Updated</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($personalRecentTasks as $pTask)
                            <tr>
                                <td>
                                    <a href="{{ route('tasks.show', $pTask) }}" class="fw-bold text-dark text-decoration-none">
                                        {{ $pTask->title }}
                                    </a>
                                    <div class="text-muted small font-monospace">{{ $pTask->task_code }}</div>
                                </td>
                                <td>
                                    <span class="tf-dot tf-dot--{{ $pTask->priority }}"></span>
                                    <span class="small text-capitalize">{{ $pTask->priority }}</span>
                                </td>
                                <td>
                                    <div class="d-flex flex-column align-items-start gap-1">
                                        <span class="tf-status-pill tf-status-pill--{{ $pTask->display_status }}">
                                            {{ \App\Models\Task::STATUS_LABELS[$pTask->display_status] ?? ucwords(str_replace('_', ' ', $pTask->display_status)) }}
                                        </span>
                                        @if ($pTask->deadline_condition !== 'normal')
                                            <span class="tf-status-pill tf-status-pill--{{ $pTask->deadline_condition }}">
                                                Deadline: {{ \App\Models\Task::DEADLINE_CONDITION_LABELS[$pTask->deadline_condition] }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span class="small {{ $pTask->isOverdue() ? 'text-danger fw-bold' : 'text-muted' }}">
                                        {{ $pTask->due_date ? $pTask->due_date->format('M d, Y') : '—' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="text-muted small">{{ $pTask->updated_at->diffForHumans() }}</span>
                                </td>
                                <td>
                                    <a href="{{ route('tasks.show', $pTask) }}" class="tf-btn tf-btn--ghost tf-btn--sm">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-center py-4 text-muted small">
                <i class="bi bi-check2-circle fs-3 d-block mb-1 text-light"></i>
                You currently have no personal tasks assigned to you.
            </div>
        @endif
    </section>

    {{-- RECENT DEPARTMENT TASKS + UPCOMING DEADLINES / OVERDUE --}}
    <section class="tf-grid tf-grid--65-35">
        @include('dashboard.partials.recent-tasks', [
            'tasks' => $recentDeptTasks,
            'title' => 'Recent Department Tasks',
            'viewAllRoute' => route('tasks.index'),
            'showOrganization' => false,
        ])

        @include('dashboard.partials.deadlines-and-overdue', [
            'upcomingDeadlines' => $upcomingDeadlines,
            'overdueTasks' => $overdueTasks,
        ])
    </section>

@endsection

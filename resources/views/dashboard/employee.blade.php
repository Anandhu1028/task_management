@extends('layouts.app')

@section('title', 'Employee Dashboard')

@section('content')

    {{-- PAGE HEADER --}}
    <div class="tf-page-head">
        <div>
            <h1>
                <span>{{ $greeting }}, {{ $user->name }}</span>
                <span class="tf-wave">👋</span>
                
            </h1>
            <p>Your personal task board, upcoming deadlines, and productivity history.</p>
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

  

    {{-- 4 PERSONAL SUMMARY CARDS --}}
    <section class="tf-kpi-row--4">
        @foreach ($kpis as $kpi)
            @include('components.stat-card', [
                ...$kpi,
                'href' => $loop->first ? route('tasks.index') : null,
            ])
        @endforeach
    </section>

    {{-- ROW 1: MY TASK COMPLETION + MY TASK STATUS --}}
    <section class="tf-grid tf-grid--65-35 mb-4">
        @include('dashboard.partials.completion-chart', [
            'completionChart' => $completionChart,
            'title' => 'My Task Completion',
            'subtitle' => 'Your personal completed tasks over ' . $dateRange['label'],
        ])

        @include('dashboard.partials.status-donut', [
            'statusDistribution' => $statusDistribution,
            'title' => 'My Task Status',
        ])
    </section>

    {{-- ROW 2: MY WORKLOAD + MY PRIORITY OVERVIEW --}}
    <section class="tf-grid tf-grid--2 mb-4">
        @include('dashboard.partials.employee-workload', [
            'myWorkload' => $myWorkload,
        ])

        @include('dashboard.partials.priority-overview', [
            'priorityDistribution' => $priorityDistribution,
            'title' => 'My Priority Overview',
            'subtitle' => 'Your active workload by priority level',
        ])
    </section>

    {{-- ROW 3: RECENT ASSIGNED TASKS + UPCOMING DEADLINES / OVERDUE --}}
    <section class="tf-grid tf-grid--65-35">
        @include('dashboard.partials.recent-tasks', [
            'tasks' => $recentTasks,
            'title' => 'My Recent Tasks',
            'viewAllRoute' => route('tasks.index'),
            'showOrganization' => false,
        ])

        @include('dashboard.partials.deadlines-and-overdue', [
            'upcomingDeadlines' => $upcomingDeadlines,
            'overdueTasks' => $overdueTasks,
        ])
    </section>

@endsection

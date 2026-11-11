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

@endsection

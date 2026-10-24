@extends('layouts.app')

@section('title', 'CEO Dashboard')

@section('content')

    {{-- PAGE HEADER --}}
    <div class="tf-page-head">
        <div>
            <h1>
                <span>{{ $greeting }}, {{ $user->name }}</span>
                <span class="tf-wave">👋</span>
                <span class="tf-role-badge tf-role-badge--ceo">
                    <i class="bi bi-briefcase"></i> CEO
                </span>
            </h1>
            <p>Executive organization overview, enterprise metrics, and department progress monitoring.</p>
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

  

    {{-- 6 PRIMARY SUMMARY CARDS --}}
    <section class="tf-kpi-row--6">
        @foreach ($kpis as $kpi)
            @include('components.stat-card', $kpi)
        @endforeach
    </section>

    {{-- TASK COMPLETION GRAPH + TASK STATUS DONUT --}}
    <section class="tf-grid tf-grid--65-35 mb-4">
        @include('dashboard.partials.completion-chart', [
            'completionChart' => $completionChart,
            'title' => 'Task Completion Trends',
            'subtitle' => 'Company-wide task completions over ' . $dateRange['label'],
        ])

        @include('dashboard.partials.status-donut', [
            'statusDistribution' => $statusDistribution,
            'title' => 'Organization Task Status',
        ])
    </section>

    {{-- COMPANY-WISE ANALYTICS --}}
    @include('dashboard.partials.company-analytics', [
        'companyAnalytics' => $companyAnalytics,
    ])

    @include('dashboard.partials.department-performance', [
        'departmentPerformanceAnalytics' => $departmentPerformanceAnalytics,
        'employeeHeadcountByCompany' => $employeeHeadcountByCompany,
        'companies' => $filterOptions['companies'],
        'selectedCompanyId' => $selectedFilters['company_id'],
    ])

    {{-- RECENT TASKS --}}
    @include('dashboard.partials.recent-tasks', [
        'tasks' => $recentTasks,
        'title' => 'Recent Organizational Tasks',
        'viewAllRoute' => route('tasks.index'),
        'showOrganization' => true,
    ])

@endsection

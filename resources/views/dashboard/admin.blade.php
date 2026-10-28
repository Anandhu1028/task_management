@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')

<div class="tf-admin-dashboard">
    {{-- PAGE HEADER --}}
    <div class="tf-page-head">
        <div>
            <h1>
                <span>{{ $greeting }}, {{ $user->name }}</span>
            </h1>
            <p>Complete organization-wide operational overview across all companies, departments, teams, and tasks.</p>
        </div>
    </div>

   

    {{-- PRIMARY SUMMARY CARDS --}}
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
            'subtitle' => 'Tasks completed over ' . $dateRange['label'] . ' across selected scope',
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
</div>

@endsection

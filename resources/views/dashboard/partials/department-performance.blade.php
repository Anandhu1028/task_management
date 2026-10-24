@props([
    'departmentPerformanceAnalytics' => [],
    'employeeHeadcountByCompany' => [],
    'companies' => [],
    'selectedCompanyId' => null,
])

<section class="tf-grid tf-grid--65-35 mb-4" id="departmentPerformanceSection">
    @php
        $departmentCount = count($departmentPerformanceAnalytics);
        $departmentTaskCount = array_sum(array_column($departmentPerformanceAnalytics, 'total_tasks'));
        $averageCompletion = $departmentCount > 0
            ? array_sum(array_column($departmentPerformanceAnalytics, 'completion_rate')) / $departmentCount
            : 0;
        $employeeCount = array_sum(array_column($employeeHeadcountByCompany, 'employee_count'));
    @endphp

    <article class="tf-card tf-department-performance">
        <div class="tf-department-performance__header">
            <div class="tf-department-performance__heading">
                <span class="tf-dashboard-insight-icon tf-dashboard-insight-icon--blue">
                    <i class="bi bi-diagram-3"></i>
                </span>
                <div>
                    <h2>Department-wise performance</h2>
                    <p>Completion progress across your departments</p>
                </div>
            </div>

            <div class="tf-department-performance__controls">
                <span class="tf-dashboard-insight-tag" data-department-count>{{ $departmentCount }} departments</span>
                <label class="tf-chart-range">
                    <span class="visually-hidden">Filter department performance by company</span>
                    <select class="form-select form-select-sm" data-department-performance-company>
                        <option value="">All companies</option>
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}" @selected((string) $selectedCompanyId === (string) $company->id)>
                                {{ $company->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="tf-department-performance__summary">
            <div>
                <span class="tf-department-performance__summary-value" data-department-average>{{ number_format($averageCompletion, 0) }}%</span>
                <span class="tf-department-performance__summary-label">Average completion</span>
            </div>
            <span class="tf-department-performance__summary-divider"></span>
            <div>
                <span class="tf-department-performance__summary-value" data-department-tasks>{{ number_format($departmentTaskCount) }}</span>
                <span class="tf-department-performance__summary-label">Department tasks</span>
            </div>
        </div>

        <div class="tf-department-performance__list">
            @forelse ($departmentPerformanceAnalytics as $department)
                @php
                    $rate = min(100, max(0, $department['completion_rate']));
                    $tone = match (true) {
                        $rate >= 85 => 'success',
                        $rate >= 65 => 'blue',
                        $rate >= 55 => 'warning',
                        default => 'danger',
                    };
                @endphp
                <div class="tf-department-performance__row"
                     data-department-performance-row
                     data-company-id="{{ $department['company_id'] }}"
                     data-completion-rate="{{ $rate }}"
                     data-task-count="{{ $department['total_tasks'] }}">
                    <span class="tf-department-performance__rank">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <div class="tf-department-performance__details">
                        <div class="tf-department-performance__row-heading">
                            <span class="tf-department-performance__name" title="{{ $department['name'] }}">
                                {{ $department['name'] }}
                            </span>
                            <span class="tf-department-performance__company">{{ $department['company_name'] }}</span>
                        </div>
                        <div class="tf-department-performance__track" role="progressbar"
                             aria-label="{{ $department['name'] }} completion rate"
                             aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $rate }}">
                            <span class="tf-department-performance__bar tf-department-performance__bar--{{ $tone }}"
                                  style="width: {{ $rate }}%"></span>
                        </div>
                    </div>
                    <div class="tf-department-performance__metrics">
                        <span>{{ number_format($department['total_tasks']) }} tasks</span>
                        <strong class="tf-department-performance__rate tf-department-performance__rate--{{ $tone }}">
                            {{ number_format($rate, 0) }}%
                        </strong>
                    </div>
                </div>
            @empty
                <div class="tf-department-performance__blank">
                    <i class="bi bi-bar-chart"></i>
                    <span>No department performance data available.</span>
                </div>
            @endforelse
            <div class="tf-department-performance__blank" data-department-performance-empty hidden>
                <i class="bi bi-search"></i>
                <span>No departments found for this company.</span>
            </div>
        </div>
    </article>

    <article class="tf-card tf-employee-headcount">
        <div class="tf-employee-headcount__header">
            <div class="tf-department-performance__heading">
                <span class="tf-dashboard-insight-icon tf-dashboard-insight-icon--purple">
                    <i class="bi bi-people"></i>
                </span>
                <div>
                    <h2>Employees by company</h2>
                    <p>Active workforce across companies</p>
                </div>
            </div>
            <span class="tf-employee-headcount__total">{{ number_format($employeeCount) }}<small>total</small></span>
        </div>

        @if (count($employeeHeadcountByCompany) > 0)
            <div class="tf-employee-headcount__scroll" role="region" aria-label="Employee count by company chart" tabindex="0">
                <div class="tf-employee-headcount__chart"
                     style="width: max(100%, {{ max(320, count($employeeHeadcountByCompany) * 145) }}px);">
                    <canvas id="employeeHeadcountChart"
                            data-labels='@json(array_column($employeeHeadcountByCompany, 'name'))'
                            data-counts='@json(array_column($employeeHeadcountByCompany, 'employee_count'))'></canvas>
                </div>
            </div>
        @else
            <div class="tf-department-performance__blank">
                <i class="bi bi-people"></i>
                <span>No active employee records available.</span>
            </div>
        @endif

        <div class="tf-employee-headcount__foot">
            <span><i class="bi bi-circle-fill"></i> Active employees</span>
            <span>{{ count($employeeHeadcountByCompany) }} companies</span>
        </div>
    </article>
</section>

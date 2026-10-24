@props([
    'employeeAnalytics' => [],
])

<article class="tf-card mb-4" id="employeeOverviewSection">
    <div class="tf-card__head">
        <div class="tf-card__title">
            <i class="bi bi-people tf-card__icon"></i>
            <div>
                <h2>Employee Performance</h2>
                <p>Individual task execution and completion rates</p>
            </div>
        </div>
    </div>

    <div class="tf-analytics-table-wrap">
        <table class="tf-analytics-table">
            <thead>
                <tr>
                    <th>Employee Name</th>
                    <th>Department</th>
                    <th>Company</th>
                    <th>Total Assigned</th>
                    @foreach (\App\Models\Task::STATUSES as $status)
                        <th>{{ \App\Models\Task::STATUS_LABELS[$status] }}</th>
                    @endforeach
                    <th>At Risk</th>
                    <th>Overdue</th>
                    <th>Completion Rate</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($employeeAnalytics as $item)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="tf-avatar tf-avatar--sm tf-avatar--aa">
                                    {{ strtoupper(substr($item['name'], 0, 2)) }}
                                </span>
                                <strong>{{ $item['name'] }}</strong>
                            </div>
                        </td>
                        <td><span class="text-secondary">{{ $item['department_name'] }}</span></td>
                        <td><span class="text-secondary">{{ $item['company_name'] }}</span></td>
                        <td><strong>{{ $item['total_tasks'] }}</strong></td>
                        @foreach (\App\Models\Task::STATUSES as $status)
                            <td><span class="tf-status-pill tf-status-pill--{{ $status }}">{{ $item[$status] }}</span></td>
                        @endforeach
                        <td><span class="tf-status-pill tf-status-pill--at_risk">{{ $item['at_risk'] }}</span></td>
                        <td><span class="tf-status-pill tf-status-pill--overdue">{{ $item['overdue'] }}</span></td>
                        <td class="tf-progress-cell">
                            <div class="d-flex align-items-center justify-content-between small">
                                <strong>{{ $item['completion_rate'] }}%</strong>
                            </div>
                            <div class="tf-progress-bar-wrap">
                                <div class="tf-progress-bar-fill @if($item['completion_rate'] >= 75) tf-progress-bar-fill--success @elseif($item['completion_rate'] >= 40) tf-progress-bar-fill--warning @else tf-progress-bar-fill--danger @endif"
                                     style="width: {{ $item['completion_rate'] }}%"></div>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-1">
                                <a href="{{ route('dashboard', ['employee_id' => $item['id']]) }}" class="tf-btn tf-btn--ghost tf-btn--sm" title="Filter dashboard">
                                    <i class="bi bi-funnel"></i>
                                </a>
                                @can('tasks.view')
                                    <a href="{{ route('tasks.index', ['assigned_to' => $item['id']]) }}" class="tf-btn tf-btn--ghost tf-btn--sm" title="View all tasks">
                                        <i class="bi bi-list-task"></i>
                                    </a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count(\App\Models\Task::STATUSES) + 8 }}" class="text-center py-4 text-muted">No employee task assignments found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</article>

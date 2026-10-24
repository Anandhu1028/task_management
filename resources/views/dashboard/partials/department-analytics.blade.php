@props([
    'departmentAnalytics' => [],
])

<article class="tf-card mb-4" id="departmentOverviewSection">
    <div class="tf-card__head">
        <div class="tf-card__title">
            <i class="bi bi-diagram-3 tf-card__icon"></i>
            <div>
                <h2>Department Overview</h2>
                <p>Operational workload and status metrics across departments</p>
            </div>
        </div>
        <div class="text-muted small">
            Click department to filter
        </div>
    </div>

    {{-- Department Breakdown Table --}}
    <div class="tf-analytics-table-wrap">
        <table class="tf-analytics-table">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Company</th>
                    <th>Total Tasks</th>
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
                @forelse ($departmentAnalytics as $item)
                    <tr class="is-clickable" onclick="window.location='{{ route('dashboard', ['department_id' => $item['id'], 'company_id' => $item['company_id']]) }}'">
                        <td>
                            <strong>{{ $item['name'] }}</strong>
                        </td>
                        <td>
                            <span class="text-secondary">{{ $item['company_name'] }}</span>
                        </td>
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
                            <a href="{{ route('dashboard', ['department_id' => $item['id'], 'company_id' => $item['company_id']]) }}" class="tf-btn tf-btn--ghost tf-btn--sm" onclick="event.stopPropagation();">
                                <i class="bi bi-funnel"></i>
                                View
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count(\App\Models\Task::STATUSES) + 7 }}" class="text-center py-4 text-muted">No department records available.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</article>

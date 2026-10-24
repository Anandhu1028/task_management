@props([
    'teamLeaderAnalytics' => [],
])

<article class="tf-card mb-4" id="teamLeaderOverviewSection">
    <div class="tf-card__head">
        <div class="tf-card__title">
            <i class="bi bi-person-badge tf-card__icon"></i>
            <div>
                <h2>Team Leader Performance</h2>
                <p>Personal task execution and managed department task volume</p>
            </div>
        </div>
    </div>

    <div class="tf-analytics-table-wrap">
        <table class="tf-analytics-table">
            <thead>
                <tr>
                    <th>Team Leader</th>
                    <th>Department</th>
                    <th>Company</th>
                    <th>Personal Tasks</th>
                    <th>Dept Volume</th>
                    <th>In Progress</th>
                    <th>Completed</th>
                    <th>At Risk</th>
                    <th>Overdue</th>
                    <th>Personal Completion</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($teamLeaderAnalytics as $item)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="tf-avatar tf-avatar--sm tf-avatar--rk">
                                    {{ strtoupper(substr($item['name'], 0, 2)) }}
                                </span>
                                <strong>{{ $item['name'] }}</strong>
                            </div>
                        </td>
                        <td><span class="text-secondary">{{ $item['department_name'] }}</span></td>
                        <td><span class="text-secondary">{{ $item['company_name'] }}</span></td>
                        <td><strong>{{ $item['personal_tasks_count'] }}</strong></td>
                        <td><span class="badge bg-light text-dark border">{{ $item['department_tasks_count'] }} tasks</span></td>
                        <td><span class="tf-status-pill tf-status-pill--in_progress">{{ $item['in_progress'] }}</span></td>
                        <td><span class="tf-status-pill tf-status-pill--completed">{{ $item['completed'] }}</span></td>
                        <td><span class="tf-status-pill tf-status-pill--at_risk">{{ $item['at_risk'] }}</span></td>
                        <td>
                            @if ($item['overdue'] > 0)
                                <span class="tf-status-pill tf-status-pill--overdue">{{ $item['overdue'] }}</span>
                            @else
                                <span class="text-muted">0</span>
                            @endif
                        </td>
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
                            <a href="{{ route('dashboard', ['team_leader_id' => $item['id']]) }}" class="tf-btn tf-btn--ghost tf-btn--sm">
                                <i class="bi bi-funnel"></i>
                                Filter
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center py-4 text-muted">No Team Leader records found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</article>

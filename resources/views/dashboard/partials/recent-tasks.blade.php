@props([
    'tasks' => [],
    'title' => 'Recent Tasks',
    'viewAllRoute' => route('tasks.index'),
    'showOrganization' => true,
])

<article class="tf-card tf-recent-tasks-card">
    <div class="tf-card__head tf-card__head--tabs">
        <div class="tf-card__title-group">
            <div class="tf-card__title">
                <i class="bi bi-card-checklist tf-card__icon"></i>
                <div>
                    <h2>{{ $title }}</h2>
                </div>
            </div>

            <div class="tf-tabs d-none d-md-flex" role="tablist">
                <button class="is-active" type="button" data-task-tab="all">All Tasks</button>
                <button type="button" data-task-tab="my">My Tasks</button>
            </div>
        </div>

        <div class="tf-card__head-actions">
            @can('tasks.view')
                <a class="tf-text-link" href="{{ $viewAllRoute }}">View All →</a>
            @endcan
        </div>
    </div>

    <div class="tf-analytics-table-wrap">
        <table class="tf-analytics-table">
            <thead>
                <tr>
                    <th>Task Code & Title</th>
                    @if ($showOrganization)
                        <th>Organization</th>
                    @endif
                    <th>Assignee</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Due Date</th>
                    <th>Last Updated</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tasks as $task)
                    @php
                        $status = $task->display_status;
                        $statusClass = 'tf-status-pill--'.$status;
                        $isOverdue = $task->isOverdue();
                        $deadlineCondition = $task->deadline_condition;
                    @endphp
                    <tr>
                        <td>
                            <div>
                                <a href="{{ route('tasks.show', $task) }}" class="fw-bold text-dark text-decoration-none">
                                    {{ $task->title }}
                                </a>
                                <div class="text-muted small">
                                    <span class="font-monospace">{{ $task->task_code }}</span>
                                    @if ($task->project)
                                        · <span>{{ $task->project }}</span>
                                    @endif
                                </div>
                            </div>
                        </td>
                        @if ($showOrganization)
                            <td>
                                <div class="small">
                                    <strong>{{ $task->company?->name ?? '—' }}</strong>
                                    <div class="text-muted">{{ $task->department?->name ?? '—' }}</div>
                                </div>
                            </td>
                        @endif
                        <td>
                            @if ($task->assignee)
                                <div class="d-flex align-items-center gap-1 small">
                                    <span class="tf-avatar tf-avatar--sm tf-avatar--aa">
                                        {{ strtoupper(substr($task->assignee->name, 0, 2)) }}
                                    </span>
                                    <span>{{ $task->assignee->name }}</span>
                                </div>
                            @else
                                <span class="text-muted small">Unassigned</span>
                            @endif
                        </td>
                        <td>
                            <span class="tf-recent-tasks__priority tf-recent-tasks__priority--{{ $task->priority }}">
                                <span class="tf-dot" aria-hidden="true"></span>
                                <span>{{ $task->priority }}</span>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex flex-column align-items-start gap-1">
                                <span class="tf-status-pill {{ $statusClass }}" @if ($status === 'on_hold') title="{{ $task->hold_reason }}" @endif>
                                    {{ \App\Models\Task::STATUS_LABELS[$status] ?? ucwords(str_replace('_', ' ', $status)) }}
                                </span>
                                @if ($deadlineCondition !== 'normal')
                                    <span class="tf-status-pill tf-status-pill--{{ $deadlineCondition }}" @if ($deadlineCondition === 'at_risk' && $task->risk_reason) title="{{ $task->risk_reason }}" @endif>
                                        Deadline: {{ \App\Models\Task::DEADLINE_CONDITION_LABELS[$deadlineCondition] }}
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td>
                            @if ($task->occurrence_date)
                                <span class="tf-recent-tasks__due-date {{ $isOverdue ? 'is-overdue' : '' }}">
                                    Occurrence: {{ $task->occurrence_date->format('M d, Y') }}
                                </span>
                            @elseif ($task->due_date)
                                <span class="tf-recent-tasks__due-date {{ $isOverdue ? 'is-overdue' : '' }}">
                                    {{ $task->due_date->format('M d, Y') }}
                                </span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="text-muted small">{{ $task->updated_at->diffForHumans() }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showOrganization ? 7 : 6 }}" class="text-center py-4 text-muted">
                            <i class="bi bi-inbox fs-4 d-block mb-1 text-light"></i>
                            No tasks recorded yet in this scope.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</article>

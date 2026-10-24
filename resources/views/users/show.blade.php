@extends('layouts.app')

@section('title', $user->name)

@section('content')
    @php
        $profileInitials = collect(preg_split('/\s+/', trim($user->name)))
            ->filter()
            ->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
        $assignedRoles = $user->roles->pluck('name')->join(', ') ?: $user->primaryRole?->name ?: '—';
    @endphp

    <div class="tf-profile-page">
        <header class="tf-profile-hero">
            <div class="" aria-hidden="true"></div>
            <div class="tf-profile-hero__main">
                <span class="tf-profile-hero__avatar" aria-hidden="true">{{ $profileInitials }}</span>
                <div class="tf-profile-hero__identity">
                    <h1>{{ $user->name }}</h1>
                    <p>
                        {{ $user->designation ?: 'Employee' }}
                        <span aria-hidden="true">·</span>
                        {{ $user->department?->name ?? 'No department' }}
                        <span aria-hidden="true">·</span>
                        {{ $user->email }}
                    </p>
                    <div class="tf-profile-hero__badges">
                        <span class="tf-profile-chip tf-profile-chip--{{ $user->status === 'active' ? 'active' : 'muted' }}">
                            <span aria-hidden="true"></span>{{ ucfirst($user->status) }}
                        </span>
                        <span class="tf-profile-chip">{{ $user->primaryRole?->name ?? $user->roles->first()?->name ?? 'Employee' }}</span>
                        @if ($user->employee_id)
                            <span class="tf-profile-chip tf-profile-chip--id">{{ $user->employee_id }}</span>
                        @endif
                    </div>
                </div>
                
            </div>

            <nav class="tf-profile-tabs" aria-label="User profile sections">
                <a class="is-active" href="#profile-overview">Overview</a>
                <a href="#assigned-tasks">Tasks</a>
                <a href="#recent-activity">Activity</a>
                <span aria-disabled="true" title="Documents are not available yet">Documents</span>
            </nav>
        </header>

        <section class="tf-profile-metrics" aria-label="Assigned task summary">
            <article class="tf-profile-metric">
                <span class="tf-profile-metric__icon tf-profile-metric__icon--blue"><i class="bi bi-check2-square" aria-hidden="true"></i></span>
                <div>
                    <span>Total tasks</span>
                    <strong>{{ number_format($taskCounts->total) }}</strong>
                    <small>Assigned to date</small>
                </div>
            </article>
            <article class="tf-profile-metric">
                <span class="tf-profile-metric__icon tf-profile-metric__icon--orange"><i class="bi bi-clock" aria-hidden="true"></i></span>
                <div>
                    <span>Open</span>
                    <strong>{{ number_format($taskCounts->open) }}</strong>
                    <small>{{ number_format($taskCounts->in_progress) }} in progress</small>
                </div>
            </article>
            <article class="tf-profile-metric">
                <span class="tf-profile-metric__icon tf-profile-metric__icon--green"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                <div>
                    <span>Completed</span>
                    <strong>{{ number_format($taskCounts->completed) }}</strong>
                    <small>{{ $taskCounts->completion_rate }}% of assigned tasks</small>
                </div>
            </article>
            <article class="tf-profile-metric">
                <span class="tf-profile-metric__icon tf-profile-metric__icon--red"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
                <div>
                    <span>Overdue</span>
                    <strong>{{ number_format($taskCounts->overdue) }}</strong>
                    <small>{{ $taskCounts->overdue > 0 ? 'Needs attention' : 'All caught up' }}</small>
                </div>
            </article>
        </section>

        <div class="tf-profile-grid">
            <section class="tf-profile-card" id="profile-overview" aria-labelledby="profile-details-title">
                <header class="tf-profile-card__header">
                    <h2 id="profile-details-title">Profile details</h2>
                </header>
                <dl class="tf-profile-details">
                    <div>
                        <dt>Full name</dt>
                        <dd>{{ $user->name }}</dd>
                    </div>
                    <div>
                        <dt>Employee ID</dt>
                        <dd>{{ $user->employee_id ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd><span class="tf-profile-inline-status tf-profile-inline-status--{{ $user->status }}"><i aria-hidden="true"></i>{{ ucfirst($user->status) }}</span></dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd><a href="mailto:{{ $user->email }}">{{ $user->email }}</a></dd>
                    </div>
                    <div>
                        <dt>Phone number</dt>
                        <dd>{{ $user->phone ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt>Assigned role</dt>
                        <dd>{{ $assignedRoles }}</dd>
                    </div>
                    <div>
                        <dt>Company</dt>
                        <dd>{{ $user->company?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Department</dt>
                        <dd>{{ $user->department?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Designation</dt>
                        <dd>{{ $user->designation ?: '—' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="tf-profile-card" aria-labelledby="account-access-title">
                <header class="tf-profile-card__header">
                    <h2 id="account-access-title">Account &amp; access</h2>
                </header>
                <dl class="tf-profile-access">
                    <div>
                        <dt>Status</dt>
                        <dd><span class="tf-profile-inline-status tf-profile-inline-status--{{ $user->status }}"><i aria-hidden="true"></i>{{ ucfirst($user->status) }}</span></dd>
                    </div>
                    <div>
                        <dt>Role</dt>
                        <dd>{{ $assignedRoles }}</dd>
                    </div>
                    <div>
                        <dt>Account created</dt>
                        <dd>{{ $user->created_at?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Last updated</dt>
                        <dd>{{ $user->updated_at?->format('d M Y, H:i') ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="tf-profile-card" aria-labelledby="task-overview-title">
                <header class="tf-profile-card__header">
                    <h2 id="task-overview-title">Task overview</h2>
                </header>
                <div class="tf-profile-task-breakdown" role="img" aria-label="{{ $taskCounts->completed }} completed, {{ $taskCounts->in_progress }} in progress, {{ $taskCounts->pending_assignment }} pending assignment, {{ $taskCounts->overdue }} overdue, {{ $taskCounts->other }} other">
                    @if ($taskCounts->total > 0)
                        <span class="tf-profile-task-breakdown__segment tf-profile-task-breakdown__segment--completed" style="width: {{ $taskCounts->completed_width }}%"></span>
                        <span class="tf-profile-task-breakdown__segment tf-profile-task-breakdown__segment--progress" style="width: {{ $taskCounts->in_progress_width }}%"></span>
                        <span class="tf-profile-task-breakdown__segment tf-profile-task-breakdown__segment--todo" style="width: {{ $taskCounts->pending_assignment_width }}%"></span>
                        <span class="tf-profile-task-breakdown__segment tf-profile-task-breakdown__segment--overdue" style="width: {{ $taskCounts->overdue_width }}%"></span>
                        <span class="tf-profile-task-breakdown__segment tf-profile-task-breakdown__segment--other" style="width: {{ $taskCounts->other_width }}%"></span>
                    @else
                        <span class="tf-profile-task-breakdown__empty"></span>
                    @endif
                </div>
                <ul class="tf-profile-legend">
                    <li class="tf-profile-legend__completed">Completed <strong>{{ $taskCounts->completed }}</strong></li>
                    <li class="tf-profile-legend__progress">In progress <strong>{{ $taskCounts->in_progress }}</strong></li>
                    <li class="tf-profile-legend__todo">Pending assignment <strong>{{ $taskCounts->pending_assignment }}</strong></li>
                    <li class="tf-profile-legend__overdue">Overdue <strong>{{ $taskCounts->overdue }}</strong></li>
                    @if ($taskCounts->other > 0)
                        <li class="tf-profile-legend__other">Other <strong>{{ $taskCounts->other }}</strong></li>
                    @endif
                </ul>
            </section>

            <section class="tf-profile-card tf-profile-workload" aria-labelledby="workload-title">
                <header class="tf-profile-card__header">
                    <h2 id="workload-title">Workload this week</h2>
                </header>
                <div class="tf-profile-workload__summary">
                    <span>Estimated effort</span>
                    <strong>{{ number_format($workloadHours, 1) }} / {{ $workloadCapacity }} hrs</strong>
                </div>
                <div class="tf-profile-workload__track" role="progressbar" aria-label="Estimated workload this week" aria-valuenow="{{ $workloadPercentage }}" aria-valuemin="0" aria-valuemax="100">
                    <span style="width: {{ $workloadPercentage }}%"></span>
                </div>
                <p>{{ $workloadPercentage }}% capacity · {{ number_format(max(0, $workloadCapacity - $workloadHours), 1) }} hrs available</p>
            </section>

            <section class="tf-profile-card tf-profile-tasks" id="assigned-tasks" aria-labelledby="assigned-tasks-title">
                <header class="tf-profile-card__header tf-profile-card__header--split">
                    <h2 id="assigned-tasks-title">Assigned tasks</h2>
                    <span>Showing {{ min($assignedTasks->count(), 6) }} of {{ number_format($taskCounts->total) }}</span>
                </header>
                @if ($assignedTasks->isNotEmpty())
                    <div class="tf-profile-table-wrap">
                        <table class="tf-profile-table">
                            <thead>
                                <tr>
                                    <th scope="col">Task</th>
                                    <th scope="col">Priority</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Progress</th>
                                    <th scope="col">Due</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($assignedTasks as $task)
                                    @php
                                        $status = $task->display_status;
                                        $priority = strtolower($task->priority);
                                    @endphp
                                    <tr>
                                        <td>
                                            <a class="tf-profile-task-name" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                            <small>{{ $task->task_code }} · {{ ucfirst($task->task_type) }}</small>
                                        </td>
                                        <td><span class="tf-profile-priority tf-profile-priority--{{ $priority }}">{{ ucfirst($priority) }}</span></td>
                                        <td><span class="tf-profile-task-status tf-profile-task-status--{{ $status }}">{{ ucwords(str_replace('_', ' ', $status)) }}</span></td>
                                        <td>
                                            <div class="tf-profile-progress">
                                                <span class="tf-profile-progress__track"><span style="width: {{ min(100, max(0, (int) $task->progress_percentage)) }}%"></span></span>
                                                <small>{{ (int) $task->progress_percentage }}%</small>
                                            </div>
                                        </td>
                                        <td>{{ ($task->due_date ?? $task->original_deadline)?->format('M d') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="tf-profile-empty">
                        <i class="bi bi-inbox" aria-hidden="true"></i>
                        <p>No tasks are currently assigned to this user.</p>
                    </div>
                @endif
            </section>

            <section class="tf-profile-card tf-profile-activity" id="recent-activity" aria-labelledby="recent-activity-title">
                <header class="tf-profile-card__header">
                    <h2 id="recent-activity-title">Recent activity</h2>
                </header>
                @if ($recentActivity->isNotEmpty())
                    <ol class="tf-profile-activity__list">
                        @foreach ($recentActivity as $activity)
                            <li>
                                <span class="tf-profile-activity__icon tf-profile-activity__icon--{{ $activity['tone'] }}">
                                    <i class="bi {{ $activity['icon'] }}" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p>{{ $activity['title'] }} @if ($activity['task'])<strong>{{ $activity['task']->task_code }}</strong>@endif</p>
                                    <small>{{ $activity['description'] }}</small>
                                    <time datetime="{{ $activity['at']?->toIso8601String() }}">{{ $activity['at']?->format('M d · g:i A') }}</time>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <div class="tf-profile-empty tf-profile-empty--compact">
                        <i class="bi bi-activity" aria-hidden="true"></i>
                        <p>No recent task activity.</p>
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection

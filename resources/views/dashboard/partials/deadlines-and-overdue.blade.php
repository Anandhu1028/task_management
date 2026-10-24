@props([
    'upcomingDeadlines' => [],
    'overdueTasks' => [],
])

<div class="d-flex flex-column gap-3">
    {{-- Upcoming Deadlines --}}
    <article class="tf-card">
        <div class="tf-card__head">
            <div class="tf-card__title">
                <i class="bi bi-calendar-event tf-card__icon"></i>
                <div>
                    <h2>Upcoming Deadlines</h2>
                    <p>Tasks due soon</p>
                </div>
            </div>
            @can('tasks.view')
                <a class="tf-text-link" href="{{ route('tasks.index') }}">View All →</a>
            @endcan
        </div>

        <div class="tf-deadline-list">
            @forelse ($upcomingDeadlines as $task)
                <a href="{{ route('tasks.show', $task) }}" class="tf-deadline-card text-decoration-none">
                    <div class="d-flex align-items-center gap-2 min-width-0">
                        <span class="tf-dot tf-dot--{{ $task->priority }}"></span>
                        <div class="tf-deadline-card__info">
                            <span class="tf-deadline-card__title">{{ $task->title }}</span>
                            <span class="tf-deadline-card__sub">
                                <span class="font-monospace">{{ $task->task_code }}</span>
                                @if ($task->assignee)
                                    · <span>{{ $task->assignee->name }}</span>
                                @endif
                            </span>
                        </div>
                    </div>
                    <time class="tf-deadline-card__date">
                        {{ $task->due_date ? $task->due_date->format('M d') : '—' }}
                    </time>
                </a>
            @empty
                <div class="text-center py-4 text-muted small">
                    <i class="bi bi-calendar-check fs-3 d-block mb-1 text-light"></i>
                    No upcoming deadlines in this period.
                </div>
            @endforelse
        </div>
    </article>

    {{-- Overdue Tasks (Requires Attention) --}}
    @if ($overdueTasks->isNotEmpty())
        <article class="tf-card">
            <div class="tf-card__head">
                <div class="tf-card__title">
                    <i class="bi bi-exclamation-octagon text-danger tf-card__icon"></i>
                    <div>
                        <h2>Overdue Tasks</h2>
                        <p class="text-danger">Action required immediately</p>
                    </div>
                </div>
                <span class="badge bg-danger text-white rounded-pill px-2">
                    {{ $overdueTasks->count() }} overdue
                </span>
            </div>

            <div class="tf-overdue-list">
                @foreach ($overdueTasks as $task)
                    <a href="{{ route('tasks.show', $task) }}" class="tf-deadline-card tf-deadline-card--overdue text-decoration-none">
                        <div class="d-flex align-items-center gap-2 min-width-0">
                            <span class="tf-dot tf-dot--critical"></span>
                            <div class="tf-deadline-card__info">
                                <span class="tf-deadline-card__title text-danger">{{ $task->title }}</span>
                                <span class="tf-deadline-card__sub">
                                    <span class="font-monospace">{{ $task->task_code }}</span>
                                    @if ($task->assignee)
                                        · <span>{{ $task->assignee->name }}</span>
                                    @endif
                                </span>
                            </div>
                        </div>
                        <time class="tf-deadline-card__date text-danger">
                            Due {{ $task->due_date ? $task->due_date->format('M d') : 'Past' }}
                        </time>
                    </a>
                @endforeach
            </div>
        </article>
    @endif
</div>

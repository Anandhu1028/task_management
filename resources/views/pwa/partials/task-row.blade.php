@php
    $deadline = $task->due_date ?? $task->original_deadline;
    $assignee = $task->activeAssignees->pluck('name')->implode(', ') ?: ($task->assignee?->name ?? 'Unassigned');
    $progressValue = min(100, max(0, (int) $task->progress_percentage));
    $assigneeInitial = strtoupper(substr(trim($assignee), 0, 1));
    $href = route('pwa.tasks.show', array_merge(['task' => $task], request()->only('search', 'filter', 'page', 'sort')));
@endphp
@if ($compact ?? false)
    <a href="{{ $href }}" class="pwa-row pwa-row--compact {{ ($selected ?? false) ? 'is-selected' : '' }}" @if ($selected ?? false) aria-current="page" @endif>
        <div class="pwa-row__top">
            <span class="pwa-row__title">{{ $task->title }}</span>
            <span class="pwa-row__type">{{ ucfirst($task->task_type ?? '') }}</span>
        </div>
        <div class="pwa-row__badges">@include('pwa.partials.badges', ['task' => $task])</div>
        <div class="pwa-row__meta">
            <span>{{ $assignee }}</span>
            <span class="{{ $task->isOverdue() ? 'is-overdue' : '' }}">{{ $deadline ? 'Due '.$deadline->format('M j, Y') : 'No deadline' }}</span>
        </div>
        <div class="pwa-progress" role="progressbar" aria-valuenow="{{ $progressValue }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progress">
            <span style="width: {{ $progressValue }}%"></span>
        </div>
    </a>
@else
    <a href="{{ $href }}" class="pwa-row pwa-row--list {{ $task->isOverdue() ? 'pwa-row--overdue' : '' }}">
        <span class="pwa-row__identity">
            <span class="pwa-row__title">{{ $task->title }}</span>
            <span class="pwa-row__badges">
                <span class="pwa-badge pwa-badge--type">{{ ucfirst($task->task_type ?? '') }}</span>
                @include('pwa.partials.badges', ['task' => $task])
            </span>
        </span>
        <span class="pwa-row__assignee">
            <span class="pwa-row__avatar" aria-hidden="true">{{ $assigneeInitial }}</span>
            <span class="pwa-row__assignee-name">{{ $assignee }}</span>
        </span>
        <span class="pwa-row__deadline {{ $task->isOverdue() ? 'is-overdue' : '' }}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>
            {{ $deadline ? 'Due '.$deadline->format('M j, Y') : 'No deadline' }}
            <svg class="pwa-row__open" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
        </span>
    </a>
@endif

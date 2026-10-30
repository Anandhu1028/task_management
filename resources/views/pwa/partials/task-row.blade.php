@php
    $deadline = $task->due_date ?? $task->original_deadline;
    $assignee = $task->activeAssignees->pluck('name')->implode(', ') ?: ($task->assignee?->name ?? 'Unassigned');
    $progressValue = min(100, max(0, (int) $task->progress_percentage));
    $href = route('pwa.tasks.show', array_merge(['task' => $task], request()->only('search', 'filter', 'page')));
@endphp
<a href="{{ $href }}" class="pwa-row {{ ($compact ?? false) ? 'pwa-row--compact' : '' }} {{ ($selected ?? false) ? 'is-selected' : '' }}" @if ($selected ?? false) aria-current="page" @endif>
    <div class="pwa-row__top">
        <span class="pwa-row__title">{{ $task->title }}</span>
        <span class="pwa-row__code">{{ $task->task_code }}</span>
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

@php
    $statusLabel = \App\Models\Task::STATUS_LABELS[$task->status] ?? ucwords(str_replace('_', ' ', $task->status));
    $condition = $task->deadline_condition;
@endphp
<span class="pwa-badge pwa-badge--status-{{ $task->status }}">{{ $statusLabel }}</span>
<span class="pwa-badge pwa-badge--prio-{{ $task->priority }}">{{ ucfirst($task->priority) }}</span>
@if ($condition === 'overdue')
    <span class="pwa-badge pwa-badge--overdue">Overdue</span>
@elseif ($condition === 'at_risk')
    <span class="pwa-badge pwa-badge--risk">At risk</span>
@endif

@extends('layouts.pwa')

@section('title', $task->task_code.' · TaskFlow')
@section('body_class', 'pwa--split')

@section('content')
@php
    $deadline = $task->due_date ?? $task->original_deadline;
    $assignees = $task->activeAssignees->pluck('name')->implode(', ') ?: ($task->assignee?->name ?? 'Unassigned');
    $progressValue = min(100, max(0, (int) $progress));
    $listQuery = request()->only('search', 'filter', 'page');
    $tabs = array_filter([
        'details' => 'Details',
        'subtasks' => $task->subtasks->isNotEmpty() ? 'Subtasks ('.$task->subtasks->count().')' : null,
        'timeline' => $task->statusHistory->isNotEmpty() ? 'Timeline' : null,
        'requests' => $extensionRequests->isNotEmpty() ? 'Requests ('.$extensionRequests->count().')' : null,
        'files' => $task->attachments->isNotEmpty() ? 'Files ('.$task->attachments->count().')' : null,
    ]);
    $hasActions = $actions['start'] || $actions['resume'] || $actions['hold'];
@endphp
<div class="pwa-shell">
    {{-- Left panel: task list (hidden on narrow screens) --}}
    <aside class="pwa-side" aria-label="Task list">
        @include('pwa.partials.topbar', ['class' => 'pwa-topbar--side'])
        <div class="pwa-side__body">
            <p class="pwa-side__counts"><strong>{{ number_format($summary['total']) }}</strong> tasks · <strong class="is-overdue">{{ number_format($summary['overdue_count']) }}</strong> overdue</p>
            @include('pwa.partials.list-controls', ['action' => route('pwa.tasks.show', $task)])
            <div class="pwa-side__list">
                @forelse ($tasks as $row)
                    @include('pwa.partials.task-row', ['task' => $row, 'compact' => true, 'selected' => $row->is($task)])
                @empty
                    <p class="pwa-empty">No tasks match this view.</p>
                @endforelse
            </div>
            @include('pwa.partials.pager')
        </div>
    </aside>

    {{-- Right panel: task detail --}}
    <section class="pwa-detail">
        @include('pwa.partials.topbar', ['class' => 'pwa-topbar--narrow'])
        <div class="pwa-detail__body">
            <nav class="pwa-crumbs" aria-label="Breadcrumb">
                <a href="{{ route('pwa.tasks.index', $listQuery) }}">← Tasks</a>
                <span>/</span>
                <span>{{ $task->task_code }}</span>
            </nav>

            @if (session('success'))<div class="pwa-alert pwa-alert--ok" role="status">{{ session('success') }}</div>@endif
            @if ($errors->any())<div class="pwa-alert pwa-alert--err" role="alert">{{ $errors->first() }}</div>@endif

            <h1 class="pwa-detail__title">{{ $task->title }}</h1>
            <div class="pwa-detail__badges">
                @include('pwa.partials.badges', ['task' => $task])
                @if ($task->company)<span class="pwa-tag">{{ $task->company->name }}</span>@endif
                @if ($task->department)<span class="pwa-tag">{{ $task->department->name }}</span>@endif
            </div>

            @if ($hasActions)
                <div class="pwa-actions">
                    @if ($actions['start'])
                        <details class="pwa-action">
                            <summary class="pwa-btn pwa-btn--primary">Start work</summary>
                            <form method="POST" action="{{ route('tasks.start', $task) }}" class="pwa-action__form">
                                @csrf
                                <input type="hidden" name="return_to" value="pwa">
                                <label class="pwa-field"><span>Start date</span><input type="date" name="start_date" value="{{ old('start_date', today()->toDateString()) }}" required></label>
                                <label class="pwa-field"><span>Note</span><textarea name="note" rows="2" maxlength="2000" required>{{ old('note') }}</textarea></label>
                                <button class="pwa-btn pwa-btn--primary" type="submit">Confirm</button>
                            </form>
                        </details>
                    @endif
                    @if ($actions['resume'])
                        <details class="pwa-action">
                            <summary class="pwa-btn pwa-btn--primary">Resume</summary>
                            <form method="POST" action="{{ route('tasks.resume', $task) }}" class="pwa-action__form">
                                @csrf
                                <input type="hidden" name="return_to" value="pwa">
                                <label class="pwa-field"><span>Note (optional)</span><textarea name="note" rows="2" maxlength="2000">{{ old('note') }}</textarea></label>
                                <button class="pwa-btn pwa-btn--primary" type="submit">Resume work</button>
                            </form>
                        </details>
                    @endif
                    @if ($actions['hold'])
                        <details class="pwa-action">
                            <summary class="pwa-btn pwa-btn--ghost">On hold</summary>
                            <form method="POST" action="{{ route('tasks.status.update', $task) }}" class="pwa-action__form">
                                @csrf
                                <input type="hidden" name="return_to" value="pwa">
                                <input type="hidden" name="status" value="on_hold">
                                <label class="pwa-field"><span>Date</span><input type="date" name="update_date" value="{{ today()->toDateString() }}" required></label>
                                <label class="pwa-field"><span>Reason</span><textarea name="reason" rows="2" maxlength="2000" required></textarea></label>
                                <button class="pwa-btn pwa-btn--ghost" type="submit">Put on hold</button>
                            </form>
                        </details>
                    @endif
                </div>
            @endif

            <ol class="pwa-steps" aria-label="Workflow progress">
                @foreach ($stages as $stage)
                    <li class="pwa-step pwa-step--{{ $stage['state'] }}" @if ($stage['state'] === 'current') aria-current="step" @endif>
                        <span class="pwa-step__dot"></span>
                        <span class="pwa-step__label">{{ $stage['label'] }}</span>
                        @if ($stage['date'] && $stage['state'] !== 'upcoming')<span class="pwa-step__date">{{ $stage['date']->format('M j') }}</span>@endif
                    </li>
                @endforeach
            </ol>

            <dl class="pwa-facts">
                <div><dt>Deadline</dt><dd class="{{ $task->isOverdue() ? 'is-overdue' : '' }}">{{ $deadline?->format('M j, Y') ?? 'Not set' }}</dd></div>
                <div><dt>Estimated effort</dt><dd>{{ $task->estimated_hours ? rtrim(rtrim(number_format((float) $task->estimated_hours, 2), '0'), '.').' h' : 'Not set' }}</dd></div>
                <div><dt>Assignee</dt><dd>{{ $assignees }}</dd></div>
                <div><dt>Progress</dt><dd>{{ $progressValue }}%<span class="pwa-progress"><span style="width: {{ $progressValue }}%"></span></span></dd></div>
            </dl>

            @if (count($tabs) > 1)
                <div class="pwa-tabs" role="tablist">
                    @foreach ($tabs as $key => $label)
                        <button type="button" role="tab" class="pwa-tab {{ $loop->first ? 'is-active' : '' }}" data-tab="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
            @endif

            <section class="pwa-panel is-active" data-panel="details">
                <h2>Description</h2>
                <p class="pwa-prose">{{ $task->description ?: 'No description provided.' }}</p>
                @if ($task->expected_output)
                    <h2>Expected output</h2>
                    <p class="pwa-prose">{{ $task->expected_output }}</p>
                @endif
                <h2>Properties</h2>
                <dl class="pwa-props">
                    @foreach ([
                        'Task type' => $task->task_type ? ucfirst($task->task_type) : null,
                        'Project' => $task->project,
                        'Start date' => $task->start_date?->format('M j, Y'),
                        'Original deadline' => $task->original_deadline && $task->due_date && ! $task->original_deadline->eq($task->due_date) ? $task->original_deadline->format('M j, Y') : null,
                        'Created by' => $task->creator?->name,
                        'Points of contact' => $task->pocs->pluck('name')->implode(', ') ?: null,
                    ] as $label => $value)
                        @if (filled($value))<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endif
                    @endforeach
                </dl>
            </section>

            @if (isset($tabs['subtasks']))
                <section class="pwa-panel" data-panel="subtasks">
                    <ul class="pwa-subtasks">
                        @foreach ($task->subtasks as $subtask)
                            <li>
                                <span class="pwa-subtasks__title {{ $subtask->is_completed ? 'is-done' : '' }}">{{ $subtask->title }}</span>
                                <span class="pwa-subtasks__meta">{{ \App\Models\Subtask::STATUS_LABELS[$subtask->status] ?? ucfirst($subtask->status) }}@if ($subtask->assignee) · {{ $subtask->assignee->name }}@endif @if ($subtask->due_date) · Due {{ $subtask->due_date->format('M j') }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (isset($tabs['timeline']))
                <section class="pwa-panel" data-panel="timeline">
                    <ol class="pwa-timeline">
                        @foreach ($task->statusHistory->sortByDesc('id') as $entry)
                            <li>
                                <strong>{{ \App\Models\Task::STATUS_LABELS[$entry->new_status] ?? ucwords(str_replace('_', ' ', (string) $entry->new_status)) }}</strong>
                                <span class="pwa-timeline__meta">{{ $entry->changedBy?->name ?? 'System' }} · {{ $entry->created_at->format('M j, Y g:i A') }}</span>
                                @if (filled($entry->remarks))<p class="pwa-prose">{{ $entry->remarks }}</p>@endif
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            @if (isset($tabs['requests']))
                <section class="pwa-panel" data-panel="requests">
                    <ul class="pwa-subtasks">
                        @foreach ($extensionRequests as $ext)
                            <li>
                                <span class="pwa-subtasks__title">{{ $ext->current_deadline?->format('M j') }} → {{ $ext->requested_deadline?->format('M j, Y') }} <span class="pwa-badge pwa-badge--status-{{ $ext->status === 'approved' ? 'completed' : ($ext->status === 'rejected' ? 'cancelled' : 'on_hold') }}">{{ ucfirst($ext->status) }}</span></span>
                                <span class="pwa-subtasks__meta">{{ $ext->requester?->name }} · {{ $ext->reason }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (isset($tabs['files']))
                <section class="pwa-panel" data-panel="files">
                    <ul class="pwa-subtasks">
                        @foreach ($task->attachments as $file)
                            <li>
                                <a class="pwa-subtasks__title" href="{{ route('tasks.attachments.download', [$task, $file]) }}">{{ $file->original_name }}</a>
                                <span class="pwa-subtasks__meta">{{ $file->uploadedBy?->name }} · {{ number_format($file->size_bytes / 1024, 0) }} KB</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </section>
</div>
@endsection

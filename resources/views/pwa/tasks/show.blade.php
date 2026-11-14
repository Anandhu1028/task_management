@extends('layouts.pwa')

@section('title', $task->task_code.' · TaskFlow')
@section('body_class', 'pwa--split')

@section('content')
@php
    $deadline = $task->due_date ?? $task->original_deadline;
    $assignees = $task->activeAssignees->pluck('name')->implode(', ') ?: ($task->assignee?->name ?? 'Unassigned');
    $progressValue = min(100, max(0, (int) $progress));
    $listQuery = request()->only('search', 'filter', 'page', 'sort');
    $tabs = array_filter([
        'details' => 'Details',
        'subtasks' => $task->subtasks->isNotEmpty() ? 'Subtasks ('.$task->subtasks->count().')' : null,
        'timeline' => $task->statusHistory->isNotEmpty() ? 'Timeline' : null,
        'requests' => $extensionRequests->isNotEmpty() ? 'Requests ('.$extensionRequests->count().')' : null,
        'files' => $task->attachments->isNotEmpty() ? 'Files ('.$task->attachments->count().')' : null,
    ]);
    $selectedAssigneeIds = array_map('strval', old('assigned_to', $task->activeAssignees->modelKeys()));
    $hasActions = in_array(true, $actions, true);
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
            <header class="pwa-detail__header">
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
                    <div class="pwa-actions tf-task-workflow__action-bar">
                        @if ($actions['start'])
                            <details class="pwa-action">
                                <summary class="pwa-btn pwa-btn--primary">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 4 14 8-14 8z"/></svg>
                                    Start work
                                </summary>
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
                                <summary class="pwa-btn pwa-btn--primary">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 7v5h-5"/><path d="M3 17v-5h5"/><path d="M5.6 9A7 7 0 0 1 17 6l4 6M3 12l4 6a7 7 0 0 0 11.4-3"/></svg>
                                    Resume
                                </summary>
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
                                <summary class="pwa-btn pwa-btn--outline">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9 8v8M15 8v8"/></svg>
                                    On Hold
                                </summary>
                                <form method="POST" action="{{ route('tasks.status.update', $task) }}" class="pwa-action__form">
                                    @csrf
                                    <input type="hidden" name="return_to" value="pwa">
                                    <input type="hidden" name="status" value="on_hold">
                                    <label class="pwa-field"><span>Date</span><input type="date" name="update_date" value="{{ today()->toDateString() }}" required></label>
                                    <label class="pwa-field"><span>Reason</span><textarea name="reason" rows="2" maxlength="2000" required></textarea></label>
                                    <button class="pwa-btn pwa-btn--outline" type="submit">Put on hold</button>
                                </form>
                            </details>
                        @endif
                        @if ($actions['cancel'])
                            <details class="pwa-action">
                                <summary class="pwa-btn pwa-btn--outline">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6m0-6 6 6"/></svg>
                                    Cancel Task
                                </summary>
                                <form method="POST" action="{{ route('tasks.status.update', $task) }}" class="pwa-action__form">
                                    @csrf
                                    <input type="hidden" name="return_to" value="pwa">
                                    <input type="hidden" name="status" value="cancelled">
                                    <label class="pwa-field"><span>Reason</span><textarea name="reason" rows="2" maxlength="2000" required></textarea></label>
                                    <button class="pwa-btn pwa-btn--primary" type="submit">Confirm cancellation</button>
                                </form>
                            </details>
                        @endif
                        @if ($actions['reassign'])
                            <details class="pwa-action">
                                <summary class="pwa-btn pwa-btn--outline">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3 21 7l-4 4"/><path d="M3 7h18"/><path d="m7 21-4-4 4-4"/><path d="M21 17H3"/></svg>
                                    Re-assign
                                </summary>
                                <form method="POST" action="{{ route('tasks.reassign', $task) }}" class="pwa-action__form">
                                    @csrf
                                    <input type="hidden" name="company_id" value="{{ $task->company_id }}">
                                    <input type="hidden" name="department_id" value="{{ $task->department_id }}">
                                    <label class="pwa-field" for="pwa-reassign-assignees">
                                        <span>Assigned employees</span>
                                        <select id="pwa-reassign-assignees" name="assigned_to[]" multiple required size="{{ min(5, max(2, $employees->count())) }}">
                                            @foreach ($employees as $employee)
                                                <option value="{{ $employee->id }}" @selected(in_array((string) $employee->id, $selectedAssigneeIds, true))>
                                                    {{ $employee->name }}@if ($employee->designation) · {{ $employee->designation }}@endif
                                                </option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="pwa-field"><span>Reason (optional)</span><textarea name="reason" rows="2" maxlength="500"></textarea></label>
                                    <button class="pwa-btn pwa-btn--outline" type="submit">Save assignment</button>
                                </form>
                            </details>
                        @endif
                        @if ($actions['edit'])
                            <a class="pwa-btn pwa-btn--primary" href="{{ route('tasks.index', ['edit' => $task->id]) }}">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>
                                Edit Task
                            </a>
                        @endif
                    </div>
                @endif
            </header>

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
                @if ($task->task_type === 'project')
                    @php
                        $projectPhases = $task->milestones->values();
                        $completedProjectPhases = $projectPhases->where('status', 'completed')->count();
                    @endphp
                    <h2>Project phases ({{ $completedProjectPhases }}/{{ $projectPhases->count() }} completed)</h2>
                    @if ($projectPhases->isNotEmpty())
                        <ol class="pwa-subtasks">
                            @foreach ($projectPhases as $phase)
                                <li>
                                    <span class="pwa-subtasks__title">{{ $phase->title }}</span>
                                    @if ($phase->description)
                                        <span class="pwa-subtasks__meta">{{ $phase->description }}</span>
                                    @endif
                                    <span class="pwa-subtasks__meta">
                                        Date: {{ $phase->milestone_date->format('M j, Y') }}
                                        · {{ \App\Models\Milestone::STATUS_LABELS[$phase->status] ?? ucfirst($phase->status) }}
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="pwa-prose">No phases have been added to this project.</p>
                    @endif
                @endif
            </section>

            @if (isset($tabs['subtasks']))
                <section class="pwa-panel" data-panel="subtasks">
                    <ul class="pwa-subtasks">
                        @foreach ($task->subtasks as $subtask)
                            <li>
                                <span class="pwa-subtasks__title {{ $subtask->is_completed ? 'is-done' : '' }}">{{ $subtask->title }}</span>
                                @if ($subtask->description)
                                    <span class="pwa-subtasks__meta">{{ $subtask->description }}</span>
                                @endif
                                <span class="pwa-subtasks__meta">{{ \App\Models\Subtask::STATUS_LABELS[$subtask->status] ?? ucfirst($subtask->status) }}@if ($subtask->assignee) · {{ $subtask->assignee->name }}@endif</span>
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

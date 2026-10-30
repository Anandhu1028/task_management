@extends('layouts.app')

@section('title', $dailyTask->title)

@section('content')
    <section class="tf-page">
        <header class="d-flex flex-wrap justify-content-between gap-3 mb-4">
            <div>
                <a href="{{ route('daily-tasks.index') }}" class="text-decoration-none">&larr; Daily Tasks</a>
                <h1 class="h3 mt-2 mb-1">{{ $dailyTask->title }}</h1>
                <p class="text-secondary mb-0">
                    <a href="{{ route('tasks.show', $dailyTask->task) }}">{{ $dailyTask->task->task_code }} · {{ $dailyTask->task->title }}</a>
                </p>
            </div>
            <div class="text-end">
                <span class="badge text-bg-secondary">{{ \App\Models\DailyTask::STATUS_LABELS[$dailyTask->status] ?? ucfirst($dailyTask->status) }}</span>
                <div class="small text-secondary mt-2">{{ $dailyTask->work_date?->format('M j, Y') }}</div>
            </div>
        </header>

        @if (session('success'))
            <div class="alert alert-success" role="status">{{ session('success') }}</div>
        @endif
        @if (session('overlap_warning'))
            <div class="alert alert-warning" role="alert">This work schedule overlaps another Daily Task assigned to the employee.</div>
        @endif
        @if ($overlaps->isNotEmpty())
            <div class="alert alert-warning" role="alert">
                <strong>Schedule overlap</strong>
                <ul class="mb-0">@foreach ($overlaps as $overlap)<li>{{ $overlap->title }} · {{ $overlap->formatTime($overlap->planned_start_time) }}–{{ $overlap->formatTime($overlap->planned_end_time) }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="row g-4">
            <div class="col-lg-7">
                <article class="card mb-4"><div class="card-body">
                    <h2 class="h5">Work details</h2>
                    <p>{{ $dailyTask->description ?: 'No additional work details.' }}</p>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Employee</dt><dd class="col-sm-8">{{ $dailyTask->assignee?->name ?? 'Former employee' }}</dd>
                        <dt class="col-sm-4">Department</dt><dd class="col-sm-8">{{ $dailyTask->task->department?->name ?? '—' }}</dd>
                        <dt class="col-sm-4">Subtask</dt><dd class="col-sm-8">{{ $dailyTask->subtask?->title ?? '—' }}</dd>
                        <dt class="col-sm-4">Milestone</dt><dd class="col-sm-8">{{ $dailyTask->milestone?->title ?? '—' }}</dd>
                        <dt class="col-sm-4">Planned time</dt><dd class="col-sm-8">{{ $dailyTask->formatTime($dailyTask->planned_start_time) }}–{{ $dailyTask->formatTime($dailyTask->planned_end_time) }}</dd>
                        <dt class="col-sm-4">Priority</dt><dd class="col-sm-8">{{ $dailyTask->priority_label }}</dd>
                        <dt class="col-sm-4">Estimated duration</dt><dd class="col-sm-8">{{ $dailyTask->estimated_minutes ? $dailyTask->estimated_minutes.' minutes' : '—' }}</dd>
                        <dt class="col-sm-4">Actual start</dt><dd class="col-sm-8">{{ $dailyTask->actual_started_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') ?? '—' }}</dd>
                        <dt class="col-sm-4">Actual end</dt><dd class="col-sm-8">{{ $dailyTask->actual_ended_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') ?? '—' }}</dd>
                        <dt class="col-sm-4">Progress</dt><dd class="col-sm-8">{{ $dailyTask->progress_percentage }}%</dd>
                    </dl>
                </div></article>

                @if ($dailyTask->operational_metrics)
                    <article class="card mb-4"><div class="card-body">
                        <h2 class="h5">Employee-reported operational metrics</h2>
                        <p class="small text-secondary">Self-reported values; not verified financial transaction results.</p>
                        <dl class="row mb-0">
                            @foreach ($dailyTask->operational_metrics as $key => $value)
                                <dt class="col-sm-7">{{ $metricDefinitions[$key] ?? \Illuminate\Support\Str::headline($key) }}</dt>
                                <dd class="col-sm-5">{{ $value }}</dd>
                            @endforeach
                        </dl>
                    </div></article>
                @endif

                @if ($dailyTask->remarks || $dailyTask->blockers || $dailyTask->completion_remarks)
                    <article class="card mb-4"><div class="card-body">
                        <h2 class="h5">Remarks and blockers</h2>
                        @if ($dailyTask->remarks)<p class="mb-2"><strong>Remarks:</strong> {{ $dailyTask->remarks }}</p>@endif
                        @if ($dailyTask->blockers)<p class="mb-2"><strong>Blockers:</strong> {{ $dailyTask->blockers }}</p>@endif
                        @if ($dailyTask->completion_remarks)<p class="mb-0"><strong>Completion:</strong> {{ $dailyTask->completion_remarks }}</p>@endif
                    </div></article>
                @endif

                @if ($canUpdate)
                    <article class="card"><div class="card-body">
                        <h2 class="h5">Work actions</h2>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            @if (in_array($dailyTask->status, ['planned', 'pending', 'missed'], true))
                                <form method="POST" action="{{ route('daily-tasks.action', [$dailyTask->task, $dailyTask, 'start']) }}">@csrf
                                    <button class="btn btn-primary" type="submit">Start Work</button>
                                </form>
                            @endif
                            @if ($dailyTask->status === 'in_progress')
                                <form method="POST" action="{{ route('daily-tasks.action', [$dailyTask->task, $dailyTask, 'hold']) }}">@csrf
                                    <button class="btn btn-outline-warning" type="submit">On Hold</button>
                                </form>
                            @endif
                            @if ($dailyTask->status === 'on_hold')
                                <form method="POST" action="{{ route('daily-tasks.action', [$dailyTask->task, $dailyTask, 'resume']) }}">@csrf
                                    <button class="btn btn-primary" type="submit">Resume Work</button>
                                </form>
                            @endif
                            @if (in_array($dailyTask->status, ['in_progress', 'on_hold'], true))
                                <form method="POST" action="{{ route('daily-tasks.action', [$dailyTask->task, $dailyTask, 'complete']) }}">@csrf
                                    <button class="btn btn-success" type="submit">Complete Daily Task</button>
                                </form>
                            @endif
                            @if (! in_array($dailyTask->status, ['completed', 'cancelled'], true))
                                <form method="POST" action="{{ route('daily-tasks.action', [$dailyTask->task, $dailyTask, 'cancel']) }}">@csrf
                                    <button class="btn btn-outline-danger" type="submit">Cancel</button>
                                </form>
                            @endif
                        </div>
                        @if (in_array($dailyTask->status, ['in_progress', 'on_hold'], true))
                            <form method="POST" action="{{ route('daily-tasks.action', [$dailyTask->task, $dailyTask, 'progress']) }}" class="row g-2 align-items-end mb-3">
                            @csrf
                            <div class="col-sm-3"><label class="form-label" for="progressPercentage">Progress (%)</label>
                                <input class="form-control" id="progressPercentage" name="progress_percentage" type="number" min="0" max="100" value="{{ $dailyTask->progress_percentage }}" required>
                            </div>
                            <div class="col-sm-6"><label class="form-label" for="progressRemarks">Progress note</label>
                                <input class="form-control" id="progressRemarks" name="remarks" maxlength="5000">
                            </div>
                            <div class="col-sm-3"><button class="btn btn-outline-primary w-100" type="submit">Update progress</button></div>
                            </form>
                        @endif
                        @if (in_array($dailyTask->status, ['in_progress', 'on_hold'], true))
                            <form method="POST" action="{{ route('daily-tasks.action', [$dailyTask->task, $dailyTask, 'update']) }}" class="row g-2">
                            @csrf
                            <div class="col-12"><label class="form-label" for="dailyTaskUpdateRemarks">Daily update / remarks</label>
                                <textarea class="form-control" id="dailyTaskUpdateRemarks" name="remarks" rows="2" maxlength="5000"></textarea>
                            </div>
                            <div class="col-12"><label class="form-label" for="dailyTaskUpdateBlockers">Blockers</label>
                                <textarea class="form-control" id="dailyTaskUpdateBlockers" name="blockers" rows="2" maxlength="5000"></textarea>
                            </div>
                            <div class="col-12"><button class="btn btn-outline-primary" type="submit">Submit Daily Update</button></div>
                            </form>
                        @endif
                        @if ($canManage && ! in_array($dailyTask->status, ['completed', 'cancelled'], true))
                            <hr>
                            <h3 class="h6">Reassign / reschedule</h3>
                            <form method="POST" action="{{ route('daily-tasks.action', [$dailyTask->task, $dailyTask, 'reassign']) }}" class="row g-2 mb-3">
                                @csrf
                                <div class="col-md-8">
                                    <label class="form-label" for="dailyTaskNewAssignee">Employee</label>
                                    <select class="form-select" id="dailyTaskNewAssignee" name="assigned_to" required>
                                        @foreach ($dailyTask->task->department?->users()->where('status', 'active')->orderBy('name')->get() ?? [] as $employee)
                                            <option value="{{ $employee->id }}" @selected($dailyTask->assigned_to === $employee->id)>{{ $employee->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex align-items-end"><button class="btn btn-outline-primary w-100" type="submit">Reassign</button></div>
                            </form>
                            <form method="POST" action="{{ route('daily-tasks.action', [$dailyTask->task, $dailyTask, 'reschedule']) }}" class="row g-2">
                                @csrf
                                <div class="col-md-4"><label class="form-label" for="dailyTaskNewDate">Work date</label>
                                    <input class="form-control" type="date" id="dailyTaskNewDate" name="work_date" value="{{ $dailyTask->work_date->toDateString() }}" required>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="dailyTaskNewStart">Start</label>
                                    <input class="form-control" type="time" id="dailyTaskNewStart" name="planned_start_time" value="{{ $dailyTask->formatTime($dailyTask->planned_start_time) }}" required>
                                </div>
                                <div class="col-md-4"><label class="form-label" for="dailyTaskNewEnd">End</label>
                                    <input class="form-control" type="time" id="dailyTaskNewEnd" name="planned_end_time" value="{{ $dailyTask->formatTime($dailyTask->planned_end_time) }}" required>
                                </div>
                                <div class="col-12"><button class="btn btn-outline-secondary" type="submit">Reschedule</button></div>
                            </form>
                        @endif
                    </div></article>
                @endif
            </div>

            <div class="col-lg-5">
                <article class="card"><div class="card-body">
                    <h2 class="h5">Daily Task timeline</h2>
                    @forelse ($dailyTask->activity->sortBy('created_at') as $history)
                        <div class="border-start border-2 ps-3 pb-3 ms-1">
                            <div class="fw-semibold">{{ \Illuminate\Support\Str::headline($history->event_type) }}</div>
                            <div>{{ $history->remarks ?: $history->new_status }}</div>
                            <div class="small text-secondary">
                                {{ $history->changedBy?->name ?? 'System' }} ·
                                <time datetime="{{ $history->created_at?->toIso8601String() }}" title="{{ $history->created_at?->toDateTimeString() }}">
                                    {{ $history->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                                </time>
                            </div>
                            @foreach ($history->attachments as $attachment)
                                <a class="small d-block" href="{{ route('tasks.attachments.download', [$dailyTask->task, $attachment]) }}">{{ $attachment->original_name }}</a>
                            @endforeach
                        </div>
                    @empty
                        <p class="text-secondary mb-0">No activity recorded yet.</p>
                    @endforelse
                </div></article>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('form[action*="/cancel"]').forEach(form => {
            form.addEventListener('submit', event => {
                if (!window.confirm('Cancel this Daily Task?')) {
                    event.preventDefault();
                }
            });
        });
    </script>
@endpush

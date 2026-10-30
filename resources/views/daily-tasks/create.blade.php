@extends('layouts.app')

@section('title', 'Add Daily Task')

@section('content')
    <section class="tf-page">
        <header class="mb-4">
            <a href="{{ route('tasks.show', $task) }}{{ $returnToTask ? '#panel-daily-tasks' : '' }}" class="text-decoration-none">&larr; {{ $task->task_code }}</a>
            <h1 class="h3 mt-2 mb-1">Add Daily Task</h1>
            <p class="text-secondary mb-0">{{ $task->title }}</p>
        </header>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('daily-tasks.store', $task) }}" enctype="multipart/form-data" class="card card-body">
            @csrf
            @if ($returnToTask)
                <input type="hidden" name="return_to_task" value="1">
            @endif
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="dailyTaskTitle">Daily Task Title</label>
                    <input class="form-control" id="dailyTaskTitle" name="title" value="{{ old('title') }}" maxlength="500" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="dailyTaskDescription">Description / Work Details</label>
                    <textarea class="form-control" id="dailyTaskDescription" name="description" rows="3" maxlength="5000">{{ old('description') }}</textarea>
                    <div class="form-text">Do not include customer account numbers, credentials, or unnecessary financial data.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Main Task</label>
                    <input class="form-control" value="{{ $task->task_code }} · {{ $task->title }}" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="dailyTaskSubtask">Subtask</label>
                    <select class="form-select" id="dailyTaskSubtask" name="subtask_id">
                        <option value="">No subtask</option>
                        @foreach ($task->subtasks as $subtask)
                            <option value="{{ $subtask->id }}" @selected((string) old('subtask_id') === (string) $subtask->id)>{{ $subtask->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="dailyTaskMilestone">Milestone</label>
                    <select class="form-select" id="dailyTaskMilestone" name="milestone_id">
                        <option value="">No milestone</option>
                        @foreach ($task->milestones as $milestone)
                            <option value="{{ $milestone->id }}" @selected((string) old('milestone_id') === (string) $milestone->id)>
                                {{ $milestone->title }} · {{ $milestone->target_date->format('M j, Y') }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="dailyTaskAssignee">Employee</label>
                    @if (auth()->user()->can('daily_tasks.create_for_others'))
                        <select class="form-select" id="dailyTaskAssignee" name="assigned_to" required>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected((string) old('assigned_to', auth()->id()) === (string) $employee->id)>
                                    {{ $employee->name }}
                                </option>
                            @endforeach
                        </select>
                    @else
                        <input class="form-control" value="{{ auth()->user()->name }}" disabled>
                        <input type="hidden" name="assigned_to" value="{{ auth()->id() }}">
                    @endif
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="dailyTaskWorkDate">Work Date</label>
                    <input class="form-control" type="date" id="dailyTaskWorkDate" name="work_date"
                        value="{{ old('work_date', today()->toDateString()) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="dailyTaskStart">Planned Start</label>
                    <input class="form-control" type="time" id="dailyTaskStart" name="planned_start_time"
                        value="{{ old('planned_start_time') }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="dailyTaskEnd">Planned End</label>
                    <input class="form-control" type="time" id="dailyTaskEnd" name="planned_end_time"
                        value="{{ old('planned_end_time') }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="dailyTaskPriority">Priority</label>
                    <select class="form-select" id="dailyTaskPriority" name="priority" required>
                        @foreach (\App\Models\DailyTask::PRIORITIES as $priority)
                            <option value="{{ $priority }}" @selected(old('priority', 'medium') === $priority)>
                                {{ \App\Models\DailyTask::PRIORITY_LABELS[$priority] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="dailyTaskEstimate">Estimated Duration (minutes)</label>
                    <input class="form-control" type="number" id="dailyTaskEstimate" name="estimated_minutes"
                        min="1" max="1440" value="{{ old('estimated_minutes') }}">
                </div>
                @if ($metricDefinitions !== [])
                    <fieldset class="col-12">
                        <legend class="h6">Operational target and initial metrics</legend>
                        <p class="form-text">Employee-reported counts only; these are not verified transaction records.</p>
                        <div class="row g-3">
                            @foreach ($metricDefinitions as $key => $label)
                                <div class="col-md-4">
                                    <label class="form-label" for="metric-{{ $key }}">{{ $label }}</label>
                                    <input class="form-control" type="number" min="0" step="1"
                                        id="metric-{{ $key }}" name="operational_metrics[{{ $key }}]"
                                        value="{{ old('operational_metrics.'.$key) }}">
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
                <div class="col-12">
                    <label class="form-label" for="dailyTaskRemarks">Remarks</label>
                    <textarea class="form-control" id="dailyTaskRemarks" name="remarks" rows="2" maxlength="5000">{{ old('remarks') }}</textarea>
                </div>
                <div class="col-12">
                    <label class="form-label" for="dailyTaskAttachments">Attachments</label>
                    <input class="form-control" type="file" id="dailyTaskAttachments" name="attachments[]" multiple
                        accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png,.webp">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Create Daily Task</button>
                    <a class="btn btn-outline-secondary" href="{{ route('tasks.show', $task) }}{{ $returnToTask ? '#panel-daily-tasks' : '' }}">Cancel</a>
                </div>
            </div>
        </form>
    </section>
@endsection

@props(['task'])

@php
    $createPhaseFormId = 'milestone-timeline-create-'.$task->getKey();
    $updateUrlTemplate = route('milestones.update', ['task' => $task, 'milestone' => '__MILESTONE__']);
    $initialMilestones = $task->milestones;
    $initialTotal = $initialMilestones->count();
    $initialCompleted = $initialMilestones->where('status', 'completed')->count();
    $initialProgress = $initialTotal > 0 ? (int) round($initialCompleted / $initialTotal * 100) : 0;
    $initiallyEmpty = $initialTotal === 0;
@endphp

<section
    class="tf-task-detail__card task-overview-project-phases milestone-timeline"
    aria-labelledby="milestone-timeline-title"
    data-milestone-timeline
    data-timeline-url="{{ route('tasks.timeline', $task) }}"
    data-update-url-template="{{ $updateUrlTemplate }}"
    data-timeline-today="{{ today(config('app.timezone'))->toDateString() }}"
>
    <header class="milestone-timeline__header">
        <div>
            <h2 id="milestone-timeline-title">Project timeline</h2>
            <p>Track each phase, its milestone date and activity</p>
        </div>
        <span class="milestone-timeline__summary" data-timeline-summary>{{ $initialCompleted }} of {{ $initialTotal }} completed</span>
    </header>

    <div class="milestone-timeline__toolbar">
        <div class="milestone-timeline__zoom-controls" role="group" aria-label="Timeline controls">
            <button type="button" class="milestone-timeline__today" data-timeline-today>Today</button>
            <button type="button" class="milestone-timeline__zoom-button" data-timeline-zoom-out aria-label="Zoom out">−</button>
            <span class="milestone-timeline__zoom-label" data-timeline-zoom-label>100%</span>
            <button type="button" class="milestone-timeline__zoom-button" data-timeline-zoom-in aria-label="Zoom in">+</button>
        </div>
        <div class="milestone-timeline__activity-legend" aria-label="Activity legend">
            <span class="milestone-timeline__legend-item"><i class="is-created" aria-hidden="true"></i>Created</span>
            <span class="milestone-timeline__legend-item"><i class="is-updated" aria-hidden="true"></i>Updated</span>
            <span class="milestone-timeline__legend-item"><i class="is-completed" aria-hidden="true"></i>Completed</span>
            <span class="milestone-timeline__legend-item"><i class="is-pending" aria-hidden="true"></i>Pending</span>
            <span class="milestone-timeline__legend-item"><i class="is-in-progress" aria-hidden="true"></i>In progress</span>
        </div>
    </div>

    <div
        class="milestone-timeline__progress"
        role="progressbar"
        aria-label="Project phase completion"
        aria-valuenow="{{ $initialProgress }}"
        aria-valuemin="0"
        aria-valuemax="100"
        data-timeline-progress
    >
        <span data-timeline-progress-fill style="width: {{ $initialProgress }}%"></span>
    </div>

    <p class="milestone-timeline__error" role="alert" data-timeline-error hidden></p>
    <p class="milestone-timeline__loading" role="status" data-timeline-loading @if ($initiallyEmpty) hidden @endif>Loading project timeline...</p>

    <div class="milestone-timeline__scroll" role="region" aria-label="Project milestone timeline" tabindex="0" data-timeline-scroll hidden>
        <div data-timeline-content></div>
    </div>

    <div class="milestone-timeline__empty" data-timeline-empty @if (! $initiallyEmpty) hidden @endif>
        <strong>No phases yet. Add a phase to see it on the timeline.</strong>
        @can('createMilestone', $task)
            <button
                type="button"
                class="milestone-timeline__add-button"
                aria-expanded="false"
                aria-controls="{{ $createPhaseFormId }}"
                data-timeline-add-phase
            >Add phase</button>
            <form
                id="{{ $createPhaseFormId }}"
                class="milestone-timeline__create-form"
                method="POST"
                action="{{ route('milestones.store', $task) }}"
                data-timeline-create-form
                hidden
            >
                @csrf
                <label>
                    <span>Phase name</span>
                    <input type="text" name="title" maxlength="255" required>
                </label>
                <label>
                    <span>Milestone date (optional)</span>
                    <input type="date" name="milestone_date">
                </label>
                <label>
                    <span>Description</span>
                    <textarea name="description" rows="2"></textarea>
                </label>
                <button type="submit" class="milestone-timeline__add-button">Create phase</button>
            </form>
        @endcan
    </div>

    <footer class="milestone-timeline__footer">
        <span data-timeline-footer-count>{{ $initialCompleted }} of {{ $initialTotal }} phases complete</span>
        <span data-timeline-footer-progress>{{ $initialProgress }}% progress</span>
    </footer>
</section>

@once
    @push('scripts')
        <script src="{{ asset('js/milestone-timeline.js') }}?v={{ filemtime(public_path('js/milestone-timeline.js')) }}"></script>
    @endpush
@endonce

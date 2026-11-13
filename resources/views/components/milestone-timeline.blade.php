@props(['phases', 'task'])

@php
    $timelinePhases = collect($phases)->map(function (\App\Models\Milestone $phase) use ($task): array {
        $endDate = $phase->getAttribute('end_date') ?? $phase->getAttribute('target_date');
        $startDate = $phase->getAttribute('start_date') ?? $phase->getAttribute('created_at') ?? $endDate;
        $formatDate = static fn (mixed $date): ?string => $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : ($date ? \Illuminate\Support\Carbon::parse($date)->format('Y-m-d') : null);
        $startDateValue = $formatDate($startDate);
        $endDateValue = $formatDate($endDate);
        $createdDateValue = $formatDate($phase->getAttribute('created_at'));
        $completedAtValue = $formatDate($phase->getAttribute('completed_at'));
        $creationActivity = $phase->activity->firstWhere('event_type', 'milestone_created');
        $creatorName = $phase->creator?->name ?? 'Unknown user';
        $activityEvents = $phase->activity
            ->filter(fn (\App\Models\TaskStatusHistory $activity): bool => in_array(
                $activity->event_type,
                ['milestone_created', 'milestone_updated', 'milestone_status'],
                true,
            ))
            ->sortBy('created_at')
            ->map(function (\App\Models\TaskStatusHistory $activity) use ($formatDate, $creatorName): array {
                $formatStatus = static fn (?string $status): ?string => match ($status) {
                    null => null,
                    'completed' => 'Completed',
                    'pending' => 'Pending',
                    'cancelled' => 'Cancel',
                    'in_progress' => 'In progress',
                    default => ucfirst(str_replace('_', ' ', $status)),
                };
                $eventAt = $activity->created_at?->timezone(config('app.timezone'));

                return [
                    'id' => $activity->getKey(),
                    'type' => $activity->event_type,
                    'status' => $activity->new_status,
                    'status_label' => $formatStatus($activity->new_status),
                    'old_status_label' => $formatStatus($activity->old_status),
                    'label' => match ($activity->event_type) {
                        'milestone_created' => 'Milestone created',
                        'milestone_updated' => 'Milestone updated',
                        'milestone_status' => 'Status updated to: '.$formatStatus($activity->new_status),
                    },
                    'date' => $formatDate($eventAt),
                    'time' => $eventAt?->format('g:i A') ?? '',
                    'actor' => $activity->changedBy?->name ?? $creatorName,
                    'remarks' => $activity->remarks ?? '',
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $phase->getKey(),
            'name' => $phase->getAttribute('name') ?? $phase->getAttribute('title'),
            'description' => $phase->getAttribute('description') ?? '',
            'start_date' => $startDateValue,
            'end_date' => $endDateValue,
            'created_date' => $createdDateValue,
            'creator' => $creatorName,
            'activity_actor' => $creationActivity?->changedBy?->name ?? $creatorName,
            'activity_text' => 'created the milestone',
            'activity_at' => $creationActivity?->created_at
                ?->timezone(config('app.timezone'))
                ->format('M j, Y g:i A') ?? '',
            'activity_events' => $activityEvents,
            'completed_at' => $completedAtValue,
            'is_milestone' => (bool) ($phase->getAttribute('is_milestone') ?? $startDateValue === $endDateValue),
            'status' => $phase->getAttribute('status'),
            'progress_percentage' => $phase->getAttribute('progress_percentage') ?? 0,
            'update_url' => route('milestones.update', [$task, $phase]),
            'can_update' => auth()->user()?->can('updateMilestone', [$task, $phase]) ?? false,
        ];
    })->values();
    $completedTimelinePhases = $timelinePhases->where('status', 'completed')->count();
    $timelineProgress = $timelinePhases->isNotEmpty()
        ? (int) round($completedTimelinePhases / $timelinePhases->count() * 100)
        : 0;
    $createPhaseFormId = 'milestone-timeline-create-'.$task->getKey();
@endphp

<section class="tf-task-detail__card task-overview-project-phases milestone-timeline"
    aria-labelledby="milestone-timeline-title" data-milestone-timeline>
    <header class="milestone-timeline__header">
        <div>
            <h2 id="milestone-timeline-title">Project timeline</h2>
            <p>Track each phase, its dates and deadline</p>
        </div>
        <span class="milestone-timeline__summary" data-timeline-summary>
            {{ $completedTimelinePhases }} of {{ $timelinePhases->count() }} completed
        </span>
    </header>

    <div class="milestone-timeline__toolbar" role="group" aria-label="Timeline controls">
        <div class="milestone-timeline__zoom-controls">
            <button type="button" class="milestone-timeline__today" data-timeline-today>Today</button>
            <button type="button" class="milestone-timeline__zoom-button" data-timeline-zoom-out aria-label="Zoom out">−</button>
            <span class="milestone-timeline__zoom-label" data-timeline-zoom-label>100%</span>
            <button type="button" class="milestone-timeline__zoom-button" data-timeline-zoom-in aria-label="Zoom in">+</button>
        </div>
    </div>

    <p class="milestone-timeline__error" role="alert" data-timeline-error hidden></p>

    @if ($timelinePhases->isNotEmpty())
        <div class="milestone-timeline__scroll" role="region" aria-label="Project phase Gantt timeline"
            tabindex="0" data-timeline-scroll>
            <div data-timeline-content></div>
        </div>
        <div class="milestone-timeline__phase-source" aria-hidden="true" hidden>
            @foreach ($timelinePhases as $phase)
                <span
                    data-timeline-phase
                    data-phase-id="{{ $phase['id'] }}"
                    data-phase-name="{{ $phase['name'] }}"
                    data-phase-description="{{ $phase['description'] }}"
                    data-phase-start-date="{{ $phase['start_date'] }}"
                    data-phase-end-date="{{ $phase['end_date'] }}"
                    data-phase-created-date="{{ $phase['created_date'] }}"
                    data-phase-creator="{{ $phase['creator'] }}"
                    data-phase-activity-actor="{{ $phase['activity_actor'] }}"
                    data-phase-activity-text="{{ $phase['activity_text'] }}"
                    data-phase-activity-at="{{ $phase['activity_at'] }}"
                    data-phase-activity-events="{{ json_encode($phase['activity_events']) }}"
                    data-phase-completed-at="{{ $phase['completed_at'] }}"
                    data-phase-is-milestone="{{ $phase['is_milestone'] ? 'true' : 'false' }}"
                    data-phase-status="{{ $phase['status'] }}"
                    data-phase-progress="{{ $phase['progress_percentage'] }}"
                    data-phase-update-url="{{ $phase['update_url'] }}"
                    data-phase-can-update="{{ $phase['can_update'] ? 'true' : 'false' }}"
                ></span>
            @endforeach
        </div>
    @else
        <div class="milestone-timeline__empty">
            <strong>No phases yet. Add a phase to see it on the timeline.</strong>
            @can('createMilestone', $task)
                <button type="button" class="milestone-timeline__add-button"
                    aria-expanded="false" aria-controls="{{ $createPhaseFormId }}" data-timeline-add-phase>
                    Add phase
                </button>
                <form id="{{ $createPhaseFormId }}" class="milestone-timeline__create-form"
                    method="POST" action="{{ route('milestones.store', $task) }}" hidden>
                    @csrf
                    <label>
                        <span>Phase name</span>
                        <input type="text" name="title" maxlength="500" required>
                    </label>
                    <label>
                        <span>Deadline</span>
                        <input type="date" name="target_date" required>
                    </label>
                    <label>
                        <span>Description</span>
                        <textarea name="description" rows="2"></textarea>
                    </label>
                    <button type="submit" class="milestone-timeline__add-button">Create phase</button>
                </form>
            @else
                <button type="button" class="milestone-timeline__add-button" disabled>Add phase</button>
            @endcan
        </div>
    @endif

    <footer class="milestone-timeline__footer">
        <span data-timeline-footer-count>{{ $completedTimelinePhases }} of {{ $timelinePhases->count() }} phases complete</span>
        <span data-timeline-footer-progress>{{ $timelineProgress }}% progress</span>
    </footer>
</section>

@once
    @push('scripts')
        <script src="{{ asset('js/milestone-timeline.js') }}?v={{ filemtime(public_path('js/milestone-timeline.js')) }}"></script>
    @endpush
@endonce

@php
    $projectPhases = $milestones->sortBy('sort_order')->values();
    $completedProjectPhases = $projectPhases->where('status', 'completed')->count();
    $projectPhaseProgress = $projectPhases->isNotEmpty()
        ? (int) round($completedProjectPhases / $projectPhases->count() * 100)
        : 0;
@endphp

<section class="tf-task-detail__card task-overview-project-phases" aria-labelledby="task-project-phases-title">
    <header class="tf-task-detail__card-header task-overview-project-phases__header">
        <div class="task-overview-project-phases__heading">
            <span class="task-overview-project-phases__icon" aria-hidden="true">
                <i class="bi bi-layers"></i>
            </span>
            <div>
                <h2 id="task-project-phases-title">Project phases</h2>
                <p>Track each phase and its deadline</p>
            </div>
        </div>
        @if ($projectPhases->isNotEmpty())
            <span class="task-overview-project-phases__summary">
                <strong>{{ $completedProjectPhases }}</strong>
                <span>of {{ $projectPhases->count() }} completed</span>
            </span>
        @endif
    </header>
    @if ($projectPhases->isNotEmpty())
        <div class="task-overview-project-phases__progress" role="progressbar"
            aria-label="Project phase completion"
            aria-valuenow="{{ $projectPhaseProgress }}" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: {{ $projectPhaseProgress }}%"></div>
        </div>
        <ol class="task-overview-project-phases__list">
            @foreach ($projectPhases as $phase)
                @php
                    $phaseIsOverdue = ! in_array($phase->status, ['completed', 'cancelled'], true)
                        && $phase->target_date->lt(today());
                    $phaseStatusClass = match ($phase->status) {
                        'completed' => 'is-completed',
                        'in_progress' => 'is-in-progress',
                        'cancelled' => 'is-cancelled',
                        default => 'is-pending',
                    };
                    $phaseStatusLabel = \App\Models\Milestone::STATUS_LABELS[$phase->status] ?? ucfirst($phase->status);
                @endphp
                <li class="task-overview-project-phase" data-project-phase-status="{{ $phase->status }}">
                    <span class="task-overview-project-phase__marker" aria-hidden="true">
                        @if ($phase->status === 'completed')
                            <i class="bi bi-check-lg"></i>
                        @elseif ($phase->status === 'cancelled')
                            <i class="bi bi-x-lg"></i>
                        @else
                            <i class="bi bi-circle-fill"></i>
                        @endif
                    </span>
                    <div class="task-overview-project-phase__content">
                        <div class="task-overview-project-phase__top">
                            <div class="task-overview-project-phase__title">
                                <span class="task-overview-project-phase__number">Step {{ sprintf('%02d', $loop->iteration) }}</span>
                                <h3>{{ $phase->title }}</h3>
                            </div>
                            <span class="task-overview-project-phase__status {{ $phaseStatusClass }}">
                                <span aria-hidden="true"></span>{{ $phaseStatusLabel }}
                            </span>
                        </div>
                        @if ($phase->description)
                            <p class="task-overview-project-phase__description">{{ $phase->description }}</p>
                        @endif
                        <div class="task-overview-project-phase__meta">
                            <span class="{{ $phaseIsOverdue ? 'is-overdue' : '' }}">
                                <i class="bi bi-calendar-event" aria-hidden="true"></i>
                                Deadline: {{ $phase->target_date->format('M j, Y') }}
                            </span>
                            @if ($phaseIsOverdue)
                                <span class="task-overview-project-phase__overdue">
                                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i> Overdue
                                </span>
                            @endif
                        </div>
                    </div>
                    @can('updateMilestone', [$task, $phase])
                        <form method="POST" action="{{ route('milestones.update', [$task, $phase]) }}" class="task-overview-project-phase__actions">
                            @csrf
                            @method('PATCH')
                            <label class="visually-hidden" for="project-phase-status-{{ $phase->id }}">Phase status</label>
                            <select class="form-select form-select-sm" id="project-phase-status-{{ $phase->id }}" name="status">
                                @foreach (array_unique(array_merge([$phase->status], \App\Models\Milestone::STATUS_TRANSITIONS[$phase->status] ?? [])) as $statusValue)
                                    <option value="{{ $statusValue }}" @selected($phase->status === $statusValue)>
                                        {{ \App\Models\Milestone::STATUS_LABELS[$statusValue] ?? ucfirst($statusValue) }}
                                    </option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-outline-primary" type="submit">
                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Update status
                            </button>
                        </form>
                    @endcan
                </li>
            @endforeach
        </ol>
        <footer class="task-overview-project-phases__footer">
            <span><i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $completedProjectPhases }} of {{ $projectPhases->count() }} phases complete</span>
            <span>{{ $projectPhaseProgress }}% progress</span>
        </footer>
    @else
        <div class="task-overview-project-phases__empty">
            <span class="task-overview-project-phases__empty-icon" aria-hidden="true">
                <i class="bi bi-layers"></i>
            </span>
            <strong>No project phases yet</strong>
            <p>Phases added to this project will appear here.</p>
        </div>
    @endif
</section>

@php
    $volume = $task->volume;
    $todayStr = today(config('app.timezone'))->toDateString();
    $prefix = $prefix ?? 'overview';
@endphp

@if ($volumeSummary !== null && $volume !== null)
    <section class="tf-task-detail__card tf-volume-overview-card mb-4" aria-labelledby="{{ $prefix }}-volume-overview-title">
        {{-- Card Header --}}
        <header class="task-activity-feed__header tf-volume-overview-card__header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="task-activity-feed__heading">
                <span class="task-activity-feed__icon" aria-hidden="true">
                    <i class="bi bi-bar-chart-steps"></i>
                </span>
                <div>
                    <h2 class="task-activity-feed__title" id="{{ $prefix }}-volume-overview-title">Volume Overview</h2>
                    <p class="text-secondary mb-0" style="font-size: 12px;">Daily target and team progress.</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                @if ($volumeSummary['completed'] >= $volumeSummary['total'])
                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle px-2.5 py-1.5 rounded-pill font-medium d-inline-flex align-items-center gap-1" style="font-size: 11px;">
                        <i class="bi bi-check-circle-fill text-success"></i> Target Achieved
                    </span>
                @elseif ($volumeSummary['mismatch_count'] > 0)
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-1.5 rounded-pill font-medium d-inline-flex align-items-center gap-1" style="font-size: 11px;">
                        <i class="bi bi-exclamation-triangle-fill text-warning"></i> Behind Pace
                    </span>
                @else
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1.5 rounded-pill font-medium d-inline-flex align-items-center gap-1" style="font-size: 11px;">
                        <i class="bi bi-activity"></i> On Track
                    </span>
                @endif
            </div>
        </header>

        <div class="p-4 tf-volume-overview-card__body" id="{{ $prefix }}-volume-overview-content">
            {{-- Metrics Grid (4 Stat Cards) --}}
            <div class="tf-volume-overview-card__metrics">
                {{-- Total Volume --}}
                <article class="tf-volume-overview-card__metric">
                    <div class="tf-volume-overview-card__metric-heading">
                        <span class="tf-volume-overview-card__metric-label">Total Volume</span>
                        <span class="tf-volume-overview-card__metric-icon text-primary bg-primary-subtle">
                            <i class="bi bi-bullseye fs-6"></i>
                        </span>
                    </div>
                    <div class="tf-volume-overview-card__metric-content">
                        <strong class="tf-volume-overview-card__metric-value">{{ number_format($volumeSummary['total']) }}</strong>
                        <small class="tf-volume-overview-card__metric-detail">Target quota units</small>
                    </div>
                </article>

                {{-- Completed --}}
                <article class="tf-volume-overview-card__metric tf-volume-overview-card__metric--completed">
                    <div class="tf-volume-overview-card__metric-heading">
                        <span class="tf-volume-overview-card__metric-label">Completed</span>
                        <span class="tf-volume-overview-card__metric-icon text-success bg-success-subtle">
                            <i class="bi bi-check2-circle fs-6"></i>
                        </span>
                    </div>
                    <div class="tf-volume-overview-card__metric-content">
                        <strong class="tf-volume-overview-card__metric-value" data-volume-completed>{{ number_format($volumeSummary['completed']) }}</strong>
                        <small class="tf-volume-overview-card__metric-detail">{{ $volumeSummary['percentage'] }}% of total goal</small>
                    </div>
                </article>

                {{-- Remaining --}}
                <article class="tf-volume-overview-card__metric">
                    <div class="tf-volume-overview-card__metric-heading">
                        <span class="tf-volume-overview-card__metric-label">Remaining</span>
                        <span class="tf-volume-overview-card__metric-icon text-secondary bg-secondary-subtle">
                            <i class="bi bi-hourglass-split fs-6"></i>
                        </span>
                    </div>
                    <div class="tf-volume-overview-card__metric-content">
                        <strong class="tf-volume-overview-card__metric-value" data-volume-remaining>{{ number_format($volumeSummary['remaining']) }}</strong>
                        <small class="tf-volume-overview-card__metric-detail">{{ $volumeSummary['working_days'] }} working days scope</small>
                    </div>
                </article>

                {{-- Today's Output --}}
                <article class="tf-volume-overview-card__metric">
                    <div class="tf-volume-overview-card__metric-heading">
                        <span class="tf-volume-overview-card__metric-label">Today's Actual</span>
                        <span class="tf-volume-overview-card__metric-icon text-info-emphasis bg-info-subtle">
                            <i class="bi bi-calendar-check fs-6"></i>
                        </span>
                    </div>
                    <div class="tf-volume-overview-card__metric-content">
                        <strong class="tf-volume-overview-card__metric-value" data-volume-today-actual>{{ number_format($volumeSummary['today_actual_count']) }}</strong>
                        <small class="tf-volume-overview-card__metric-detail">Units recorded today</small>
                    </div>
                </article>
            </div>

        {{-- Progress Bar --}}
        <div class="tf-volume-overview-card__progress">
            <div class="d-flex justify-content-between align-items-center small mb-1">
                <span class="text-secondary fw-semibold">Overall Volume Progress</span>
                <span class="fw-bold text-dark" data-volume-percentage>{{ $volumeSummary['percentage'] }}%</span>
            </div>
            <div class="progress rounded-pill bg-secondary-subtle" role="progressbar" aria-label="Volume completion progress" aria-valuenow="{{ $volumeSummary['percentage'] }}" aria-valuemin="0" aria-valuemax="100" data-volume-progress>
                <div class="progress-bar rounded-pill bg-primary" data-volume-progress-bar style="width: {{ $volumeSummary['percentage'] }}%; transition: width 0.6s ease;"></div>
            </div>
        </div>

        <div class="tf-volume-overview-card__summary-row">
            {{-- Meta Pills Row --}}
            <div class="tf-volume-overview-card__meta">
                <span class="tf-volume-overview-card__meta-item">
                    <i class="bi bi-calendar3 text-primary"></i> <strong>Start:</strong> {{ $volume->start_date->format('M j, Y') }}
                </span>
                <span class="tf-volume-overview-card__meta-separator" aria-hidden="true">·</span>
                <span class="tf-volume-overview-card__meta-item">
                    <i class="bi bi-flag-fill text-danger"></i> <strong>Deadline:</strong> {{ $volume->deadline_date->format('M j, Y') }}
                </span>
                <span class="tf-volume-overview-card__meta-separator" aria-hidden="true">·</span>
                <span class="tf-volume-overview-card__meta-item">
                    <i class="bi bi-briefcase text-secondary"></i> <strong>Working Days:</strong> {{ $volumeSummary['working_days'] }} days
                </span>
                <span class="tf-volume-overview-card__meta-separator" aria-hidden="true">·</span>
                <span class="tf-volume-overview-card__meta-item">
                    <i class="bi bi-speedometer text-info"></i> <strong>Target Rate:</strong> {{ $volumeSummary['minimum_daily_count'] }} / day
                </span>
            </div>

            {{-- Health / Mismatch Warning Alert --}}
            @if ($volumeSummary['mismatch_count'] > 0)
                <div class="alert alert-warning border border-warning-subtle d-flex align-items-center gap-3 p-3 rounded-3 mb-4 tf-volume-overview-card__alert" role="alert">
                    <span class="tf-volume-overview-card__alert-icon d-flex align-items-center justify-content-center text-warning">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </span>
                    <div class="tf-volume-overview-card__alert-copy">
                        <strong class="text-warning-emphasis">Pace alert:</strong>
                        <span class="text-secondary">{{ $volumeSummary['mismatch_count'] }} workday(s) below the target of {{ $volumeSummary['minimum_daily_count'] }}/day or missing subtasks.</span>
                    </div>
                </div>
            @endif
        </div>

        {{-- Daily Target & Schedule Table (The Complete Missing Section) --}}
        <div class="tf-volume-overview-card__schedule">
            

            <div class="table-responsive rounded-3 border tf-volume-overview-card__table-wrap">
                <table class="table table-hover align-middle mb-0 tf-volume-overview-card__table">
                    <colgroup>
                        <col class="tf-volume-overview-card__column-date">
                        <col class="tf-volume-overview-card__column-target">
                        <col class="tf-volume-overview-card__column-actual">
                        <col class="tf-volume-overview-card__column-status">
                    </colgroup>
                    <thead class="table-light text-secondary small text-uppercase tracking-wider">
                        <tr>
                            <th class="ps-3 py-3">Working Date</th>
                            <th class="py-3">Target (Count)</th>
                            <th class="py-3">Actual Output (Count)</th>
                            <th class="py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($volumeSummary['rows'] as $row)
                            @php
                                $dateStr = $row['date']->toDateString();
                                $isToday = $dateStr === $todayStr;
                            @endphp
                            <tr class="tf-volume-overview-card__table-row {{ $isToday ? 'tf-volume-overview-card__table-row--today' : '' }}" data-volume-date="{{ $dateStr }}">
                                <td class="ps-3 py-3 fw-medium tf-volume-overview-card__date">
                                    <div class="tf-volume-overview-card__date-line">
                                        <span class="tf-volume-overview-card__date-value">{{ $row['date']->format('M d, Y') }}</span>
                                        @if ($isToday)
                                            <span class="tf-volume-overview-card__today">Today</span>
                                        @endif
                                    </div>
                                    <small class="tf-volume-overview-card__weekday">{{ $row['date']->format('l') }}</small>
                                </td>
                                <td class="text-center py-3" data-volume-actual>
                                    <span class="tf-volume-overview-card__target">{{ $row['minimum'] }} <small>/ day</small></span>
                                </td>
                                <td class="text-center py-3">
                                    @if ($row['has_update'])
                                        <span class="tf-volume-overview-card__actual {{ $row['actual'] >= $row['minimum'] ? 'tf-volume-overview-card__actual--achieved' : 'tf-volume-overview-card__actual--below' }}">
                                            {{ number_format($row['actual']) }}
                                        </span>
                                    @else
                                        <span class="tf-volume-overview-card__actual-empty">—</span>
                                    @endif
                                </td>
                                <td class="py-3 tf-volume-overview-card__status" data-volume-status>
                                    @if ($row['status'] === 'Achieved')
                                        <span class="badge tf-volume-overview-card__status-badge tf-volume-overview-card__status-badge--achieved">
                                            <span class="tf-volume-overview-card__status-indicator" aria-hidden="true"></span> Achieved
                                        </span>
                                    @elseif ($row['status'] === 'Mismatch')
                                        <span class="badge tf-volume-overview-card__status-badge tf-volume-overview-card__status-badge--mismatch">
                                            <span class="tf-volume-overview-card__status-indicator" aria-hidden="true"></span> Mismatch
                                        </span>
                                    @elseif ($row['status'] === 'Missed Update')
                                        <span class="badge tf-volume-overview-card__status-badge tf-volume-overview-card__status-badge--missed">
                                            <span class="tf-volume-overview-card__status-indicator" aria-hidden="true"></span> Missed
                                        </span>
                                    @else
                                        <span class="badge tf-volume-overview-card__status-badge tf-volume-overview-card__status-badge--pending">
                                            <span class="tf-volume-overview-card__status-indicator" aria-hidden="true"></span> Pending
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">
                                    No working days configured for this volume period.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endif

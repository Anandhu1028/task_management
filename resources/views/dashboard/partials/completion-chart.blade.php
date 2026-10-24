@props([
    'completionChart' => [],
    'title' => 'Task Completion',
    'subtitle' => 'Tasks completed over the selected period',
])

<article class="tf-card">
    <div class="tf-card__head">
        <div class="tf-card__title">
            <i class="bi bi-bar-chart tf-card__icon"></i>
            <div>
                <h2>{{ $title }}</h2>
                <p>{{ $subtitle }}</p>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="tf-status-pill tf-status-pill--completed">
                <i class="bi bi-check-circle"></i>
                <span id="completionChartTotal">{{ $completionChart['totalCompleted'] ?? 0 }}</span> completed
            </span>
        </div>
    </div>

    <div class="tf-chart-wrap" style="position: relative; min-height: 220px;">
        @if (!empty($completionChart['peakDay']) && ($completionChart['peakCount'] ?? 0) > 0)
            <div class="tf-thu-tooltip" id="tfPeakTooltip" style="display: none;">
                <div class="tf-thu-tooltip__title">{{ $completionChart['peakCount'] }} tasks</div>
                <div class="tf-thu-tooltip__sub">Peak: {{ $completionChart['peakDay'] }}</div>
                <div class="tf-thu-tooltip__caret"></div>
            </div>
        @endif

        <canvas id="completionChart"
            data-labels='@json($completionChart['labels'] ?? [])'
            data-values='@json($completionChart['values'] ?? [])'>
        </canvas>

        @if (empty($completionChart['values']) || array_sum($completionChart['values']) === 0)
            <div class="text-center py-4 text-muted small position-absolute top-50 start-50 translate-middle pointer-events-none" id="completionChartEmpty" style="pointer-events: none;">
                <i class="bi bi-clipboard2-check fs-3 d-block mb-1 text-light"></i>
                No tasks completed in this time range.
            </div>
        @endif
    </div>
</article>

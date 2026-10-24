@props([
    'myWorkload' => [],
])

@php
    $labels = $myWorkload['labels'] ?? ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    $values = $myWorkload['values'] ?? [0, 0, 0, 0, 0];
    $totalThisWeek = $myWorkload['totalThisWeek'] ?? array_sum($values);
@endphp

<article class="tf-card">
    <div class="tf-card__head">
        <div class="tf-card__title">
            <i class="bi bi-clock-history tf-card__icon"></i>
            <div>
                <h2>My Workload</h2>
                <p>Assigned work scheduled across this week</p>
            </div>
        </div>
        <span class="badge bg-light text-primary border">
            {{ $totalThisWeek }} tasks this week
        </span>
    </div>

    <div class="tf-chart-wrap tf-chart-wrap--workload" style="height: 200px; position: relative;">
        <canvas id="myWorkloadChart"
            data-labels='@json($labels)'
            data-values='@json($values)'>
        </canvas>
    </div>

    <p class="tf-card__foot">Based on your assigned tasks due and active during this week</p>
</article>

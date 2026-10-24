@props([
    'companyAnalytics' => [],
])

<article class="tf-card mb-4" id="companyOverviewSection">
    <div class="tf-card__head">
        <div class="tf-card__title">
            <i class="bi bi-buildings tf-card__icon"></i>
            <div>
                <h2>Company Overview</h2>
                <p>Task volume and completion across registered organizations</p>
            </div>
        </div>
    </div>

    {{-- Company Comparison Chart --}}
    @if (count($companyAnalytics) > 0)
        <div class="mb-4" style="height: 180px;">
            <canvas id="companyComparisonChart"
                data-labels='@json(array_column($companyAnalytics, 'name'))'
                data-totals='@json(array_column($companyAnalytics, 'total_tasks'))'
                data-completed='@json(array_column($companyAnalytics, 'completed'))'
                data-at-risk='@json(array_column($companyAnalytics, 'at_risk'))'
                data-overdue='@json(array_column($companyAnalytics, 'overdue'))'>
            </canvas>
        </div>
    @else
        <div class="tf-department-performance__blank">
            <i class="bi bi-bar-chart"></i>
            <span>No company task data available.</span>
        </div>
    @endif

</article>

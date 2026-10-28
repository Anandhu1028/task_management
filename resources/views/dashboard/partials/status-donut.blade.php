@props([
    'statusDistribution' => [],
    'title' => 'Task Status',
])

@php
    $total = $statusDistribution['total'] ?? 0;
    $items = $statusDistribution['items'] ?? [];
    $counts = array_column($items, 'count');
@endphp

<article class="tf-card">
    <div class="tf-card__head">
        <div class="tf-card__title">
            <div>
                <h2>{{ $title }}</h2>
                <p>Status distribution within scope</p>
            </div>
        </div>
    </div>

    <div class="tf-status-chart-row">
        <div class="tf-doughnut">
            <canvas id="statusChart"
                data-labels='@json(array_column($items, 'label'))'
                data-values='@json($counts)'
                data-colors='@json(array_column($items, 'color'))'>
            </canvas>
            <div class="tf-doughnut__center">
                <span class="tf-doughnut__label">Total Tasks</span>
                <strong class="tf-doughnut__val" id="statusChartTotalVal">{{ $total }}</strong>
            </div>
        </div>

        <ul class="tf-legend" id="statusChartLegend">
            @forelse ($items as $item)
                <li>
                    <span class="tf-legend__dot" style="background: {{ $item['color'] }}"></span>
                    <span class="tf-legend__name">{{ $item['label'] }}</span>
                    <span class="tf-legend__count">{{ $item['count'] }}</span>
                    <span class="tf-legend__pct">{{ $item['percent'] }}%</span>
                </li>
            @empty
                <li class="text-muted small">No task status data available.</li>
            @endforelse
        </ul>
    </div>
</article>

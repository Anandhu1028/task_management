@props([
    'icon' => 'bi-circle',
    'label' => '',
    'value' => '0',
    'meta' => '',
    'tone' => 'default',
    'meta_type' => 'default',
    'spark' => [4, 6, 5, 7, 6, 8, 9],
    'spark_color' => null,
    'icon_style' => 'primary',
    'href' => null,
])

@php
    $sparkColor = $spark_color ?? match ($tone) {
        'success' => '#22c55e',
        'warning' => '#f59e0b',
        'danger'  => '#ef4444',
        default   => '#2563eb',
    };
@endphp

@if ($href)
    <a class="tf-kpi tf-kpi--link" href="{{ $href }}">
@else
    <article class="tf-kpi">
@endif

    <div class="tf-kpi__main">
        <div class="tf-kpi__top">
            <div class="tf-kpi__icon tf-kpi__icon--{{ $icon_style }}">
                <i class="bi {{ $icon }}"></i>
            </div>
            <p class="tf-kpi__label">{{ $label }}</p>
        </div>

        <div class="tf-kpi__value-row">
            <p class="tf-kpi__value @if($tone === 'danger') is-danger @endif">{{ $value }}</p>

            <div class="tf-kpi__spark">
                <canvas data-spark='@json($spark)' data-color="{{ $sparkColor }}"></canvas>
            </div>
        </div>

        <p class="tf-kpi__meta">
            @if ($meta_type === 'up')
                <span class="tf-kpi__arrow">▲</span>
            @elseif ($meta_type === 'dot-danger')
                <span class="tf-dot tf-dot--danger"></span>
            @elseif ($meta_type === 'dot-orange')
                <span class="tf-dot tf-dot--warning"></span>
            @endif
            <span>{{ $meta }}</span>
        </p>
    </div>

@if ($href)
    </a>
@else
    </article>
@endif
@props([
    'priorityDistribution' => [],
    'title' => 'Priority Overview',
    'subtitle' => 'Work distribution by urgency',
])

<article class="tf-card">
    <div class="tf-card__head">
        <div class="tf-card__title">
            <i class="bi bi-flag tf-card__icon"></i>
            <div>
                <h2>{{ $title }}</h2>
                <p>{{ $subtitle }}</p>
            </div>
        </div>
    </div>

    <ul class="tf-priority-overview-list" id="priorityOverviewList">
        @forelse ($priorityDistribution as $priority)
            <li class="tf-priority-row">
                <span class="tf-priority-row__label">
                    <span class="tf-dot tf-dot--{{ $priority['tone'] }}"></span>
                    {{ $priority['label'] }}
                </span>

                <div class="tf-priority-row__track">
                    <span class="tf-priority-row__bar tf-priority-row__bar--{{ $priority['tone'] }}"
                          style="width: {{ min(100, $priority['percent']) }}%"></span>
                </div>

                <strong class="tf-priority-row__count">{{ $priority['count'] }}</strong>
            </li>
        @empty
            <li class="text-muted small py-2">No priority data found.</li>
        @endforelse
    </ul>
</article>

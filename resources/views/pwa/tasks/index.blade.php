@extends('layouts.pwa')

@section('title', 'Tasks · TaskFlow')

@section('content')
@include('pwa.partials.topbar')
@php
    $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->before(' ')->toString();
    $cards = [
        ['key' => 'all', 'label' => 'Total Tasks', 'value' => $summary['total'], 'tone' => 'blue', 'icon' => '✓'],
        ['key' => 'overdue', 'label' => 'Overdue', 'value' => $summary['overdue_count'], 'tone' => 'red', 'icon' => '!'],
        ['key' => 'awaiting', 'label' => 'Awaiting Assignment', 'value' => $summary['pending_assignment_count'], 'tone' => 'amber', 'icon' => '◷'],
        ['key' => 'at_risk', 'label' => 'At Risk', 'value' => $summary['at_risk_count'], 'tone' => 'orange', 'icon' => '⚠'],
        ['key' => 'in_progress', 'label' => 'In Progress', 'value' => $summary['in_progress_count'], 'tone' => 'blue', 'icon' => '◌'],
        ['key' => 'done', 'label' => 'Completed', 'value' => $summary['completed_count'], 'tone' => 'green', 'icon' => '✓'],
    ];
@endphp
<main class="pwa-container pwa-task-index">
    <header class="pwa-list-heading">
        <section class="pwa-greeting">
            <h1>Hi, {{ $firstName }} <span aria-hidden="true">👋</span></h1>
            <p>Here is where your tasks stand today.</p>
        </section>
        @can('tasks.create')
            <a href="{{ route('tasks.create') }}" class="pwa-btn pwa-btn--primary pwa-new-task">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                New Task
            </a>
        @endcan
    </header>

    <section class="pwa-summary" aria-label="Task summary">
        @foreach ($cards as $card)
            <a href="{{ route('pwa.tasks.index', [
                'filter' => $card['key'] === 'all' ? null : $card['key'],
                'search' => $search !== '' ? $search : null,
                'sort' => $sort,
            ]) }}"
               class="pwa-stat pwa-stat--{{ $card['tone'] }} {{ $filter === $card['key'] ? 'is-active' : '' }}">
                <span class="pwa-stat__icon" aria-hidden="true">{{ $card['icon'] }}</span>
                <span class="pwa-stat__value">{{ number_format($card['value']) }}</span>
                <span class="pwa-stat__label">{{ $card['label'] }}</span>
            </a>
        @endforeach
    </section>

    @include('pwa.partials.list-controls', [
        'action' => route('pwa.tasks.index'),
        'showSort' => true,
        'sortOptions' => $sortOptions,
    ])

    <section class="pwa-list" aria-label="Tasks">
        @forelse ($tasks as $task)
            @include('pwa.partials.task-row', ['task' => $task])
        @empty
            <p class="pwa-empty">No tasks match this view.</p>
        @endforelse
    </section>

    @include('pwa.partials.pager')
</main>
@endsection

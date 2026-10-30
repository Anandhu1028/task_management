@extends('layouts.pwa')

@section('title', 'Tasks · TaskFlow')

@section('content')
@include('pwa.partials.topbar')
@php
    $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->before(' ')->toString();
    $cards = [
        ['key' => 'all', 'label' => 'Total tasks', 'value' => $summary['total'], 'tone' => 'blue'],
        ['key' => 'overdue', 'label' => 'Overdue', 'value' => $summary['overdue_count'], 'tone' => 'red'],
        ['key' => 'awaiting', 'label' => 'Awaiting assignment', 'value' => $summary['pending_assignment_count'], 'tone' => 'amber'],
        ['key' => 'at_risk', 'label' => 'At risk', 'value' => $summary['at_risk_count'], 'tone' => 'orange'],
        ['key' => 'in_progress', 'label' => 'In progress', 'value' => $summary['in_progress_count'], 'tone' => 'blue'],
        ['key' => 'done', 'label' => 'Completed', 'value' => $summary['completed_count'], 'tone' => 'green'],
    ];
@endphp
<main class="pwa-container">
    <section class="pwa-greeting">
        <h1>Hi, {{ $firstName }}</h1>
        <p>Here is where your tasks stand today.</p>
    </section>

    <section class="pwa-summary" aria-label="Task summary">
        @foreach ($cards as $card)
            <a href="{{ route('pwa.tasks.index', $card['key'] === 'all' ? [] : ['filter' => $card['key']]) }}"
               class="pwa-stat pwa-stat--{{ $card['tone'] }} {{ $filter === $card['key'] ? 'is-active' : '' }}">
                <span class="pwa-stat__value">{{ number_format($card['value']) }}</span>
                <span class="pwa-stat__label">{{ $card['label'] }}</span>
            </a>
        @endforeach
    </section>

    @include('pwa.partials.list-controls', ['action' => route('pwa.tasks.index')])

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

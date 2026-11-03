@extends('layouts.app')

@section('title', 'Daily Update History')

@section('content')
    <div class="tf-dwr">
        <header class="tf-dwr__header">
            <div>
                <p class="tf-dwr__eyebrow">{{ $task->task_code }} · {{ $task->title }}</p>
                <h1>{{ $subtask->title }}</h1>
                <p>Historical daily work reports are retained as individual records.</p>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('dwr.index', ['task_id' => $task->id]) }}">
                <i class="bi bi-arrow-left"></i> Back to DWR
            </a>
        </header>

        <section class="tf-dwr-panel">
            @forelse ($updates as $update)
                <article class="tf-dwr-history">
                    <header>
                        <div>
                            <strong>{{ $update->employee?->name ?? $update->employee_name }}</strong>
                            <span>{{ $update->work_date->format('M j, Y') }}</span>
                        </div>
                        <span class="tf-dwr-status tf-dwr-status--{{ $update->current_status }}">
                            {{ \App\Models\Subtask::STATUS_LABELS[$update->current_status] ?? ucfirst($update->current_status) }}
                        </span>
                    </header>
                    <p>{{ $update->work_description }}</p>
                    @if ($update->work_completed)
                        <p><strong>Completed:</strong> {{ $update->work_completed }}</p>
                    @endif
                    <footer>
                        @if ($update->time_spent_minutes)
                            <span><i class="bi bi-clock"></i> {{ $update->time_spent_minutes }} minutes</span>
                        @endif
                        <span>Submitted {{ $update->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</span>
                    </footer>
                </article>
            @empty
                <p class="tf-dwr-empty">No daily work updates have been recorded for this subtask.</p>
            @endforelse
            <div class="tf-dwr-pagination">{{ $updates->links() }}</div>
        </section>
    </div>
@endsection

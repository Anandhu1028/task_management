@extends('layouts.app')

@section('title', 'Recurring Tasks')

@section('content')
    <section class="tf-management">
        <div class="tf-management__header"><div><p class="tf-page-kicker">Task schedules</p><h1>Recurring Tasks</h1><p>Manage recurring schedules separately from generated task instances.</p></div></div>
        <form method="GET" class="tf-user-toolbar d-flex gap-2 flex-wrap mb-3">
            <input class="form-control" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search templates">
            <select class="form-select" name="company_id"><option value="">All companies</option>@foreach ($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id'] ?? null) == $company->id)>{{ $company->name }}</option>@endforeach</select>
            <select class="form-select" name="status"><option value="">All statuses</option>@foreach (\App\Models\RecurringTaskTemplate::STATUSES as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ ucfirst($status) }}</option>@endforeach</select>
            <button class="tf-btn tf-btn--primary">Filter</button>
        </form>
        <div class="tf-table-wrap tf-user-table-wrap"><table class="tf-table tf-user-table"><thead><tr><th>Template / Task Title</th><th>Company / Department</th><th>Task Type</th><th>Frequency</th><th>Assigned Users</th><th>Schedule</th><th>Next Occurrence</th><th>Generated</th><th>Status</th><th></th></tr></thead><tbody>
        @forelse ($templates as $template)
            <tr><td><a class="tf-task-identity__link" href="{{ route('recurring-tasks.show', $template) }}">{{ $template->title }}</a></td><td>{{ $template->company?->name }}<br><small>{{ $template->department?->name }}</small></td><td>{{ ucfirst($template->task_type) }}</td><td>{{ ucfirst($template->repeat_frequency) }}</td><td>{{ $template->assignees->pluck('name')->implode(', ') ?: 'Unassigned' }}</td><td>{{ $template->start_date->format('M d, Y') }} – {{ $template->end_date->format('M d, Y') }}<br><small>{{ \Illuminate\Support\Carbon::parse($template->start_time)->format('g:i A') }}–{{ \Illuminate\Support\Carbon::parse($template->end_time)->format('g:i A') }}</small></td><td>{{ $template->nextOccurrenceDate()?->format('M d, Y') ?? '—' }}</td><td>{{ $template->occurrences_count }}</td><td><span class="tf-task-status">{{ ucfirst($template->status) }}</span></td><td><a class="tf-btn tf-btn--ghost" href="{{ route('recurring-tasks.show', $template) }}">View</a></td></tr>
        @empty <tr><td colspan="10" class="text-center py-4">No recurring schedules found.</td></tr>@endforelse
        </tbody></table></div>
        <footer class="tf-management__table-footer">{{ $templates->links('pagination::bootstrap-5') }}</footer>
    </section>
@endsection

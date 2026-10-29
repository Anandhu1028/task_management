<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PwaTaskController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Task::class);
        $validated = $request->validate(['status' => ['nullable', 'in:notified,unassigned,assigned,in_progress,on_hold,submitted_for_review,rework,completed,cancelled']]);

        $tasks = Task::query()
            ->visibleTo($request->user())
            ->when($validated['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->with(['activeAssignees:id,name', 'assignee:id,name'])
            ->orderByRaw('CASE WHEN COALESCE(due_date, original_deadline) IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('COALESCE(due_date, original_deadline)')
            ->paginate(15)
            ->withQueryString();

        $baseQuery = Task::query()->visibleTo($request->user());
        $summary = [
            'all' => (clone $baseQuery)->count(),
            'overdue' => (clone $baseQuery)->overdue()->count(),
            'awaiting' => (clone $baseQuery)->whereIn('status', ['notified', 'unassigned'])->count(),
            'at_risk' => (clone $baseQuery)->atRisk()->count(),
            'in_progress' => (clone $baseQuery)->where('status', 'in_progress')->count(),
            'completed' => (clone $baseQuery)->where('status', 'completed')->count(),
        ];

        return view('pwa.tasks.index', compact('tasks', 'summary'));
    }

    public function show(Request $request, Task $task): View
    {
        $this->authorize('view', $task);
        $task->load(['company:id,name', 'department:id,name', 'activeAssignees:id,name', 'assignee:id,name', 'pocs:id,name']);

        return view('pwa.tasks.show', compact('task'));
    }
}

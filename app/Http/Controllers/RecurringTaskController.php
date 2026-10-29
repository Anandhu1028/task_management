<?php

namespace App\Http\Controllers;

use App\Models\RecurringTaskTemplate;
use App\Models\Task;
use App\Services\TaskWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecurringTaskController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', RecurringTaskTemplate::class);

        return redirect()->route('tasks.index', ['task_type' => 'recurring']);
    }

    public function show(RecurringTaskTemplate $recurringTask): RedirectResponse
    {
        $this->authorize('view', $recurringTask);

        return redirect()->route('tasks.index', [
            'task_type' => 'recurring',
            'search' => $recurringTask->title,
        ]);
    }

    public function updateStatus(Request $request, RecurringTaskTemplate $recurringTask, Task $task, TaskWorkflowService $workflow): RedirectResponse
    {
        $this->authorize('view', $recurringTask);
        abort_unless($task->recurring_task_template_id === $recurringTask->id, 404);
        $validated = $request->validate([
            'status' => ['required', Rule::in(Task::STATUS_TRANSITIONS[$task->status] ?? [])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'update_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $workflow->changeStatus($task, $validated['status'], $validated['reason'] ?? null, $request->user(), $validated['update_date'] ?? null);

        return back()->with('success', 'Generated task status updated successfully.');
    }

    public function updateScheduleStatus(Request $request, RecurringTaskTemplate $recurringTask): RedirectResponse
    {
        $this->authorize('update', $recurringTask);
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'paused', 'cancelled'])]]);
        $recurringTask->update(['status' => $validated['status']]);

        return back()->with('success', 'Recurring schedule status updated successfully.');
    }
}

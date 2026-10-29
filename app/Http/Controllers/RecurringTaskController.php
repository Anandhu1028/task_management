<?php

namespace App\Http\Controllers;

use App\Models\RecurringTask;
use App\Models\Task;
use App\Services\TaskWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecurringTaskController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('tasks.index', ['task_type' => 'recurring']);
    }

    public function show(RecurringTask $recurringTask): RedirectResponse
    {
        return redirect()->route('tasks.show', $recurringTask->task_id);
    }

    public function updateStatus(Request $request, RecurringTask $recurringTask, Task $task, TaskWorkflowService $workflow): RedirectResponse
    {
        $this->authorize('view', $task);
        abort_unless($task->recurring_task_id === $recurringTask->id, 404);
        $validated = $request->validate([
            'status' => ['required', Rule::in(Task::STATUS_TRANSITIONS[$task->status] ?? [])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'update_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $workflow->changeStatus($task, $validated['status'], $validated['reason'] ?? null, $request->user(), $validated['update_date'] ?? null);

        return back()->with('success', 'Generated task status updated successfully.');
    }

    public function updateScheduleStatus(Request $request, RecurringTask $recurringTask): RedirectResponse
    {
        $this->authorize('update', $recurringTask->task);
        $validated = $request->validate(['status' => ['required', Rule::in(RecurringTask::STATUSES)]]);
        $recurringTask->update(['status' => $validated['status']]);

        return back()->with('success', 'Recurring schedule status updated successfully.');
    }
}

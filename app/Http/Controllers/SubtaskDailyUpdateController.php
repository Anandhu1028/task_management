<?php

namespace App\Http\Controllers;

use App\Models\Subtask;
use App\Models\SubtaskDailyUpdate;
use App\Models\Task;
use App\Services\SubtaskActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SubtaskDailyUpdateController extends Controller
{
    public function __construct(private readonly SubtaskActivityService $activity) {}

    public function store(Request $request, Task $task, Subtask $subtask): RedirectResponse
    {
        abort_if($subtask->task_id !== $task->id, 404);
        $this->authorize('view', $task);

        $update = new SubtaskDailyUpdate([
            'task_id' => $task->id,
            'subtask_id' => $subtask->id,
            'employee_id' => $request->user()->id,
        ]);
        $update->setRelation('subtask', $subtask);
        $this->authorize('create', $update);

        $validated = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'work_description' => ['required', 'string', 'max:5000'],
            'work_completed' => ['nullable', 'string', 'max:5000'],
            'time_spent_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        DB::transaction(function () use ($validated, $task, $subtask, $request): void {
            $workDate = $validated['work_date'];
            $timeSpent = isset($validated['time_spent_minutes'])
                ? (int) $validated['time_spent_minutes']
                : null;
            SubtaskDailyUpdate::query()->create([
                'task_id' => $task->id,
                'subtask_id' => $subtask->id,
                'employee_id' => $request->user()->id,
                'reported_by' => $request->user()->id,
                'task_title' => $task->title,
                'subtask_title' => $subtask->title,
                'employee_name' => $request->user()->name,
                'work_date' => $workDate,
                'work_description' => $validated['work_description'],
                'work_completed' => $validated['work_completed'] ?? null,
                'current_status' => $subtask->is_completed ? 'completed' : $subtask->status,
                'time_spent_minutes' => $timeSpent,
            ]);

            if ($timeSpent !== null) {
                $elapsedMinutes = (int) ($subtask->actual_elapsed_minutes ?? 0) + $timeSpent;
                $subtask->forceFill([
                    'actual_elapsed_minutes' => $elapsedMinutes,
                    'actual_hours' => number_format($elapsedMinutes / 60, 2, '.', ''),
                ])->save();
            }

            $remarks = 'Daily work update: '.$validated['work_description'];
            if (filled($validated['work_completed'] ?? null)) {
                $remarks .= ' | Completed today: '.$validated['work_completed'];
            }
            if ($timeSpent !== null) {
                $remarks .= ' | Time spent: '.$timeSpent.' minutes';
            }

            $this->activity->record(
                $task,
                $subtask,
                $request->user(),
                'daily_update',
                $subtask->status,
                $subtask->is_completed ? 'completed' : $subtask->status,
                $remarks,
                $workDate,
            );
        });

        return redirect()
            ->route('dwr.index', ['date_range' => 'custom', 'from_date' => $validated['work_date'], 'to_date' => $validated['work_date']])
            ->with('success', 'Daily work update recorded.');
    }

    public function history(Request $request, Task $task, Subtask $subtask): View
    {
        abort_if($subtask->task_id !== $task->id, 404);
        $this->authorize('view', $task);
        $updates = $subtask->dailyUpdates()
            ->with(['employee:id,name,designation', 'reporter:id,name'])
            ->when(
                ! $request->user()->hasAssignedRole('Admin')
                    && ! $request->user()->hasAssignedRole('CEO')
                    && ! $request->user()->hasAssignedRole('Manager')
                    && ! $request->user()->hasAssignedRole('Team Leader'),
                fn ($query) => $query->where('employee_id', $request->user()->id),
            )
            ->orderByDesc('work_date')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        foreach ($updates as $update) {
            $this->authorize('view', $update);
        }

        return view('subtask-management.history', compact('task', 'subtask', 'updates'));
    }
}

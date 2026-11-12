<?php

namespace App\Http\Controllers;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\SubtaskActivityService;
use App\Services\SubtaskProgressService;
use App\Services\VolumeTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubtaskController extends Controller
{
    public function __construct(
        private readonly SubtaskActivityService $activity,
        private readonly SubtaskProgressService $progress,
        private readonly VolumeTaskService $volumes,
    ) {}

    public function store(Request $request, Task $task): RedirectResponse|JsonResponse
    {
        $this->authorize('createSubtask', $task);
        $isVolumeTask = $task->task_type === 'volume';

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['nullable', 'string', Rule::in(Subtask::PRIORITIES)],
            'status' => ['nullable', 'string', Rule::in(Subtask::STATUSES)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0.01', 'max:9999.99'],
            'progress_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'work_date' => $isVolumeTask
                ? ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']
                : ['prohibited'],
        ]);
        $data = $this->prepareNewSubtaskData($task, $validated);
        if (! $request->user()->can('updateSubtask', $task)) {
            $data = [
                ...$data,
                'assigned_to' => $request->user()->id,
                'status' => 'assigned',
                'priority' => 'medium',
                'start_date' => null,
                'estimated_hours' => null,
                'progress_percentage' => 0,
            ];
        }
        $this->validateSubtaskDates($task, $data['start_date'], $data['due_date']);
        $this->validateAssignee($task, $data['assigned_to']);

        $assignedUserName = $data['assigned_to'] === null
            ? null
            : User::query()->whereKey($data['assigned_to'])->value('name');

        $subtask = DB::transaction(function () use ($task, $request, $data, $assignedUserName): Subtask {
            $sortOrder = (int) ($task->subtasks()->max('sort_order') ?? -1) + 1;
            $subtask = $this->createSubtask($task, $data, $request->user(), $sortOrder, $assignedUserName);
            if ($task->task_type !== 'volume') {
                $this->progress->sync($task);
            }

            return $subtask;
        });

        $subtask->load(['creator', 'assignee', 'completedBy', ...($isVolumeTask ? ['volumeUpdates'] : [])]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'subtask' => $this->formatSubtask($subtask),
            ], 201);
        }

        return back()->with('success', 'Subtask added successfully.');
    }

    public function bulkStore(Request $request, Task $task): JsonResponse
    {
        $this->authorize('createSubtask', $task);
        $isVolumeTask = $task->task_type === 'volume';

        $validated = $request->validate([
            'subtasks' => ['required', 'array', 'min:1', 'max:50'],
            'subtasks.*.title' => ['required', 'string', 'max:500'],
            'subtasks.*.description' => ['nullable', 'string', 'max:5000'],
            'subtasks.*.assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'subtasks.*.priority' => ['nullable', 'string', Rule::in(Subtask::PRIORITIES)],
            'subtasks.*.status' => ['nullable', 'string', Rule::in(Subtask::STATUSES)],
            'subtasks.*.start_date' => ['nullable', 'date_format:Y-m-d'],
            'subtasks.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'subtasks.*.estimated_hours' => ['nullable', 'numeric', 'min:0.01', 'max:9999.99'],
            'subtasks.*.progress_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'subtasks.*.work_date' => $isVolumeTask
                ? ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']
                : ['prohibited'],
        ]);

        $rows = [];
        foreach ($validated['subtasks'] as $index => $row) {
            $rows[$index] = $this->prepareNewSubtaskData($task, $row);
            if (! $request->user()->can('updateSubtask', $task)) {
                $rows[$index] = [
                    ...$rows[$index],
                    'assigned_to' => $request->user()->id,
                    'status' => 'assigned',
                    'priority' => 'medium',
                    'start_date' => null,
                    'estimated_hours' => null,
                    'progress_percentage' => 0,
                ];
            }
            $this->validateSubtaskDates($task, $rows[$index]['start_date'], $rows[$index]['due_date'], "subtasks.{$index}.");
            $this->validateAssignee($task, $rows[$index]['assigned_to'], "subtasks.{$index}.");
        }

        $assigneeNames = User::query()
            ->whereIn('id', collect($rows)->pluck('assigned_to')->filter()->unique())
            ->pluck('name', 'id');

        $subtasks = DB::transaction(function () use ($task, $request, $rows, $assigneeNames): array {
            $sortOrder = (int) ($task->subtasks()->max('sort_order') ?? -1) + 1;
            $subtasks = [];

            foreach ($rows as $row) {
                $subtasks[] = $this->createSubtask(
                    $task,
                    $row,
                    $request->user(),
                    $sortOrder++,
                    $assigneeNames->get($row['assigned_to'] ?? null),
                );
            }

            if ($task->task_type !== 'volume') {
                $this->progress->sync($task);
            }

            return $subtasks;
        });

        foreach ($subtasks as $subtask) {
            $subtask->load(['creator', 'assignee', 'completedBy', ...($isVolumeTask ? ['volumeUpdates'] : [])]);
        }

        return response()->json([
            'ok' => true,
            'subtasks' => array_map(fn (Subtask $subtask): array => $this->formatSubtask($subtask), $subtasks),
        ], 201);
    }

    public function show(Task $task, Subtask $subtask): JsonResponse
    {
        $this->authorize('view', $task);
        abort_if($subtask->task_id !== $task->id, 404);

        $subtask->load(['creator', 'assignee', 'completedBy', ...($task->task_type === 'volume' ? ['volumeUpdates'] : [])]);

        return response()->json([
            'ok' => true,
            'subtask' => $this->formatSubtask($subtask),
            'parent_task' => [
                'id' => $task->id,
                'task_code' => $task->task_code,
                'title' => $task->title,
                'start_date' => $task->start_date?->format('Y-m-d'),
                'due_date' => ($task->due_date ?? $task->original_deadline)?->format('Y-m-d'),
            ],
        ]);
    }

    public function toggle(Request $request, Task $task, Subtask $subtask): JsonResponse
    {
        abort_if($subtask->task_id !== $task->id, 404);
        $this->authorize('updateSubtask', [$task, $subtask]);

        $actor = $request->user();
        $wasCompleted = $subtask->is_completed || $subtask->status === 'completed';
        $oldStatus = $wasCompleted ? 'completed' : $subtask->status;
        $newStatus = $wasCompleted
            ? ($subtask->assigned_to ? 'assigned' : 'todo')
            : 'completed';

        DB::transaction(function () use ($task, $subtask, $actor, $wasCompleted, $oldStatus, $newStatus): void {
            $this->applyStatusAndProgress($subtask, $newStatus, $wasCompleted ? 0 : 100, $actor);
            $subtask->save();

            $this->activity->record(
                $task,
                $subtask,
                $actor,
                'status',
                $oldStatus,
                $newStatus,
                $wasCompleted
                    ? "reopened subtask \"{$subtask->title}\""
                    : "completed subtask \"{$subtask->title}\"",
            );
            $this->activity->notifyParticipants(
                $task,
                $subtask,
                $actor,
                $wasCompleted ? 'reopened' : 'completed',
                $wasCompleted ? 'Subtask reopened' : 'Subtask completed',
                "{$actor->name} ".($wasCompleted ? 'reopened' : 'completed')
                    ." subtask {$subtask->title} on {$task->task_code}.",
            );
            if ($task->task_type !== 'volume') {
                $this->progress->sync($task);
            }
        });

        $subtask->load(['creator', 'assignee', 'completedBy', ...($task->task_type === 'volume' ? ['volumeUpdates'] : [])]);

        return response()->json([
            'ok' => true,
            'subtask' => $this->formatSubtask($subtask),
        ]);
    }

    public function update(Request $request, Task $task, Subtask $subtask): JsonResponse
    {
        $this->authorize('view', $task);
        abort_if($subtask->task_id !== $task->id, 404);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(Subtask::PRIORITIES)],
            'status' => ['sometimes', 'required', 'string', Rule::in(Subtask::STATUSES)],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'estimated_hours' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:9999.99'],
            'actual_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999.99'],
            'progress_percentage' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'work_date' => $task->task_type === 'volume'
                ? ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today']
                : ['prohibited'],
        ]);

        $actor = $request->user();
        $managerFields = ['title', 'description', 'assigned_to', 'priority', 'start_date', 'due_date', 'estimated_hours', 'actual_hours'];
        $managerChanges = $this->hasManagerChanges($subtask, $validated, $managerFields);
        $statusChanged = isset($validated['status'])
            && $validated['status'] !== ($subtask->is_completed ? 'completed' : $subtask->status);
        $progressChanged = isset($validated['progress_percentage'])
            && (int) $validated['progress_percentage'] !== (int) $subtask->progress_percentage;
        $volumeEntryChanged = $task->task_type === 'volume'
            && array_key_exists('work_date', $validated);

        if ($managerChanges) {
            $this->authorize('updateSubtask', [$task, $subtask]);
        } elseif ($statusChanged || $progressChanged || $volumeEntryChanged) {
            $this->authorize('updateSubtask', [$task, $subtask]);
        } else {
            $this->authorize('view', $task);

            $subtask->load(['creator', 'assignee', 'completedBy']);

            return response()->json([
                'ok' => true,
                'subtask' => $this->formatSubtask($subtask),
            ]);
        }

        $startDate = array_key_exists('start_date', $validated)
            ? $validated['start_date']
            : $subtask->start_date?->format('Y-m-d');
        $dueDate = array_key_exists('due_date', $validated)
            ? $validated['due_date']
            : $subtask->due_date?->format('Y-m-d');
        if (array_key_exists('start_date', $validated) || array_key_exists('due_date', $validated)) {
            $this->validateSubtaskDates($task, $startDate, $dueDate);
        }

        if (array_key_exists('assigned_to', $validated)
            && (int) ($validated['assigned_to'] ?? 0) !== (int) ($subtask->assigned_to ?? 0)) {
            $this->authorize('reassignSubtask', [$task, $subtask]);
            $this->validateAssignee($task, $validated['assigned_to']);
        }

        $oldStatus = $subtask->is_completed ? 'completed' : $subtask->status;
        $oldProgress = (int) $subtask->progress_percentage;
        $oldAssigneeId = $subtask->assigned_to;
        $oldTitle = $subtask->title;
        $oldDescription = $subtask->description;
        $oldPriority = $subtask->priority;
        $oldStartDate = $subtask->start_date?->format('Y-m-d');
        $oldDueDate = $subtask->due_date?->format('Y-m-d');
        $oldEstimatedHours = $subtask->estimated_hours === null ? null : (string) $subtask->estimated_hours;
        $oldActualHours = $subtask->actual_hours === null ? null : (string) $subtask->actual_hours;
        $newStatus = $validated['status'] ?? $oldStatus;
        $newProgress = array_key_exists('progress_percentage', $validated)
            ? (int) $validated['progress_percentage']
            : ($newStatus === 'completed' ? 100 : ($oldStatus === 'completed' && $newStatus !== 'completed' ? 0 : $oldProgress));

        if ($newStatus === 'completed' && $newProgress !== 100) {
            throw ValidationException::withMessages([
                'progress_percentage' => 'Completed subtasks must have 100% progress.',
            ]);
        }
        if ($newStatus !== 'completed' && $newProgress === 100) {
            throw ValidationException::withMessages([
                'progress_percentage' => 'Set the status to Completed to reach 100% progress.',
            ]);
        }

        DB::transaction(function () use (
            $task,
            $subtask,
            $actor,
            $validated,
            $oldStatus,
            $oldProgress,
            $oldAssigneeId,
            $oldTitle,
            $oldDescription,
            $oldPriority,
            $oldStartDate,
            $oldDueDate,
            $oldEstimatedHours,
            $oldActualHours,
            $newStatus,
            $newProgress,
        ): void {
            $updates = [];
            foreach (['title', 'description', 'assigned_to', 'priority', 'start_date', 'due_date', 'estimated_hours', 'actual_hours'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            $statusChanged = $newStatus !== $oldStatus;
            $progressChanged = $newProgress !== $oldProgress;
            if ($statusChanged || $progressChanged) {
                $this->applyStatusAndProgress($subtask, $newStatus, $newProgress, $actor);
                $updates += [
                    'status' => $subtask->status,
                    'is_completed' => $subtask->is_completed,
                    'progress_percentage' => $subtask->progress_percentage,
                    'completed_at' => $subtask->completed_at,
                    'completed_by' => $subtask->completed_by,
                    'actual_started_at' => $subtask->actual_started_at,
                    'actual_elapsed_minutes' => $subtask->actual_elapsed_minutes,
                    'actual_start_at' => $subtask->actual_start_at,
                ];
            }

            if ($updates !== []) {
                $subtask->fill($updates)->save();
                $subtask->refresh();
            }

            if ($task->task_type === 'volume') {
                $existingVolumeUpdate = $subtask->volumeUpdates()
                    ->where('task_id', $task->id)
                    ->orderBy('id')
                    ->first();
                $workDate = array_key_exists('work_date', $validated)
                    ? $validated['work_date']
                    : ($existingVolumeUpdate?->update_date?->toDateString() ?? today(config('app.timezone'))->toDateString());
                $this->volumes->syncFromSubtask(
                    $task,
                    $subtask,
                    $actor,
                    $workDate,
                    $subtask->description,
                );
            }

            if (array_key_exists('assigned_to', $validated)
                && (int) ($validated['assigned_to'] ?? 0) !== (int) ($oldAssigneeId ?? 0)) {
                $oldAssignee = $oldAssigneeId ? User::query()->find($oldAssigneeId) : null;
                $newAssignee = $subtask->assigned_to ? User::query()->find($subtask->assigned_to) : null;
                $this->activity->record(
                    $task,
                    $subtask,
                    $actor,
                    'assignment',
                    $oldAssignee?->name,
                    $newAssignee?->name ?? 'Unassigned',
                    $newAssignee
                        ? "assigned subtask \"{$subtask->title}\" to {$newAssignee->name}"
                        : "unassigned subtask \"{$subtask->title}\"",
                );
                $this->activity->notifyParticipants(
                    $task,
                    $subtask,
                    $actor,
                    $oldAssigneeId ? 'reassigned' : 'assigned',
                    $oldAssigneeId ? 'Subtask reassigned' : 'Subtask assigned',
                    $newAssignee
                        ? "{$actor->name} assigned {$subtask->title} to {$newAssignee->name} on {$task->task_code}."
                        : "{$actor->name} unassigned {$subtask->title} on {$task->task_code}.",
                );
            }

            if ($statusChanged) {
                $event = $newStatus === 'completed'
                    ? 'completed'
                    : ($oldStatus === 'completed' ? 'reopened' : 'status_changed');
                $this->activity->record(
                    $task,
                    $subtask,
                    $actor,
                    'status',
                    $oldStatus,
                    $newStatus,
                    $event === 'completed'
                        ? "completed subtask \"{$subtask->title}\""
                        : ($event === 'reopened'
                            ? "reopened subtask \"{$subtask->title}\""
                            : "changed subtask \"{$subtask->title}\" status from ".(Subtask::STATUS_LABELS[$oldStatus] ?? $oldStatus).' to '.(Subtask::STATUS_LABELS[$newStatus] ?? $newStatus)),
                );
                $this->activity->notifyParticipants(
                    $task,
                    $subtask,
                    $actor,
                    $event,
                    $event === 'completed' ? 'Subtask completed' : 'Subtask status updated',
                    "{$actor->name} ".($event === 'completed' ? 'completed' : 'changed the status of')
                        ." {$subtask->title} on {$task->task_code} to ".Subtask::STATUS_LABELS[$newStatus].'.',
                );
            } elseif ($progressChanged) {
                $this->activity->record(
                    $task,
                    $subtask,
                    $actor,
                    'progress',
                    (string) $oldProgress,
                    (string) $newProgress,
                    "updated progress for subtask \"{$subtask->title}\" from {$oldProgress}% to {$newProgress}%",
                );
            }

            $newStartDate = $subtask->start_date?->format('Y-m-d');
            $newDueDate = $subtask->due_date?->format('Y-m-d');
            if ($oldStartDate !== $newStartDate || $oldDueDate !== $newDueDate) {
                $this->activity->record(
                    $task,
                    $subtask,
                    $actor,
                    'deadline',
                    'start: '.($oldStartDate ?? 'none').'; due: '.($oldDueDate ?? 'none'),
                    'start: '.($newStartDate ?? 'none').'; due: '.($newDueDate ?? 'none'),
                    "changed the timeframe for subtask \"{$subtask->title}\"",
                );
            }

            $detailChanges = [];
            foreach ([
                'Title' => [$oldTitle, $subtask->title],
                'Description' => [$oldDescription, $subtask->description],
                'Priority' => [$oldPriority, $subtask->priority],
                'Estimated hours' => [$oldEstimatedHours, $subtask->estimated_hours === null ? null : (string) $subtask->estimated_hours],
                'Actual hours' => [$oldActualHours, $subtask->actual_hours === null ? null : (string) $subtask->actual_hours],
            ] as $label => [$oldValue, $newValue]) {
                if ($oldValue !== $newValue) {
                    $detailChanges[] = "{$label}: ".($oldValue ?? '—').' → '.($newValue ?? '—');
                }
            }

            if ($detailChanges !== []) {
                $this->activity->record(
                    $task,
                    $subtask,
                    $actor,
                    'updated',
                    $oldTitle,
                    $subtask->title,
                    "updated details for subtask \"{$subtask->title}\": ".implode('; ', $detailChanges),
                );
            }

            if ($task->task_type !== 'volume') {
                $this->progress->sync($task);
            }
        });

        $subtask->load(['creator', 'assignee', 'completedBy', ...($task->task_type === 'volume' ? ['volumeUpdates'] : [])]);

        return response()->json([
            'ok' => true,
            'subtask' => $this->formatSubtask($subtask),
        ]);
    }

    public function destroy(Request $request, Task $task, Subtask $subtask): JsonResponse
    {
        abort_if($subtask->task_id !== $task->id, 404);
        $this->authorize('deleteSubtask', [$task, $subtask]);

        $actor = $request->user();

        DB::transaction(function () use ($task, $subtask, $actor): void {
            $this->activity->record(
                $task,
                $subtask,
                $actor,
                'deleted',
                $subtask->title,
                'Deleted',
                json_encode([
                    'action' => 'deleted',
                    'title' => $subtask->title,
                    'description' => $subtask->description,
                    'assigned_to' => $subtask->assigned_to,
                    'assignee' => $subtask->assignee?->name ?? 'Unassigned',
                    'deleted_subtask_id' => $subtask->id,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
            $this->activity->notifyParticipants(
                $task,
                $subtask,
                $actor,
                'deleted',
                'Subtask deleted',
                "{$actor->name} deleted {$subtask->title} from {$task->task_code}.",
            );
            if ($task->task_type === 'volume') {
                $subtask->volumeUpdates()->where('task_id', $task->id)->delete();
                $task->load('volume');
                $summary = $this->volumes->summary($task);
                $task->update(['progress_percentage' => $summary['percentage']]);
            }
            $subtask->delete();
            if ($task->task_type !== 'volume') {
                $this->progress->sync($task);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:100'],
            'order.*' => ['required', 'integer', 'distinct'],
        ]);

        $subtaskIds = $task->subtasks()->whereIn('id', $validated['order'])->pluck('id')->map(fn ($id): int => (int) $id);
        if ($subtaskIds->count() !== count($validated['order'])) {
            throw ValidationException::withMessages([
                'order' => 'The subtask order contains an item outside this task.',
            ]);
        }

        DB::transaction(function () use ($task, $validated): void {
            foreach ($validated['order'] as $index => $subtaskId) {
                $task->subtasks()->whereKey($subtaskId)->update(['sort_order' => $index]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createSubtask(
        Task $task,
        array $data,
        User $actor,
        int $sortOrder,
        ?string $assignedUserName = null,
    ): Subtask {
        $workDate = $task->task_type === 'volume' ? ($data['work_date'] ?? null) : null;
        unset($data['work_date']);

        $subtask = $task->subtasks()->create([
            ...$data,
            'created_by' => $actor->id,
            'is_completed' => false,
            'sort_order' => $sortOrder,
        ]);

        if ($task->task_type === 'volume') {
            $this->volumes->syncFromSubtask(
                $task,
                $subtask,
                $actor,
                $workDate ?? today(config('app.timezone'))->toDateString(),
                $subtask->description,
            );
        }

        $this->activity->record(
            $task,
            $subtask,
            $actor,
            'created',
            null,
            $subtask->status,
            "{$actor->name} created subtask \"{$subtask->title}\"",
        );
        if ($assignedUserName !== null) {
            $this->activity->record(
                $task,
                $subtask,
                $actor,
                'assignment',
                null,
                $assignedUserName,
                "assigned subtask \"{$subtask->title}\" to {$assignedUserName}",
            );
        }
        $this->activity->notifyParticipants(
            $task,
            $subtask,
            $actor,
            'created',
            'New subtask',
            "{$actor->name} created {$subtask->title} for {$task->task_code}.",
        );

        return $subtask;
    }

    private function applyStatus(Subtask $subtask, string $status, User $actor): void
    {
        $this->applyStatusAndProgress(
            $subtask,
            $status,
            $status === 'completed' ? 100 : (int) $subtask->progress_percentage,
            $actor,
        );
    }

    private function applyStatusAndProgress(Subtask $subtask, string $status, int $progress, User $actor): void
    {
        $now = now();
        $isCompleted = $status === 'completed';
        $actualStartedAt = $subtask->actual_started_at;
        if ($actualStartedAt === null && in_array($status, ['in_progress', 'in_review', 'completed'], true)) {
            $actualStartedAt = $now;
        }
        $completedAt = $isCompleted
            ? ($subtask->status === 'completed' && $subtask->completed_at ? $subtask->completed_at : $now)
            : null;
        $elapsedMinutes = $isCompleted && $actualStartedAt !== null
            ? max(0, (int) $actualStartedAt->diffInMinutes($completedAt))
            : ($status === 'on_hold' && $actualStartedAt !== null
                ? max(0, (int) $actualStartedAt->diffInMinutes($now))
                : $subtask->actual_elapsed_minutes);

        $subtask->forceFill([
            'status' => $status,
            'is_completed' => $isCompleted,
            'progress_percentage' => $isCompleted ? 100 : $progress,
            'completed_at' => $completedAt,
            'completed_by' => $isCompleted
                ? ($subtask->status === 'completed' ? $subtask->completed_by : $actor->id)
                : null,
            'actual_started_at' => $actualStartedAt,
            'actual_start_at' => $subtask->actual_start_at ?? $actualStartedAt,
            'actual_elapsed_minutes' => $elapsedMinutes,
            'actual_hours' => $elapsedMinutes === null
                ? $subtask->actual_hours
                : number_format($elapsedMinutes / 60, 2, '.', ''),
        ]);
    }

    private function validateSubtaskDates(Task $task, ?string $startDate, ?string $dueDate, string $fieldPrefix = ''): void
    {
        $errors = [];
        $parentStart = $task->start_date?->toDateString();
        $parentDeadline = ($task->due_date ?? $task->original_deadline)?->toDateString();

        if ($startDate !== null && $parentStart !== null && Carbon::parse($startDate)->lt(Carbon::parse($parentStart))) {
            $errors[$fieldPrefix.'start_date'] = 'A subtask cannot start before the main task start date.';
        }
        if ($dueDate !== null && $parentDeadline !== null && Carbon::parse($dueDate)->gt(Carbon::parse($parentDeadline))) {
            $errors[$fieldPrefix.'due_date'] = 'A subtask deadline cannot exceed the main task deadline.';
        }
        if ($startDate !== null && $dueDate !== null && Carbon::parse($startDate)->gt(Carbon::parse($dueDate))) {
            $errors[$fieldPrefix.'start_date'] = 'A subtask start date cannot be later than its deadline.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateAssignee(Task $task, int|string|null $assigneeId, string $fieldPrefix = ''): void
    {
        if ($assigneeId === null) {
            return;
        }

        $isEligibleAssignee = User::query()
            ->whereKey((int) $assigneeId)
            ->where('status', 'active')
            ->where('company_id', $task->company_id)
            ->where('department_id', $task->department_id)
            ->exists();

        if (! $isEligibleAssignee) {
            throw ValidationException::withMessages([
                $fieldPrefix.'assigned_to' => 'Select an active employee from the main task department.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $fields
     */
    private function hasManagerChanges(Subtask $subtask, array $validated, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $current = match ($field) {
                'assigned_to' => $subtask->assigned_to === null ? null : (int) $subtask->assigned_to,
                'start_date' => $subtask->start_date?->format('Y-m-d'),
                'due_date' => $subtask->due_date?->format('Y-m-d'),
                'estimated_hours', 'actual_hours' => $subtask->{$field} === null
                    ? null
                    : number_format((float) $subtask->{$field}, 2, '.', ''),
                'description' => $subtask->description,
                default => $subtask->{$field},
            };
            $candidate = match ($field) {
                'assigned_to' => $validated[$field] === null ? null : (int) $validated[$field],
                'estimated_hours', 'actual_hours' => $validated[$field] === null
                    ? null
                    : number_format((float) $validated[$field], 2, '.', ''),
                default => $validated[$field],
            };

            if ($current !== $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSubtask(Subtask $subtask): array
    {
        $isCompleted = $subtask->is_completed || $subtask->status === 'completed';
        $status = $isCompleted ? 'completed' : ($subtask->status ?? 'todo');
        $volumeUpdate = $subtask->relationLoaded('volumeUpdates')
            ? $subtask->volumeUpdates->sortByDesc('update_date')->first()
            : null;

        return [
            'id' => $subtask->id,
            'title' => $subtask->title,
            'description' => $subtask->description ?? '',
            'priority' => $subtask->priority,
            'status' => $status,
            'status_label' => Subtask::STATUS_LABELS[$status] ?? 'Pending',
            'is_completed' => $isCompleted,
            'progress_percentage' => (int) $subtask->progress_percentage,
            'start_date' => $subtask->start_date?->toDateString(),
            'due_date' => $subtask->due_date?->toDateString(),
            'estimated_hours' => $subtask->estimated_hours,
            'actual_hours' => $subtask->actual_hours,
            'sort_order' => $subtask->sort_order,
            'created_at_formatted' => $subtask->created_at?->format('d M Y, h:i A'),
            'completed_at_formatted' => $subtask->completed_at?->format('d M Y, h:i A'),
            'volume_update' => $volumeUpdate === null ? null : [
                'work_date' => $volumeUpdate->update_date?->toDateString(),
            ],
            'created_by' => [
                'id' => $subtask->creator?->id,
                'name' => $subtask->creator?->name ?? 'Unknown user',
                'designation' => $subtask->creator?->designation,
            ],
            'assigned_to' => [
                'id' => $subtask->assignee?->id,
                'name' => $subtask->assignee?->name ?? 'Unassigned',
                'designation' => $subtask->assignee?->designation,
            ],
            'completed_by' => [
                'id' => $subtask->completedBy?->id,
                'name' => $subtask->completedBy?->name,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareNewSubtaskData(Task $task, array $data): array
    {
        $parentDeadline = $task->due_date ?? $task->original_deadline;
        $data['assigned_to'] = isset($data['assigned_to']) ? (int) $data['assigned_to'] : null;
        $data['priority'] = $data['priority'] ?? 'medium';
        $data['status'] = $data['status'] ?? ($data['assigned_to'] ? 'assigned' : 'todo');
        $data['start_date'] = $data['start_date'] ?? null;
        $data['due_date'] = $data['due_date'] ?? $parentDeadline?->format('Y-m-d');
        $data['estimated_hours'] = $data['estimated_hours'] ?? null;
        $data['progress_percentage'] = (int) ($data['progress_percentage'] ?? 0);

        if ($data['status'] === 'completed') {
            $data['progress_percentage'] = 100;
        } elseif ($data['progress_percentage'] === 100) {
            throw ValidationException::withMessages([
                'progress_percentage' => 'Set the status to Completed to reach 100% progress.',
            ]);
        }

        return $data;
    }
}

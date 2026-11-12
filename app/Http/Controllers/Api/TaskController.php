<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Services\SubtaskActivityService;
use App\Services\SubtaskProgressService;
use App\Services\TaskAssignmentService;
use App\Services\TaskAttachmentService;
use App\Services\TaskQueryService;
use App\Services\TaskWorkflowService;
use App\Support\DesktopApiPresenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskQueryService $query,
        private readonly TaskAssignmentService $assignments,
        private readonly TaskAttachmentService $attachments,
        private readonly TaskWorkflowService $workflow,
        private readonly SubtaskProgressService $progress,
        private readonly SubtaskActivityService $subtaskActivity,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $filters = $this->query->validatedFilters($request);
        $sort = $filters['sort'] ?? 'recently_updated';

        $tasks = $this->query->applySort(
            $this->query->filteredTasksQuery($filters, $request->user()),
            $sort,
        )
            ->with([
                'company:id,name',
                'department:id,name',
                'assignee:id,name,designation',
                'activeAssignees:id,name,designation',
                'pocs:id,name,designation',
                'creator:id,name',
            ])
            ->withCount([
                'subtasks',
                'subtasks as completed_subtasks_count' => fn ($query) => $query->where('is_completed', true),
            ])
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->appends($filters);

        return response()->json([
            'data' => $tasks->getCollection()
                ->map(fn (Task $task): array => DesktopApiPresenter::taskSummary($task))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
            'counts' => $this->query->counts($request->user()),
            'filters' => $filters,
        ]);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $this->authorize('create', Task::class);

        $validated = $request->validated();
        $department = Department::query()
            ->where('id', $validated['department_id'])
            ->where('company_id', $validated['company_id'])
            ->where('status', 'active')
            ->first();

        if (! $department) {
            throw ValidationException::withMessages([
                'department_id' => 'Selected department does not belong to the selected company.',
            ]);
        }

        $assigneeIds = $validated['assigned_to'] ?? [];
        if (! $this->assignments->assigneesBelongToDepartment($assigneeIds, $validated['company_id'], $validated['department_id'])) {
            throw ValidationException::withMessages([
                'assigned_to' => 'All assigned employees must be active in the selected department.',
            ]);
        }

        $pocIds = $validated['poc_ids'] ?? [];
        if ($pocIds !== []) {
            $validPocCount = User::query()
                ->whereIn('id', $pocIds)
                ->where('company_id', $validated['company_id'])
                ->where('department_id', $validated['department_id'])
                ->where('status', 'active')
                ->count();

            if ($validPocCount !== count($pocIds)) {
                throw ValidationException::withMessages([
                    'poc_ids' => 'One or more selected POCs do not belong to the selected department.',
                ]);
            }
        }

        $taskData = collect($validated)->except(['assigned_to', 'poc_ids', 'attachments'])->merge([
            'task_code' => Task::generateCode(),
            'created_by' => $request->user()->id,
            'status' => empty($assigneeIds) ? 'notified' : 'assigned',
            'assigned_to' => $assigneeIds[0] ?? null,
        ])->all();
        $storedAttachments = $this->attachments->storeAttachmentFiles($validated['attachments'] ?? []);

        try {
            $task = DB::transaction(function () use ($taskData, $pocIds, $assigneeIds, $storedAttachments, $request): Task {
                $task = Task::create($taskData);

                if ($pocIds !== []) {
                    $task->pocs()->sync($pocIds);
                }

                $this->assignments->syncTaskAssignees($task, $assigneeIds, 'Initial assignment', $request->user()->id);
                $this->attachments->createAttachmentRecords($task, $storedAttachments, $request->user()->id);
                $task->statusHistory()->create([
                    'changed_by' => $request->user()->id,
                    'new_status' => $task->status,
                    'event_type' => 'status',
                    'remarks' => 'Initial task status.',
                ]);
                $this->workflow->notifyTaskCreated($task, $request->user());

                return $task;
            });
        } catch (Throwable $exception) {
            $this->attachments->deleteStoredAttachmentFiles($storedAttachments);
            throw $exception;
        }

        return response()->json([
            'message' => 'Task created successfully.',
            'task' => DesktopApiPresenter::taskDetail($task, $request->user(), $this->workflow, $this->progress),
        ], 201);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json([
            'task' => DesktopApiPresenter::taskDetail($task, $request->user(), $this->workflow, $this->progress),
        ]);
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $validated = $request->validated();
        $this->preventConflictingSubtaskTimeframeChange($task, $validated, $request->user());

        if (! empty($validated['department_id']) && ! empty($validated['company_id'])) {
            $department = Department::query()
                ->where('id', $validated['department_id'])
                ->where('company_id', $validated['company_id'])
                ->first();

            if (! $department) {
                throw ValidationException::withMessages([
                    'department_id' => 'Selected department does not belong to the selected company.',
                ]);
            }
        }

        $hasAssigneeUpdate = array_key_exists('assigned_to', $validated);
        $assigneeIds = $validated['assigned_to'] ?? [];
        if ($task->status === 'notified' && $assigneeIds !== []) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The department point of contact must accept the task before employee assignment.',
            ]);
        }

        if ($hasAssigneeUpdate && $assigneeIds === []
            && in_array($task->status, ['in_progress', 'on_hold', 'submitted_for_review', 'rework'], true)) {
            throw ValidationException::withMessages([
                'assigned_to' => 'An active task must remain assigned to an employee.',
            ]);
        }

        if ($hasAssigneeUpdate && ! $this->assignments->assigneesBelongToDepartment(
            $assigneeIds,
            $validated['company_id'],
            $validated['department_id'],
        )) {
            throw ValidationException::withMessages([
                'assigned_to' => 'All assigned employees must be active in the selected department.',
            ]);
        }

        $pocIds = $validated['poc_ids'] ?? null;
        if ($pocIds !== null && $pocIds !== []) {
            $deptId = $validated['department_id'] ?? $task->department_id;
            $validPocCount = User::query()
                ->whereIn('id', $pocIds)
                ->where('company_id', $validated['company_id'] ?? $task->company_id)
                ->where('department_id', $deptId)
                ->where('status', 'active')
                ->count();

            if ($validPocCount !== count($pocIds)) {
                throw ValidationException::withMessages([
                    'poc_ids' => 'One or more selected POCs do not belong to the selected department.',
                ]);
            }
        }

        unset($validated['original_deadline']);
        $taskData = collect($validated)->except(['assigned_to', 'poc_ids', 'attachments'])->all();
        if ($hasAssigneeUpdate) {
            $taskData['assigned_to'] = $assigneeIds[0] ?? null;
        }
        $storedAttachments = $this->attachments->storeAttachmentFiles($validated['attachments'] ?? []);

        try {
            DB::transaction(function () use ($task, $taskData, $pocIds, $hasAssigneeUpdate, $assigneeIds, $storedAttachments, $request): void {
                if ($hasAssigneeUpdate) {
                    $this->assignments->syncTaskAssignees($task, $assigneeIds, 'Updated task assignment', $request->user()->id);
                }

                $task->update($taskData);
                if ($hasAssigneeUpdate) {
                    $this->assignments->synchronizeAssignmentStatus($task, $request->user()->id);
                }

                if ($pocIds !== null) {
                    $task->pocs()->sync($pocIds);
                }

                $this->attachments->createAttachmentRecords($task, $storedAttachments, $request->user()->id);
            });
        } catch (Throwable $exception) {
            $this->attachments->deleteStoredAttachmentFiles($storedAttachments);
            throw $exception;
        }

        return response()->json([
            'message' => 'Task updated successfully.',
            'task' => DesktopApiPresenter::taskDetail($task->fresh(), $request->user(), $this->workflow, $this->progress),
        ]);
    }

    public function accept(Request $request, Task $task): JsonResponse
    {
        $this->workflow->acceptNotifiedTask($task, $request->user());

        return response()->json([
            'message' => 'Task accepted and ready for employee assignment.',
            'task' => DesktopApiPresenter::taskDetail($task->fresh(), $request->user(), $this->workflow, $this->progress),
        ]);
    }

    public function startWork(Request $request, Task $task): JsonResponse
    {
        $this->authorize('startWork', $task);
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'start_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $this->workflow->startWork($task, $validated['note'], $validated['start_date'], $request->user());

        return response()->json([
            'message' => 'Work started successfully.',
            'task' => DesktopApiPresenter::taskDetail($task->fresh(), $request->user(), $this->workflow, $this->progress),
        ]);
    }

    public function addWorkUpdate(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
            'update_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $this->workflow->addWorkUpdate($task, $validated['note'], $validated['update_date'], $request->user());

        return response()->json([
            'message' => 'Work update added successfully.',
            'task' => DesktopApiPresenter::taskDetail($task->fresh(), $request->user(), $this->workflow, $this->progress),
        ]);
    }

    public function resume(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->workflow->resumeWork($task, $validated['note'] ?? null, $request->user());

        return response()->json([
            'message' => 'Work resumed successfully.',
            'task' => DesktopApiPresenter::taskDetail($task->fresh(), $request->user(), $this->workflow, $this->progress),
        ]);
    }

    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Task::STATUSES)],
            'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf(
                in_array($request->input('status'), ['on_hold', 'cancelled', 'submitted_for_review'], true),
            )],
            'update_date' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf(
                in_array($request->input('status'), ['on_hold', 'submitted_for_review'], true),
            )],
        ]);

        $this->authorize(match ($validated['status']) {
            'cancelled' => 'cancel',
            'submitted_for_review' => 'submitForReview',
            default => 'changeStatus',
        }, $task);

        $this->workflow->changeStatus(
            $task,
            $validated['status'],
            $validated['reason'] ?? null,
            $request->user(),
            $validated['update_date'] ?? null,
        );

        $statusLabel = Task::STATUS_LABELS[$validated['status']] ?? ucfirst($validated['status']);

        return response()->json([
            'message' => "Task status updated to {$statusLabel}.",
            'task' => DesktopApiPresenter::taskDetail($task->fresh(), $request->user(), $this->workflow, $this->progress),
        ]);
    }

    public function updateRisk(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['flag', 'resolve'])],
            'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf($request->input('action') === 'flag')],
        ]);

        $this->authorize('flagRisk', $task);
        $this->workflow->updateRisk($task, $validated['action'], $validated['reason'] ?? null, $request->user());

        return response()->json([
            'message' => $validated['action'] === 'flag' ? 'Task flagged as at risk.' : 'Task risk flag resolved.',
            'task' => DesktopApiPresenter::taskDetail($task->fresh(), $request->user(), $this->workflow, $this->progress),
        ]);
    }

    public function review(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'rework'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->authorize('review', $task);
        $this->workflow->review($task, $validated['decision'], $validated['comment'] ?? null, $request->user());

        return response()->json([
            'message' => $validated['decision'] === 'approve'
                ? 'Task approved and completed.'
                : 'Task returned for re-work.',
            'task' => DesktopApiPresenter::taskDetail($task->fresh(), $request->user(), $this->workflow, $this->progress),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function preventConflictingSubtaskTimeframeChange(Task $task, array $validated, User $actor): void
    {
        $oldStartDate = $task->start_date?->toDateString();
        $oldDeadline = ($task->due_date ?? $task->original_deadline)?->toDateString();
        $newStartDate = array_key_exists('start_date', $validated)
            ? $validated['start_date']
            : $oldStartDate;
        $newDeadline = array_key_exists('due_date', $validated)
            ? ($validated['due_date'] ?? $task->original_deadline?->toDateString())
            : $oldDeadline;
        $startDateChanged = array_key_exists('start_date', $validated) && $newStartDate !== $oldStartDate;
        $deadlineChanged = array_key_exists('due_date', $validated)
            && ($validated['due_date'] ?? null) !== $task->due_date?->toDateString();

        if (! $startDateChanged && ! $deadlineChanged) {
            return;
        }

        $subtasks = $task->subtasks()
            ->get(['id', 'task_id', 'title', 'start_date', 'due_date', 'assigned_to', 'created_by']);
        $startConflicts = $startDateChanged && $newStartDate !== null
            ? $subtasks->filter(fn ($subtask): bool => $subtask->start_date !== null
                && $subtask->start_date->lt(Carbon::parse($newStartDate)))
            : collect();
        $deadlineConflicts = $deadlineChanged && $newDeadline !== null
            ? $subtasks->filter(fn ($subtask): bool => $subtask->due_date !== null
                && $subtask->due_date->gt(Carbon::parse($newDeadline)))
            : collect();
        $conflicts = $startConflicts->merge($deadlineConflicts)->unique('id')->values();

        if ($conflicts->isEmpty()) {
            return;
        }

        DB::transaction(fn () => $this->subtaskActivity->recordParentDeadlineConflicts(
            $task,
            $conflicts,
            $actor,
            $oldDeadline,
            $newDeadline,
            $oldStartDate,
            $newStartDate,
        ));

        $errors = [];
        if ($startConflicts->isNotEmpty()) {
            $errors['start_date'] = 'The new main task start date conflicts with subtasks: '.$startConflicts->pluck('title')->implode(', ').'. Correct their dates before saving.';
        }
        if ($deadlineConflicts->isNotEmpty()) {
            $errors['due_date'] = 'The new main task deadline conflicts with subtasks: '.$deadlineConflicts->pluck('title')->implode(', ').'. Correct their dates before saving.';
        }

        throw ValidationException::withMessages($errors);
    }
}

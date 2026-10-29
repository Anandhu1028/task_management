<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskAttachment;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Services\RecurringTaskTemplateService;
use App\Services\SubtaskActivityService;
use App\Services\SubtaskProgressService;
use App\Services\TaskAttachmentService;
use App\Services\TaskWorkflowService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TaskController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        $filters = $this->validatedFilters($request);

        $tasks = $this->applyKanbanSort(
            $this->filteredTasksQuery($filters, $request->user()),
            $filters['sort'] ?? 'deadline_priority',
        )
            ->with([
                'company:id,name',
                'department:id,name',
                'assignee:id,name,designation',
                'activeAssignees:id,name,designation',
                'pocs:id,name,designation',
                'recurringTemplate:id,title,repeat_frequency,start_date,end_date,status',
            ])
            ->withExists([
                'deadlineExtensionRequests as has_pending_deadline_extension_request' => fn (Builder $query) => $query
                    ->where('status', 'pending'),
            ])
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->appends($filters);

        $kanbanTasks = $this->applyKanbanSort(
            $this->filteredTasksQuery($filters, $request->user()),
            $filters['sort'] ?? 'deadline_priority',
        )
            ->with([
                'company:id,name',
                'department:id,name',
                'assignee:id,name,designation',
                'activeAssignees:id,name,designation',
                'pocs:id,name,designation',
            ])
            ->get()
            ->groupBy('status');

        $counts = Task::query()
            ->visibleTo($request->user())
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('notified', 'unassigned', 'assigned') THEN 1 ELSE 0 END) as pending_assignment_count")
            ->selectRaw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count")
            ->first();
        $counts->overdue_count = Task::query()
            ->visibleTo($request->user())
            ->overdue()
            ->count();
        $counts->at_risk_count = Task::query()
            ->visibleTo($request->user())
            ->atRisk()
            ->count();

        $companies = Company::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $departments = Department::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'company_id']);
        $employees = User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'company_id', 'department_id', 'designation']);

        $editingTask = null;
        if ($request->filled('edit')) {
            $editingTask = Task::query()
                ->visibleTo($request->user())
                ->with(['company', 'department', 'pocs', 'assignee', 'activeAssignees'])
                ->find($request->integer('edit'));
        }

        return view('tasks.index', compact(
            'tasks',
            'kanbanTasks',
            'filters',
            'counts',
            'companies',
            'departments',
            'employees',
            'editingTask',
        ));
    }

    public function store(
        StoreTaskRequest $request,
        TaskWorkflowService $workflow,
        RecurringTaskTemplateService $recurringTemplates,
        TaskAttachmentService $attachments,
    ): RedirectResponse {
        $this->authorize('create', Task::class);

        $validated = $request->validated();

        // Validate company/department belong together
        $department = Department::query()
            ->where('id', $validated['department_id'])
            ->where('company_id', $validated['company_id'])
            ->where('status', 'active')
            ->first();

        if (! $department) {
            return back()->withErrors(['department_id' => 'Selected department does not belong to the selected company.'])->withInput();
        }

        $assigneeIds = $validated['assigned_to'] ?? [];
        if (! $this->assigneesBelongToDepartment($assigneeIds, $validated['company_id'], $validated['department_id'])) {
            return back()->withErrors(['assigned_to' => 'All assigned employees must be active in the selected department.'])->withInput();
        }

        // Validate POCs belong to the selected department
        $pocIds = $validated['poc_ids'] ?? [];
        if (! empty($pocIds)) {
            $validPocCount = User::query()
                ->whereIn('id', $pocIds)
                ->where('company_id', $validated['company_id'])
                ->where('department_id', $validated['department_id'])
                ->where('status', 'active')
                ->count();

            if ($validPocCount !== count($pocIds)) {
                return back()->withErrors(['poc_ids' => 'One or more selected POCs do not belong to the selected department.'])->withInput();
            }
        }

        if ($validated['task_type'] === 'recurring') {
            $storedAttachments = $attachments->storeAttachmentFiles($validated['attachments'] ?? []);

            try {
                $recurringTemplates->create(
                    $validated,
                    $pocIds,
                    $assigneeIds,
                    $storedAttachments,
                    $request->user(),
                );
            } catch (Throwable $exception) {
                $attachments->deleteStoredAttachmentFiles($storedAttachments);
                throw $exception;
            }

            return redirect()
                ->route('tasks.index')
                ->with('success', 'Recurring task schedule created successfully.')
                ->with('operation_status', [
                    'kind' => 'task',
                    'title' => 'Task schedule created',
                    'message' => 'Recurring task schedule created successfully.',
                ]);
        }

        $taskData = collect($validated)->except(['assigned_to', 'poc_ids', 'attachments'])->merge([
            'task_code' => Task::generateCode(),
            'created_by' => auth()->id(),
            'status' => empty($assigneeIds) ? 'notified' : 'assigned',
            'assigned_to' => $assigneeIds[0] ?? null,
        ])->all();
        $storedAttachments = $this->storeAttachmentFiles($validated['attachments'] ?? []);

        try {
            DB::transaction(function () use ($taskData, $pocIds, $assigneeIds, $storedAttachments, $workflow, $request): void {
                $task = Task::create($taskData);

                if (! empty($pocIds)) {
                    $task->pocs()->sync($pocIds);
                }

                $this->syncTaskAssignees($task, $assigneeIds, 'Initial assignment', auth()->id());
                $this->createAttachmentRecords($task, $storedAttachments, auth()->id());
                $task->statusHistory()->create([
                    'changed_by' => auth()->id(),
                    'new_status' => $task->status,
                    'event_type' => 'status',
                    'remarks' => 'Initial task status.',
                ]);
                $workflow->notifyTaskCreated($task, $request->user());
            });
        } catch (Throwable $exception) {
            $this->deleteStoredAttachmentFiles($storedAttachments);
            throw $exception;
        }

        return redirect()->route('tasks.index')
            ->with('success', 'Task created successfully.')
            ->with('operation_status', [
                'kind' => 'task',
                'title' => 'Task created',
                'message' => 'Task created successfully.',
            ]);
    }

    public function show(
        Request $request,
        Task $task,
        TaskWorkflowService $workflow,
        SubtaskProgressService $subtaskProgress,
    ): View {
        $this->authorize('view', $task);

        $task->load([
            'company',
            'department',
            'assignee',
            'activeAssignees',
            'creator',
            'pocs',
            'assignments.user',
            'assignments.assignedBy',
            'attachments.uploadedBy',
            'recurringTemplate.assignees:id,name',
            'statusHistory.changedBy',
            'subtasks.creator',
            'subtasks.assignee',
            'subtasks.completedBy',
        ]);

        $companies = Company::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']);
        $departments = Department::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'company_id']);
        $employees = User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'company_id', 'department_id', 'designation']);
        $statusOptions = $workflow->availableTransitions($task, $request->user());
        $canFlagRisk = $request->user()->can('flagRisk', $task);
        $canReviewSubmission = $request->user()->can('review', $task);
        $canAcceptTask = $request->user()->can('accept', $task);
        $canStartWork = $request->user()->can('startWork', $task);
        $canAddWorkUpdate = $request->user()->can('addWorkUpdate', $task);
        $canResumeWork = $request->user()->can('resume', $task);
        $canManageSubtasks = $request->user()->can('update', $task);
        $canUpdateSubtaskProgress = $request->user()->can('changeStatus', $task);
        $taskProgress = $subtaskProgress->summary($task);
        $lastHeldStatus = $task->statusHistory
            ->where('event_type', 'hold')
            ->where('new_status', 'on_hold')
            ->sortByDesc('id')
            ->first()?->old_status;
        $resumeToReview = $lastHeldStatus === 'submitted_for_review';
        $canReviewDeadlineExtensions = $request->user()->can('reviewDeadlineExtensions', $task);
        $deadlineExtensionRequests = $task->deadlineExtensionRequests()
            ->with(['requester:id,name', 'reviewer:id,name'])
            ->when(
                ! $canReviewDeadlineExtensions,
                fn (Builder $query) => $query->where('requested_by', $request->user()->id),
            )
            ->get();

        return view('tasks.show', compact(
            'task',
            'companies',
            'departments',
            'employees',
            'statusOptions',
            'canFlagRisk',
            'canReviewSubmission',
            'canAcceptTask',
            'canStartWork',
            'canAddWorkUpdate',
            'canResumeWork',
            'canManageSubtasks',
            'canUpdateSubtaskProgress',
            'taskProgress',
            'resumeToReview',
            'canReviewDeadlineExtensions',
            'deadlineExtensionRequests',
        ));
    }

    public function edit(Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        return redirect()->route('tasks.index', ['edit' => $task->id]);
    }

    public function update(
        UpdateTaskRequest $request,
        Task $task,
        TaskWorkflowService $workflow,
        SubtaskActivityService $subtaskActivity,
    ): RedirectResponse|JsonResponse {
        $this->authorize('update', $task);

        $validated = $request->validated();
        $this->preventConflictingSubtaskTimeframeChange($task, $validated, $request->user(), $subtaskActivity);

        // Validate company/department belong together
        if (! empty($validated['department_id']) && ! empty($validated['company_id'])) {
            $department = Department::query()
                ->where('id', $validated['department_id'])
                ->where('company_id', $validated['company_id'])
                ->first();

            if (! $department) {
                return $this->taskUpdateFailure($request, [
                    'department_id' => 'Selected department does not belong to the selected company.',
                ]);
            }
        }

        $hasAssigneeUpdate = array_key_exists('assigned_to', $validated);
        $assigneeIds = $validated['assigned_to'] ?? [];
        if ($task->status === 'notified' && $assigneeIds !== []) {
            return $this->taskUpdateFailure($request, [
                'assigned_to' => 'The department point of contact must accept the task before employee assignment.',
            ]);
        }

        if ($hasAssigneeUpdate && $assigneeIds === []
            && in_array($task->status, ['in_progress', 'on_hold', 'submitted_for_review', 'rework'], true)) {
            return $this->taskUpdateFailure($request, [
                'assigned_to' => 'An active task must remain assigned to an employee.',
            ]);
        }

        if ($hasAssigneeUpdate && ! $this->assigneesBelongToDepartment(
            $assigneeIds,
            $validated['company_id'],
            $validated['department_id'],
        )) {
            return $this->taskUpdateFailure($request, [
                'assigned_to' => 'All assigned employees must be active in the selected department.',
            ]);
        }

        // Validate POCs
        $pocIds = $validated['poc_ids'] ?? null;
        if ($pocIds !== null) {
            $deptId = $validated['department_id'] ?? $task->department_id;
            if (! empty($pocIds)) {
                $validPocCount = User::query()
                    ->whereIn('id', $pocIds)
                    ->where('company_id', $validated['company_id'] ?? $task->company_id)
                    ->where('department_id', $deptId)
                    ->where('status', 'active')
                    ->count();

                if ($validPocCount !== count($pocIds)) {
                    return $this->taskUpdateFailure($request, [
                        'poc_ids' => 'One or more selected POCs do not belong to the selected department.',
                    ]);
                }
            }
        }

        // Original deadline is immutable after creation
        unset($validated['original_deadline']);

        $taskData = collect($validated)->except(['assigned_to', 'poc_ids', 'attachments'])->all();
        if ($hasAssigneeUpdate) {
            $taskData['assigned_to'] = $assigneeIds[0] ?? null;
        }
        $storedAttachments = $this->storeAttachmentFiles($validated['attachments'] ?? []);

        try {
            DB::transaction(function () use ($task, $taskData, $pocIds, $hasAssigneeUpdate, $assigneeIds, $storedAttachments, $workflow, $request): void {
                if ($hasAssigneeUpdate) {
                    $this->syncTaskAssignees($task, $assigneeIds, 'Updated task assignment', auth()->id());
                }

                $task->update($taskData);
                if ($hasAssigneeUpdate) {
                    $this->synchronizeAssignmentStatus($task);
                }

                if ($pocIds !== null) {
                    $task->pocs()->sync($pocIds);
                }

                $this->createAttachmentRecords($task, $storedAttachments, auth()->id());

                $updateDetails = ['task details updated'];
                if ($hasAssigneeUpdate) {
                    $assigneeNames = User::query()
                        ->whereIn('id', $assigneeIds)
                        ->orderBy('name')
                        ->pluck('name')
                        ->implode(', ');
                    $updateDetails[] = $assigneeNames === ''
                        ? 'employee assignment cleared'
                        : "assigned to {$assigneeNames}";
                }
                if ($pocIds !== null) {
                    $pocNames = $task->pocs()->orderBy('users.name')->pluck('users.name')->implode(', ');
                    $updateDetails[] = $pocNames === ''
                        ? 'no department POC assigned'
                        : "department POCs: {$pocNames}";
                }

                $workflow->notifyTaskParticipants(
                    $task,
                    $request->user(),
                    $hasAssigneeUpdate && $assigneeIds !== [] ? 'Task assigned' : 'Task details updated',
                    "updated {$task->task_code}: ".implode('; ', $updateDetails).'.',
                );
            });
        } catch (Throwable $exception) {
            $this->deleteStoredAttachmentFiles($storedAttachments);
            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Task updated successfully.']);
        }

        return redirect()->route('tasks.index')
            ->with('success', 'Task updated successfully.')
            ->with('operation_status', [
                'kind' => 'task',
                'title' => 'Task updated',
                'message' => 'Task updated successfully.',
            ]);
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function taskUpdateFailure(UpdateTaskRequest $request, array $errors): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The task could not be updated.',
                'errors' => $errors,
            ], 422);
        }

        return back()->withErrors($errors)->withInput();
    }

    public function downloadAttachment(Task $task, TaskAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $task);
        abort_unless($attachment->task_id === $task->id, 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function previewAttachment(Task $task, TaskAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $task);
        abort_unless($attachment->task_id === $task->id, 404);

        $previewContentTypes = [
            'application/pdf' => 'application/pdf',
            'image/jpeg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            'text/csv' => 'text/plain; charset=UTF-8',
            'text/plain' => 'text/plain; charset=UTF-8',
        ];
        abort_unless(isset($previewContentTypes[$attachment->mime_type]), 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $previewContentTypes[$attachment->mime_type],
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);

        $task->delete();

        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    public function accept(Task $task, Request $request, TaskWorkflowService $workflow): RedirectResponse
    {
        $shouldOpenAssignment = ! $task->activeAssignees()->exists()
            && $request->user()->can('update', $task);

        $workflow->acceptNotifiedTask($task, $request->user());

        $response = $this->redirectWithTaskOperation(
            $task,
            'Task accepted',
            'Task accepted and ready for employee assignment.',
        );

        if ($shouldOpenAssignment) {
            $response->with('auto_open_reassign', true);
        }

        return $response;
    }

    public function startWork(Request $request, Task $task, TaskWorkflowService $workflow): RedirectResponse
    {
        $this->authorize('startWork', $task);
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp'],
        ]);
        $storedAttachments = $this->storeAttachmentFiles($validated['attachments'] ?? []);

        try {
            DB::transaction(function () use ($task, $validated, $request, $workflow, $storedAttachments): void {
                $history = $workflow->startWork($task, $validated['note'], $validated['start_date'], $request->user());
                $this->createAttachmentRecords($task, $storedAttachments, $request->user()->id, $history);
            });
        } catch (Throwable $exception) {
            $this->deleteStoredAttachmentFiles($storedAttachments);
            throw $exception;
        }

        return $this->redirectWithTaskOperation($task, 'Work started', 'Work started successfully.');
    }

    public function addWorkUpdate(Request $request, Task $task, TaskWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
            'update_date' => ['required', 'date_format:Y-m-d'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp'],
        ]);
        $storedAttachments = $this->storeAttachmentFiles($validated['attachments'] ?? []);

        try {
            DB::transaction(function () use ($task, $validated, $request, $workflow, $storedAttachments): void {
                $history = $workflow->addWorkUpdate($task, $validated['note'], $validated['update_date'], $request->user());
                $this->createAttachmentRecords($task, $storedAttachments, $request->user()->id, $history);
            });
        } catch (Throwable $exception) {
            $this->deleteStoredAttachmentFiles($storedAttachments);
            throw $exception;
        }

        return $this->redirectWithTaskOperation($task, 'Work update added', 'Work update added successfully.');
    }

    public function resumeWork(Request $request, Task $task, TaskWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $workflow->resumeWork($task, $validated['note'] ?? null, $request->user());

        return $this->redirectWithTaskOperation($task, 'Work resumed', 'Work resumed successfully.');
    }

    public function updateStatus(Request $request, Task $task, TaskWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Task::STATUSES)],
            'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf(
                in_array($request->input('status'), ['on_hold', 'cancelled', 'submitted_for_review'], true),
            )],
            'update_date' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf(
                in_array($request->input('status'), ['on_hold', 'submitted_for_review'], true),
            )],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp'],
        ]);

        $this->authorize(match ($validated['status']) {
            'cancelled' => 'cancel',
            'submitted_for_review' => 'submitForReview',
            default => 'changeStatus',
        }, $task);
        $storedAttachments = in_array($validated['status'], ['on_hold', 'submitted_for_review'], true)
            ? $this->storeAttachmentFiles($validated['attachments'] ?? [])
            : [];

        try {
            DB::transaction(function () use ($workflow, $task, $validated, $request, $storedAttachments): void {
                $history = $workflow->changeStatus(
                    $task,
                    $validated['status'],
                    $validated['reason'] ?? null,
                    $request->user(),
                    $validated['update_date'] ?? null,
                );
                $this->createAttachmentRecords($task, $storedAttachments, $request->user()->id, $history);
            });
        } catch (Throwable $exception) {
            $this->deleteStoredAttachmentFiles($storedAttachments);
            throw $exception;
        }

        $statusLabel = Task::STATUS_LABELS[$validated['status']] ?? ucfirst($validated['status']);

        return $this->redirectWithTaskOperation(
            $task,
            'Task status updated',
            "Task status updated to {$statusLabel}.",
        );
    }

    public function updateRisk(Request $request, Task $task, TaskWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['flag', 'resolve'])],
            'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf($request->input('action') === 'flag')],
        ]);

        $this->authorize('flagRisk', $task);
        $workflow->updateRisk($task, $validated['action'], $validated['reason'] ?? null, $request->user());

        $message = $validated['action'] === 'flag' ? 'Task flagged as at risk.' : 'Task risk flag resolved.';

        return $this->redirectWithTaskOperation(
            $task,
            $validated['action'] === 'flag' ? 'Task flagged as at risk' : 'Task risk resolved',
            $message,
        );
    }

    public function review(Request $request, Task $task, TaskWorkflowService $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'rework'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->authorize('review', $task);
        $workflow->review($task, $validated['decision'], $validated['comment'] ?? null, $request->user());

        $message = $validated['decision'] === 'approve'
            ? 'Task approved and completed.'
            : 'Task returned for re-work.';

        return $this->redirectWithTaskOperation(
            $task,
            $validated['decision'] === 'approve' ? 'Task approved' : 'Task returned for re-work',
            $message,
        );
    }

    public function reassign(Request $request, Task $task, TaskWorkflowService $workflow): RedirectResponse
    {
        $this->authorize('update', $task);

        if (in_array($task->status, ['completed', 'cancelled'], true)) {
            return back()->withErrors(['assigned_to' => 'Completed or cancelled tasks cannot be reassigned.']);
        }

        $assignedTo = $request->input('assigned_to');
        if (is_numeric($assignedTo)) {
            $request->merge(['assigned_to' => [$assignedTo]]);
        } elseif (! is_array($assignedTo)) {
            $request->merge(['assigned_to' => []]);
        }

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'assigned_to' => ['present', 'array'],
            'assigned_to.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $department = Department::query()
            ->where('id', $validated['department_id'])
            ->where('company_id', $validated['company_id'])
            ->where('status', 'active')
            ->first();

        if (! $department) {
            return back()->withErrors(['department_id' => 'Selected department does not belong to the selected company.'])->withInput();
        }

        $assigneeIds = $validated['assigned_to'];
        if ($task->status === 'notified' && $assigneeIds !== []) {
            return back()->withErrors([
                'assigned_to' => 'The department point of contact must accept the task before employee assignment.',
            ])->withInput();
        }

        if ($assigneeIds === [] && in_array($task->status, ['in_progress', 'on_hold', 'submitted_for_review', 'rework'], true)) {
            return back()->withErrors([
                'assigned_to' => 'An active task must remain assigned to an employee.',
            ])->withInput();
        }

        if (! $this->assigneesBelongToDepartment($assigneeIds, $validated['company_id'], $validated['department_id'])) {
            return back()->withErrors(['assigned_to' => 'All assigned employees must be active in the selected department.'])->withInput();
        }

        $reason = $validated['reason'] ?? 'Re-assigned task';
        $assigneeNames = User::query()->whereIn('id', $assigneeIds)->orderBy('name')->pluck('name')->implode(', ');
        DB::transaction(function () use ($task, $validated, $assigneeIds, $reason, $workflow, $request, $assigneeNames): void {
            $this->syncTaskAssignees($task, $assigneeIds, $reason, auth()->id());
            $task->update([
                'company_id' => $validated['company_id'],
                'department_id' => $validated['department_id'],
                'assigned_to' => $assigneeIds[0] ?? null,
            ]);
            $this->synchronizeAssignmentStatus($task);
            $workflow->notifyTaskParticipants(
                $task,
                $request->user(),
                'Task assignment updated',
                $assigneeNames === ''
                    ? "removed employee assignments from {$task->task_code}."
                    : "reassigned {$task->task_code} to {$assigneeNames}.",
            );
        });

        return back()->with(
            'success',
            $assigneeNames === ''
                ? "Task {$task->task_code} is now unassigned."
                : "Task {$task->task_code} has been re-assigned to {$assigneeNames} successfully.",
        );
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Task::class);

        $filters = $this->validatedFilters($request);

        $tasks = $this->applyKanbanSort(
            $this->filteredTasksQuery($filters, $request->user()),
            $filters['sort'] ?? 'deadline_priority',
        )
            ->with(['company', 'department', 'assignee', 'activeAssignees', 'pocs']);

        return response()->streamDownload(function () use ($tasks): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            fputcsv($output, [
                'Task Code',
                'Title',
                'Company',
                'Department',
                'Task Type',
                'Priority',
                'Status',
                'Deadline Condition',
                'Assigned Employee',
                'Department POCs',
                'Original Deadline',
                'Due Date',
                'Progress %',
                'Estimated Hours',
                'Expected Output',
                'Created At',
            ]);

            $tasks->chunk(500, function ($chunk) use ($output): void {
                foreach ($chunk as $task) {
                    $assignees = $task->activeAssignees->pluck('name');
                    if ($assignees->isEmpty() && $task->assignee) {
                        $assignees->push($task->assignee->name);
                    }
                    $pocs = $task->pocs->pluck('name')->implode('; ');
                    $status = $task->display_status;

                    fputcsv($output, [
                        $task->task_code,
                        $this->safeCsvValue($task->title),
                        $this->safeCsvValue($task->company?->name),
                        $this->safeCsvValue($task->department?->name),
                        ucfirst($task->task_type ?? ''),
                        ucfirst($task->priority),
                        ucwords(str_replace('_', ' ', $status)),
                        Task::DEADLINE_CONDITION_LABELS[$task->deadline_condition],
                        $this->safeCsvValue($assignees->implode('; ')),
                        $this->safeCsvValue($pocs),
                        $task->original_deadline?->format('Y-m-d'),
                        $task->due_date?->format('Y-m-d'),
                        $task->progress_percentage,
                        $task->estimated_hours,
                        $this->safeCsvValue($task->expected_output),
                        $task->created_at?->format('Y-m-d'),
                    ]);
                }
            });

            fclose($output);
        }, 'tasks-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * AJAX: Return users belonging to a department for POC / assignee dropdowns.
     */
    public function usersByDepartment(Department $department): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $users = User::query()
            ->where('department_id', $department->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'designation']);

        return response()->json([
            'pocs' => $users,
            'employees' => $users,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'condition' => ['nullable', Rule::in(Task::DEADLINE_CONDITIONS)],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'task_type' => ['nullable', Rule::in(Task::TYPES)],
            'sort' => ['nullable', Rule::in([
                'deadline_priority',
                'priority_deadline',
                'nearest_deadline',
                'oldest_deadline',
                'recently_created',
                'recently_updated',
            ])],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', Rule::in([10, 15, 25, 50, 100])],
        ]);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function applyKanbanSort(Builder $query, string $sort): Builder
    {
        $deadline = 'COALESCE(due_date, original_deadline)';
        $priority = "CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END";
        $nullDeadline = "CASE WHEN {$deadline} IS NULL THEN 1 ELSE 0 END";

        if ($sort === 'recently_created') {
            return $query->orderByDesc('created_at')->orderByDesc('id');
        }

        if ($sort === 'recently_updated') {
            return $query->orderByDesc('updated_at')->orderByDesc('id');
        }

        if ($sort === 'nearest_deadline') {
            $today = today()->toDateString();

            return $query
                ->orderByRaw("CASE WHEN {$deadline} IS NULL THEN 2 WHEN {$deadline} < ? THEN 1 ELSE 0 END", [$today])
                ->orderByRaw("{$deadline} ASC")
                ->orderBy('created_at')
                ->orderBy('id');
        }

        if ($sort === 'priority_deadline') {
            $query->orderByRaw($priority);
        }

        $query->orderByRaw($nullDeadline)
            ->orderByRaw("{$deadline} ASC");

        if ($sort === 'deadline_priority') {
            $query->orderByRaw($priority);
        }

        return $query->orderBy('created_at')->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Task>
     */
    private function filteredTasksQuery(array $filters, User $user): Builder
    {
        $query = Task::query()->visibleTo($user);

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('task_code', 'like', "%{$search}%")
                    ->orWhereHas('company', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('department', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('assignee', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('activeAssignees', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('pocs', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->assignedTo((int) $filters['assigned_to']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['due_date'])) {
            $query->whereRaw('DATE(COALESCE(due_date, original_deadline)) = ?', [$filters['due_date']]);
        }

        if (! empty($filters['task_type'])) {
            if ($filters['task_type'] === 'recurring') {
                $query->where(function (Builder $query): void {
                    $query->whereHas('recurringTemplate')
                        ->orWhere(function (Builder $legacyQuery): void {
                            $legacyQuery->where('task_type', 'recurring')
                                ->whereNull('recurring_task_template_id');
                        });
                });
            } else {
                $query->where('task_type', $filters['task_type']);
            }
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['condition'])) {
            match ($filters['condition']) {
                'overdue' => $query->overdue(),
                'at_risk' => $query->atRisk(),
                'normal' => $query->deadlineNormal(),
                default => null,
            };
        }

        return $query;
    }

    /**
     * @param  array<int, int|string>  $assigneeIds
     */
    private function assigneesBelongToDepartment(array $assigneeIds, int $companyId, int $departmentId): bool
    {
        if ($assigneeIds === []) {
            return true;
        }

        return User::query()
            ->whereIn('id', $assigneeIds)
            ->where('company_id', $companyId)
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->count() === count($assigneeIds);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function preventConflictingSubtaskTimeframeChange(
        Task $task,
        array $validated,
        User $actor,
        SubtaskActivityService $subtaskActivity,
    ): void {
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

        DB::transaction(fn () => $subtaskActivity->recordParentDeadlineConflicts(
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

    private function redirectWithTaskOperation(Task $task, string $title, string $message): RedirectResponse
    {
        return redirect()->route('tasks.show', $task)
            ->with('success', $message)
            ->with('operation_status', [
                'kind' => 'task',
                'title' => $title,
                'message' => $message,
            ]);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{path: string, original_name: string, mime_type: ?string, size_bytes: int}>
     */
    private function storeAttachmentFiles(array $files): array
    {
        $storedAttachments = [];

        try {
            foreach ($files as $file) {
                $path = $file->store('task-attachments', 'local');
                if (! is_string($path)) {
                    throw new RuntimeException('Unable to store the task attachment.');
                }

                $storedAttachments[] = [
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                ];
            }
        } catch (Throwable $exception) {
            $this->deleteStoredAttachmentFiles($storedAttachments);
            throw $exception;
        }

        return $storedAttachments;
    }

    /**
     * @param  list<array{path: string, original_name: string, mime_type: ?string, size_bytes: int}>  $files
     */
    private function createAttachmentRecords(Task $task, array $files, int $uploadedBy, ?TaskStatusHistory $history = null): void
    {
        foreach ($files as $file) {
            $task->attachments()->create([
                'status_history_id' => $history?->id,
                'uploaded_by' => $uploadedBy,
                'disk' => 'local',
                ...$file,
            ]);
        }
    }

    /**
     * @param  list<array{path: string, original_name: string, mime_type: ?string, size_bytes: int}>  $files
     */
    private function deleteStoredAttachmentFiles(array $files): void
    {
        $paths = array_column($files, 'path');
        if ($paths !== [] && ! Storage::disk('local')->delete($paths)) {
            throw new RuntimeException('Unable to clean up stored task attachments.');
        }
    }

    /**
     * @param  array<int, int|string>  $assigneeIds
     */
    private function syncTaskAssignees(Task $task, array $assigneeIds, string $reason, ?int $assignedBy): void
    {
        $assigneeIds = array_values(array_unique(array_map('intval', $assigneeIds)));
        $now = now();

        if ($task->assigned_to && ! TaskAssignment::query()
            ->where('task_id', $task->id)
            ->where('user_id', $task->assigned_to)
            ->exists()) {
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $task->assigned_to,
                'assigned_by' => $task->created_by,
                'reason' => 'Initial assignment',
                'assigned_at' => $task->created_at ?? $now,
                'is_active' => true,
            ]);
        }

        $activeAssignments = $task->assignments()
            ->where('is_active', true)
            ->get();
        $activeAssigneeIds = [];

        foreach ($activeAssignments as $assignment) {
            if (in_array((int) $assignment->user_id, $assigneeIds, true) && $assignment->unassigned_at === null) {
                $activeAssigneeIds[] = (int) $assignment->user_id;

                continue;
            }

            $assignment->update([
                'unassigned_at' => $now,
                'is_active' => false,
            ]);
        }

        foreach (array_diff($assigneeIds, array_unique($activeAssigneeIds)) as $assigneeId) {
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $assigneeId,
                'assigned_by' => $assignedBy,
                'reason' => $reason,
                'assigned_at' => $now,
                'is_active' => true,
            ]);
        }
    }

    private function synchronizeAssignmentStatus(Task $task): void
    {
        $task->refresh();
        $hasActiveAssignee = $task->activeAssignees()->exists();
        $newStatus = match (true) {
            $hasActiveAssignee && $task->status === 'unassigned' => 'assigned',
            ! $hasActiveAssignee && $task->status === 'assigned' => 'unassigned',
            default => null,
        };

        if ($newStatus === null) {
            return;
        }

        $oldStatus = $task->status;
        $task->update(['status' => $newStatus]);
        $task->statusHistory()->create([
            'changed_by' => auth()->id(),
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'event_type' => 'status',
            'remarks' => 'Status synchronized with the active employee assignment.',
        ]);
    }

    private function safeCsvValue(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\t\r ]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDailyTaskRequest;
use App\Models\DailyTask;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskLifecycleNotification;
use App\Services\DailyTaskQueryService;
use App\Services\DailyTaskWorkflowService;
use App\Services\TaskAttachmentService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class DailyTaskController extends Controller
{
    public function __construct(
        private readonly DailyTaskWorkflowService $workflow,
        private readonly DailyTaskQueryService $query,
        private readonly TaskAttachmentService $attachments,
    ) {}

    public function index(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize('viewAny', Task::class);

        if (! $request->wantsJson()) {
            return redirect()->route('tasks.index');
        }

        $this->workflow->refreshDueStatuses();

        $filters = $this->validatedFilters($request);
        $listing = $this->query->paginateForUser($request->user(), $filters);

        return response()->json([
            'ok' => true,
            'summary' => $listing['summary'],
            'daily_tasks' => $listing['paginator']->through(fn (DailyTask $dailyTask): array => $this->formatDailyTask($dailyTask)),
        ]);
    }

    public function create(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('createDailyTask', $task);

        $openCreateDialog = $request->user()->hasAssignedRole('Employee')
            ? '?open_daily_task=1'
            : '';

        return redirect()->to(route('tasks.show', $task).$openCreateDialog.'#panel-daily-tasks');
    }

    public function forTask(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);
        $this->workflow->refreshDueStatuses();

        $filters = $this->validatedFilters($request);
        $listing = $this->query->paginateForUser($request->user(), $filters, $task->id);

        return response()->json([
            'ok' => true,
            'summary' => $listing['summary'],
            'daily_tasks' => $listing['paginator']->through(fn (DailyTask $dailyTask): array => $this->formatDailyTask($dailyTask)),
        ]);
    }

    public function store(StoreDailyTaskRequest $request, Task $task): JsonResponse|RedirectResponse
    {
        $data = $request->safe()->except(['attachments']);
        $this->assertEligibleAssignee($task, (int) $data['assigned_to']);
        $overlaps = $this->query->overlaps(
            (int) $data['assigned_to'],
            $data['work_date'],
            $data['planned_start_time'],
            $data['planned_end_time'],
        );

        $storedAttachments = [];
        try {
            if ($request->file('attachments')) {
                $storedAttachments = $this->attachments->storeAttachmentFiles($request->file('attachments'));
            }

            $dailyTask = DB::transaction(function () use ($data, $storedAttachments, $request, $task): DailyTask {
                $dailyTask = $this->workflow->create($task, $request->user(), $data);

                if ($storedAttachments !== []) {
                    $history = $dailyTask->activity()->where('event_type', 'daily_task_created')->first();
                    $this->attachments->createAttachmentRecords($task, $storedAttachments, $request->user()->id, $history);
                }

                return $dailyTask;
            });
        } catch (Throwable $exception) {
            if ($storedAttachments !== []) {
                $this->attachments->deleteStoredAttachmentFiles($storedAttachments);
            }

            throw $exception;
        }

        $this->notifyStakeholders(
            $task,
            $request->user(),
            'Daily Task submitted',
            "{$request->user()->name} created Daily Task {$dailyTask->title} for {$dailyTask->work_date?->format('M j, Y')}.",
            $dailyTask->assignee,
        );

        if (! $request->wantsJson()) {
            return redirect()
                ->to(route('tasks.show', $task).'#panel-daily-tasks')
                ->with('success', 'Daily Task created successfully.')
                ->with('overlap_warning', $overlaps->isNotEmpty());
        }

        return response()->json([
            'ok' => true,
            'overlap_warning' => $overlaps->isNotEmpty(),
            'overlaps' => $overlaps->map(fn (DailyTask $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'planned_start_time' => $item->formatTime($item->planned_start_time),
                'planned_end_time' => $item->formatTime($item->planned_end_time),
            ])->values(),
            'daily_task' => $this->formatDailyTask($dailyTask->load(['task', 'subtask', 'milestone', 'assignee', 'creator', 'activity.changedBy'])),
        ], 201);
    }

    public function show(Request $request, Task $task, DailyTask $dailyTask): JsonResponse|RedirectResponse
    {
        $this->authorize('viewDailyTask', [$task, $dailyTask]);

        if (! $request->wantsJson()) {
            return redirect()->to(route('tasks.show', $task).'#daily-task-'.$dailyTask->id);
        }

        $dailyTask->load(['task.department', 'subtask', 'milestone', 'assignee', 'creator', 'activity.changedBy', 'activity.attachments']);
        $payload = $this->formatDailyTask($dailyTask, true);

        return response()->json([
            'ok' => true,
            'daily_task' => $payload,
            'overlap_warning' => $dailyTask->overlappingSchedules()->isNotEmpty(),
        ]);
    }

    public function action(Request $request, Task $task, DailyTask $dailyTask, string $action): JsonResponse|RedirectResponse
    {
        $this->authorize('viewDailyTask', [$task, $dailyTask]);
        $this->authorize('updateDailyTask', [$task, $dailyTask]);
        abort_unless(in_array($action, ['start', 'hold', 'resume', 'progress', 'update', 'reassign', 'reschedule', 'complete', 'cancel'], true), 404);

        $validated = $request->validate([
            'remarks' => [$action === 'update' ? 'required' : 'nullable', 'string', 'max:5000'],
            'blockers' => ['nullable', 'string', 'max:5000'],
            'progress_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'work_date' => ['nullable', 'date_format:Y-m-d'],
            'planned_start_time' => ['nullable', 'date_format:H:i'],
            'planned_end_time' => ['nullable', 'date_format:H:i'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'operational_metrics' => ['nullable', 'array', 'max:30'],
            'operational_metrics.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        if (isset($validated['operational_metrics'])) {
            $unknownMetrics = array_diff(
                array_keys($validated['operational_metrics']),
                array_keys($this->metricDefinitionsForTask($task)),
            );
            if ($unknownMetrics !== []) {
                throw ValidationException::withMessages([
                    'operational_metrics' => 'One or more operational metrics are not configured for this department.',
                ]);
            }
        }

        $actor = $request->user();
        $dailyTask = match ($action) {
            'start' => $this->workflow->start($dailyTask, $actor, $validated['remarks'] ?? null),
            'hold' => $this->workflow->hold($dailyTask, $actor, $validated['remarks'] ?? null),
            'resume' => $this->workflow->resume($dailyTask, $actor, $validated['remarks'] ?? null),
            'progress' => $this->workflow->updateProgress(
                $dailyTask,
                $actor,
                (int) ($validated['progress_percentage'] ?? $dailyTask->progress_percentage),
                $validated['remarks'] ?? null,
            ),
            'update' => $this->workflow->submitUpdate(
                $dailyTask,
                $actor,
                $validated['remarks'] ?? null,
                $validated['blockers'] ?? null,
                $validated['operational_metrics'] ?? null,
            ),
            'reassign' => $this->reassign($task, $dailyTask, $actor, $validated),
            'reschedule' => $this->reschedule($dailyTask, $actor, $validated),
            'complete' => $this->workflow->complete($dailyTask, $actor, $validated['remarks'] ?? null),
            'cancel' => $this->workflow->cancel($dailyTask, $actor, $validated['remarks'] ?? null),
        };

        $overlaps = $dailyTask->overlappingSchedules();
        if (in_array($action, ['hold', 'complete', 'reassign'], true)) {
            $notificationText = match ($action) {
                'hold' => 'put Daily Task',
                'complete' => 'completed Daily Task',
                default => 'reassigned Daily Task',
            };
            $this->notifyStakeholders(
                $task,
                $actor,
                match ($action) {
                    'hold' => 'Daily Task on hold',
                    'complete' => 'Daily Task completed',
                    default => 'Daily Task reassigned',
                },
                "{$actor->name} {$notificationText} {$dailyTask->title} on {$task->task_code}.",
                $dailyTask->assignee,
            );
        }

        if (! $request->wantsJson()) {
            return redirect()
                ->to(route('tasks.show', $task).'#panel-daily-tasks')
                ->with('success', 'Daily Task updated successfully.')
                ->with('overlap_warning', $overlaps->isNotEmpty());
        }

        return response()->json([
            'ok' => true,
            'overlap_warning' => $overlaps->isNotEmpty(),
            'daily_task' => $this->formatDailyTask($dailyTask->load(['task', 'subtask', 'milestone', 'assignee', 'creator', 'activity.changedBy'])),
        ]);
    }

    public function subtasks(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $subtasks = $task->subtasks()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'task_id', 'title', 'status', 'assigned_to']);

        $employees = $this->employeesForTask($request->user(), $task);
        $milestones = $task->milestones()->orderBy('target_date')->get(['id', 'task_id', 'title', 'target_date', 'status']);

        return response()->json([
            'ok' => true,
            'subtasks' => $subtasks,
            'milestones' => $milestones,
            'employees' => $employees,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function reassign(Task $task, DailyTask $dailyTask, User $actor, array $validated): DailyTask
    {
        $assigneeId = (int) ($validated['assigned_to'] ?? 0);
        if ($assigneeId === 0) {
            throw ValidationException::withMessages(['assigned_to' => 'Select an employee to reassign this Daily Task.']);
        }
        $this->assertEligibleAssignee($task, $assigneeId);

        return $this->workflow->reassign($dailyTask, $actor, $assigneeId, $validated['remarks'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function reschedule(DailyTask $dailyTask, User $actor, array $validated): DailyTask
    {
        $workDate = $validated['work_date'] ?? $dailyTask->work_date?->toDateString();
        $start = $validated['planned_start_time'] ?? substr((string) $dailyTask->planned_start_time, 0, 5);
        $end = $validated['planned_end_time'] ?? substr((string) $dailyTask->planned_end_time, 0, 5);

        if ($end <= $start) {
            throw ValidationException::withMessages(['planned_end_time' => 'Planned end time must be after the start time.']);
        }

        return $this->workflow->reschedule($dailyTask, $actor, [
            'work_date' => $workDate,
            'planned_start_time' => $start,
            'planned_end_time' => $end,
            'estimated_minutes' => $validated['estimated_minutes'] ?? $dailyTask->estimated_minutes,
        ], $validated['remarks'] ?? null);
    }

    private function assertEligibleAssignee(Task $task, int $userId): void
    {
        $isEligible = User::query()
            ->whereKey($userId)
            ->where('status', 'active')
            ->where('company_id', $task->company_id)
            ->where('department_id', $task->department_id)
            ->exists();

        if (! $isEligible) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The assignee must be an active employee in the main task department.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'range' => ['nullable', Rule::in(['today', 'yesterday', 'upcoming', 'all'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'subtask_id' => ['nullable', 'integer', 'exists:subtasks,id'],
            'milestone_id' => ['nullable', 'integer', 'exists:milestones,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'priority' => ['nullable', Rule::in(DailyTask::PRIORITIES)],
            'status' => ['nullable', Rule::in(DailyTask::STATUSES)],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDailyTask(DailyTask $dailyTask, bool $withActivity = false): array
    {
        $payload = [
            'id' => $dailyTask->id,
            'task_id' => $dailyTask->task_id,
            'subtask_id' => $dailyTask->subtask_id,
            'milestone_id' => $dailyTask->milestone_id,
            'title' => $dailyTask->title,
            'description' => $dailyTask->description,
            'main_task' => $dailyTask->task ? [
                'id' => $dailyTask->task->id,
                'task_code' => $dailyTask->task->task_code,
                'title' => $dailyTask->task->title,
            ] : null,
            'subtask' => $dailyTask->subtask ? [
                'id' => $dailyTask->subtask->id,
                'title' => $dailyTask->subtask->title,
            ] : null,
            'milestone' => $dailyTask->milestone ? [
                'id' => $dailyTask->milestone->id,
                'title' => $dailyTask->milestone->title,
            ] : null,
            'assigned_to' => $dailyTask->assignee ? [
                'id' => $dailyTask->assignee->id,
                'name' => $dailyTask->assignee->name,
            ] : null,
            'work_date' => $dailyTask->work_date?->toDateString(),
            'planned_start_time' => substr((string) $dailyTask->planned_start_time, 0, 5),
            'planned_end_time' => substr((string) $dailyTask->planned_end_time, 0, 5),
            'priority' => $dailyTask->priority,
            'priority_label' => DailyTask::PRIORITY_LABELS[$dailyTask->priority] ?? ucfirst((string) $dailyTask->priority),
            'status' => $dailyTask->status,
            'status_label' => DailyTask::STATUS_LABELS[$dailyTask->status] ?? ucfirst((string) $dailyTask->status),
            'progress_percentage' => (int) $dailyTask->progress_percentage,
            'estimated_minutes' => $dailyTask->estimated_minutes,
            'operational_metrics' => $dailyTask->operational_metrics ?? [],
            'actual_started_at' => $dailyTask->actual_started_at?->timezone(config('app.timezone'))->toDateTimeString(),
            'actual_ended_at' => $dailyTask->actual_ended_at?->timezone(config('app.timezone'))->toDateTimeString(),
            'missed_at' => $dailyTask->missed_at?->timezone(config('app.timezone'))->toDateTimeString(),
            'remarks' => $dailyTask->remarks,
            'blockers' => $dailyTask->blockers,
            'urls' => [
                'show' => route('daily-tasks.show', [$dailyTask->task_id, $dailyTask->id]),
                'start' => route('daily-tasks.action', [$dailyTask->task_id, $dailyTask->id, 'start']),
                'hold' => route('daily-tasks.action', [$dailyTask->task_id, $dailyTask->id, 'hold']),
                'resume' => route('daily-tasks.action', [$dailyTask->task_id, $dailyTask->id, 'resume']),
                'progress' => route('daily-tasks.action', [$dailyTask->task_id, $dailyTask->id, 'progress']),
                'update' => route('daily-tasks.action', [$dailyTask->task_id, $dailyTask->id, 'update']),
                'reassign' => route('daily-tasks.action', [$dailyTask->task_id, $dailyTask->id, 'reassign']),
                'reschedule' => route('daily-tasks.action', [$dailyTask->task_id, $dailyTask->id, 'reschedule']),
                'complete' => route('daily-tasks.action', [$dailyTask->task_id, $dailyTask->id, 'complete']),
                'cancel' => route('daily-tasks.action', [$dailyTask->task_id, $dailyTask->id, 'cancel']),
            ],
        ];

        if ($withActivity) {
            $payload['activity'] = $dailyTask->activity
                ->sortBy('id')
                ->values()
                ->map(fn ($history): array => [
                    'id' => $history->id,
                    'event_type' => $history->event_type,
                    'old_status' => $history->old_status,
                    'new_status' => $history->new_status,
                    'remarks' => $history->remarks,
                    'actor' => $history->changedBy?->name ?? 'System',
                    'at' => $history->created_at?->timezone(config('app.timezone'))->toDateTimeString(),
                ]);
        }

        return $payload;
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function employeesForTask(User $user, Task $task): EloquentCollection
    {
        $query = User::query()
            ->where('status', 'active')
            ->where('company_id', $task->company_id)
            ->where('department_id', $task->department_id);

        if (! $user->can('daily_tasks.create_for_others')) {
            $query->whereKey($user->id);
        }

        return $query->orderBy('name')->get(['id', 'name', 'company_id', 'department_id']);
    }

    private function notifyStakeholders(
        Task $task,
        User $actor,
        string $title,
        string $message,
        ?User $assignee = null,
    ): void {
        $task->loadMissing(['pocs:id', 'activeAssignees:id']);
        $recipientIds = collect([
            $task->created_by,
            ...$task->pocs->modelKeys(),
            ...$task->activeAssignees->modelKeys(),
            $assignee?->id,
        ])
            ->filter()
            ->map(fn (int|string $id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === (int) $actor->id)
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        User::query()
            ->where('status', 'active')
            ->whereIn('id', $recipientIds)
            ->each(fn (User $recipient) => $recipient->notify(
                new TaskLifecycleNotification($task, $actor->name, $title, $message),
            ));
    }

    /**
     * @return array<string, string>
     */
    private function metricDefinitionsForTask(Task $task): array
    {
        $task->loadMissing('department');

        return config('operational_metrics.'.Str::slug((string) $task->department?->name, '_'), []);
    }
}

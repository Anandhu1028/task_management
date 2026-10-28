<?php

namespace App\Support;

use App\Models\DeadlineExtensionRequest;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\SubtaskProgressService;
use App\Services\TaskWorkflowService;
use Illuminate\Support\Collection;

class DesktopApiPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function user(User $user): array
    {
        $user->loadMissing(['company:id,name', 'department:id,name', 'primaryRole:id,name']);
        $roleName = $user->getRoleNames()->first() ?? $user->primaryRole?->name;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_id' => $user->employee_id,
            'designation' => $user->designation,
            'status' => $user->status,
            'role' => $roleName,
            'company' => $user->company ? [
                'id' => $user->company->id,
                'name' => $user->company->name,
            ] : null,
            'department' => $user->department ? [
                'id' => $user->department->id,
                'name' => $user->department->name,
            ] : null,
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'can' => [
                'view_tasks' => $user->can('tasks.view'),
                'create_task' => $user->can('tasks.create'),
                'update_task' => $user->can('tasks.update'),
                'delete_task' => $user->can('tasks.delete'),
                'change_status' => $user->can('tasks.change_status'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function taskSummary(Task $task): array
    {
        $assignees = $task->activeAssignees;
        if ($assignees->isEmpty() && $task->assignee) {
            $assignees = collect([$task->assignee]);
        }
        $deadline = $task->due_date ?? $task->original_deadline;

        return [
            'id' => $task->id,
            'task_code' => $task->task_code,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'status_label' => Task::STATUS_LABELS[$task->status] ?? $task->status,
            'priority' => $task->priority,
            'task_type' => $task->task_type,
            'progress_percentage' => (int) $task->progress_percentage,
            'deadline_condition' => $task->deadline_condition,
            'deadline_condition_label' => Task::DEADLINE_CONDITION_LABELS[$task->deadline_condition] ?? $task->deadline_condition,
            'is_overdue' => $task->isOverdue(),
            'is_due_today' => $deadline?->toDateString() === today()->toDateString() && ! $task->isOverdue(),
            'start_date' => $task->start_date?->format('Y-m-d'),
            'due_date' => $task->due_date?->format('Y-m-d'),
            'original_deadline' => $task->original_deadline?->format('Y-m-d'),
            'estimated_hours' => $task->estimated_hours,
            'created_at' => $task->created_at?->toIso8601String(),
            'company' => $task->company ? [
                'id' => $task->company->id,
                'name' => $task->company->name,
            ] : null,
            'department' => $task->department ? [
                'id' => $task->department->id,
                'name' => $task->department->name,
            ] : null,
            'created_by' => $task->creator ? [
                'id' => $task->creator->id,
                'name' => $task->creator->name,
            ] : null,
            'assignees' => $assignees->map(fn (User $assignee): array => [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'designation' => $assignee->designation,
            ])->values()->all(),
            'pocs' => $task->pocs->map(fn (User $poc): array => [
                'id' => $poc->id,
                'name' => $poc->name,
                'designation' => $poc->designation,
            ])->values()->all(),
            'subtask_counts' => [
                'total' => $task->subtasks_count ?? $task->subtasks->count(),
                'completed' => $task->completed_subtasks_count ?? $task->subtasks->where('is_completed', true)->count(),
            ],
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function taskDetail(
        Task $task,
        User $actor,
        TaskWorkflowService $workflow,
        SubtaskProgressService $progress,
    ): array {
        $task->loadMissing([
            'company:id,name',
            'department:id,name',
            'assignee:id,name,designation',
            'activeAssignees:id,name,designation',
            'creator:id,name,designation',
            'pocs:id,name,designation',
            'reviewer:id,name,designation',
            'subtasks.creator:id,name,designation',
            'subtasks.assignee:id,name,designation',
            'subtasks.completedBy:id,name,designation',
            'deadlineExtensionRequests.requester:id,name',
            'deadlineExtensionRequests.reviewer:id,name',
            'statusHistory.changedBy:id,name',
        ]);

        return [
            ...self::taskSummary($task),
            'expected_output' => $task->expected_output,
            'hold_reason' => $task->hold_reason,
            'risk_reason' => $task->risk_reason,
            'cancellation_reason' => $task->cancellation_reason,
            'submission_comment' => $task->submission_comment,
            'review_comment' => $task->review_comment,
            'reviewer' => $task->reviewer ? [
                'id' => $task->reviewer->id,
                'name' => $task->reviewer->name,
            ] : null,
            'progress' => $progress->summary($task),
            'status_options' => $workflow->availableTransitions($task, $actor),
            'permissions' => [
                'update' => $actor->can('update', $task),
                'delete' => $actor->can('delete', $task),
                'change_status' => $actor->can('changeStatus', $task),
                'accept' => $actor->can('accept', $task),
                'start_work' => $actor->can('startWork', $task),
                'add_work_update' => $actor->can('addWorkUpdate', $task),
                'resume' => $actor->can('resume', $task),
                'submit_for_review' => $actor->can('submitForReview', $task),
                'flag_risk' => $actor->can('flagRisk', $task),
                'review' => $actor->can('review', $task),
                'cancel' => $actor->can('cancel', $task),
                'create_subtask' => $actor->can('createSubtask', $task),
                'update_subtask' => $actor->can('updateSubtask', $task),
                'request_deadline_extension' => $actor->can('requestDeadlineExtension', $task),
                'review_deadline_extensions' => $actor->can('reviewDeadlineExtensions', $task),
            ],
            'subtasks' => $task->subtasks->map(fn (Subtask $subtask): array => self::subtask($subtask))->values()->all(),
            'deadline_extension_requests' => $task->deadlineExtensionRequests
                ->map(fn (DeadlineExtensionRequest $request): array => self::deadlineExtensionRequest($request))
                ->values()
                ->all(),
            'history' => $task->statusHistory
                ->sortByDesc('id')
                ->take(20)
                ->map(fn ($event): array => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'old_status' => $event->old_status,
                    'new_status' => $event->new_status,
                    'remarks' => $event->remarks,
                    'changed_by' => $event->changedBy?->name,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function subtask(Subtask $subtask): array
    {
        $status = $subtask->is_completed ? 'completed' : ($subtask->status ?? 'todo');

        return [
            'id' => $subtask->id,
            'title' => $subtask->title,
            'description' => $subtask->description ?? '',
            'priority' => $subtask->priority ?? 'medium',
            'status' => $status,
            'status_label' => Subtask::STATUS_LABELS[$status] ?? 'Pending',
            'is_completed' => (bool) $subtask->is_completed,
            'progress_percentage' => $subtask->is_completed ? 100 : (int) $subtask->progress_percentage,
            'start_date' => $subtask->start_date?->format('Y-m-d'),
            'due_date' => $subtask->due_date?->format('Y-m-d'),
            'estimated_hours' => $subtask->estimated_hours,
            'is_overdue' => $subtask->is_overdue,
            'sort_order' => $subtask->sort_order,
            'created_by' => [
                'id' => $subtask->creator?->id,
                'name' => $subtask->creator?->name ?? 'Unknown user',
            ],
            'assigned_to' => [
                'id' => $subtask->assignee?->id,
                'name' => $subtask->assignee?->name ?? 'Unassigned',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deadlineExtensionRequest(DeadlineExtensionRequest $request): array
    {
        return [
            'id' => $request->id,
            'status' => $request->status,
            'current_deadline' => $request->current_deadline?->format('Y-m-d'),
            'requested_deadline' => $request->requested_deadline?->format('Y-m-d'),
            'reason' => $request->reason,
            'review_note' => $request->review_note,
            'requested_by' => $request->requester?->name,
            'reviewed_by' => $request->reviewer?->name,
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array{id: int, name: string, designation: ?string, company_id: ?int, department_id: ?int}>
     */
    public static function people(Collection $users): array
    {
        return $users->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'designation' => $user->designation,
            'company_id' => $user->company_id,
            'department_id' => $user->department_id,
        ])->values()->all();
    }
}

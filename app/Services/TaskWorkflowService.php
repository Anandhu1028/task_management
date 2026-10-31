<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Notifications\TaskLifecycleNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TaskWorkflowService
{
    /**
     * @return array<string, string>
     */
    public function availableTransitions(Task $task, User $user): array
    {
        if (in_array($task->status, ['completed', 'cancelled'], true)) {
            return [];
        }

        $transitions = [];
        $candidateStatuses = Task::STATUS_TRANSITIONS[$task->status] ?? [];

        if (Gate::forUser($user)->allows('changeStatus', $task)) {
            foreach ($candidateStatuses as $status) {
                if ($task->status === 'notified' && $status === 'unassigned') {
                    continue;
                }

                if (in_array($task->status, ['assigned', 'on_hold'], true) && $status === 'in_progress') {
                    continue;
                }

                if ($this->statusRequirementsAreMet($task, $status)) {
                    $transitions[$status] = Task::STATUS_LABELS[$status];
                }
            }
        }

        if (in_array('submitted_for_review', $candidateStatuses, true)
            && Gate::forUser($user)->allows('submitForReview', $task)
            && $this->statusRequirementsAreMet($task, 'submitted_for_review')) {
            $transitions['submitted_for_review'] = Task::STATUS_LABELS['submitted_for_review'];
        }

        if (Gate::forUser($user)->allows('cancel', $task)) {
            $transitions['cancelled'] = Task::STATUS_LABELS['cancelled'];
        }

        return $transitions;
    }

    public function acceptNotifiedTask(Task $task, User $actor): void
    {
        Gate::forUser($actor)->authorize('accept', $task);

        DB::transaction(function () use ($task, $actor): void {
            $task->update(['status' => 'unassigned']);
            $task->statusHistory()->create([
                'changed_by' => $actor->id,
                'old_status' => 'notified',
                'new_status' => 'unassigned',
                'event_type' => 'accepted',
                'remarks' => 'Department point of contact accepted the task for assignment.',
            ]);

            $this->notifyTaskParticipants(
                $task,
                $actor,
                'Task accepted',
                "accepted {$task->task_code} for assignment.",
            );
        });
    }

    public function notifyTaskCreated(Task $task, User $actor): void
    {
        $pocNames = $task->pocs()->orderBy('users.name')->pluck('users.name')->implode(', ');
        $assigneeNames = $task->activeAssignees()->orderBy('users.name')->pluck('users.name')->implode(', ');
        $routingDetails = $pocNames !== ''
            ? ($assigneeNames === '' ? "sent to {$pocNames} for acceptance" : "routed through {$pocNames}")
            : 'created without a department point of contact';

        if ($assigneeNames !== '') {
            $routingDetails .= "; assigned to {$assigneeNames}";
        }

        $recipientIds = array_merge(
            $this->administratorAndCeoIds(),
            [$task->created_by],
            $task->pocs()->pluck('users.id')->all(),
            $task->activeAssignees()->pluck('users.id')->all(),
        );

        $this->notifyUsers(
            $task,
            $recipientIds,
            $actor,
            'New task created',
            "{$actor->name} created {$task->task_code}, {$routingDetails}.",
            true,
        );
    }

    public function startWork(Task $task, string $note, string $startDate, User $actor): TaskStatusHistory
    {
        $this->ensureRecurringOccurrenceIsAvailable($task);
        Gate::forUser($actor)->authorize('startWork', $task);

        if (blank($note)) {
            throw ValidationException::withMessages([
                'note' => 'A start note is required.',
            ]);
        }
        if (blank($startDate)) {
            throw ValidationException::withMessages([
                'start_date' => 'A start date is required.',
            ]);
        }

        return DB::transaction(function () use ($task, $note, $startDate, $actor): TaskStatusHistory {
            $oldStatus = $task->status;
            $now = now();
            $task->update([
                'status' => 'in_progress',
                'start_date' => $startDate,
            ]);
            $history = $task->statusHistory()->make([
                'changed_by' => $actor->id,
                'old_status' => $oldStatus,
                'new_status' => 'in_progress',
                'event_type' => 'start',
                'remarks' => trim($note),
            ]);
            $history->created_at = Carbon::parse($startDate)->setTime($now->hour, $now->minute, $now->second);
            $history->save();

            $this->notifyTaskParticipants(
                $task,
                $actor,
                'Work started',
                "started {$task->task_code}.",
            );

            return $history;
        });
    }

    public function addWorkUpdate(Task $task, string $note, string $updateDate, User $actor): TaskStatusHistory
    {
        $this->ensureRecurringOccurrenceIsAvailable($task);
        Gate::forUser($actor)->authorize('addWorkUpdate', $task);

        return DB::transaction(function () use ($task, $note, $updateDate, $actor): TaskStatusHistory {
            $workUpdate = $task->statusHistory()->make([
                'changed_by' => $actor->id,
                'old_status' => $task->status,
                'new_status' => $task->status,
                'event_type' => 'work_update',
                'activity_date' => Carbon::parse($updateDate, config('app.timezone'))->toDateString(),
                'remarks' => trim($note),
            ]);
            $workUpdate->save();

            $this->notifyTaskParticipants(
                $task,
                $actor,
                'Work update added',
                "added a work update to {$task->task_code}.",
            );

            return $workUpdate;
        });
    }

    public function resumeWork(Task $task, ?string $note, User $actor): void
    {
        $this->ensureRecurringOccurrenceIsAvailable($task);
        Gate::forUser($actor)->authorize('resume', $task);

        $holdEvent = $task->statusHistory()
            ->where('event_type', 'hold')
            ->where('new_status', 'on_hold')
            ->latest('id')
            ->first();
        $previousStatus = in_array($holdEvent?->old_status, ['in_progress', 'submitted_for_review', 'rework'], true)
            ? $holdEvent->old_status
            : 'assigned';
        if (! $task->activeAssignees()->exists()) {
            $previousStatus = 'unassigned';
        }

        DB::transaction(function () use ($task, $previousStatus, $holdEvent, $note, $actor): void {
            $task->update([
                'status' => $previousStatus,
                'hold_reason' => null,
            ]);
            $remarks = array_filter([
                filled($note) ? trim($note) : null,
                filled($holdEvent?->remarks) ? 'Previous hold reason: '.trim($holdEvent->remarks) : null,
            ]);
            $task->statusHistory()->create([
                'changed_by' => $actor->id,
                'old_status' => 'on_hold',
                'new_status' => $previousStatus,
                'event_type' => 'resume',
                'remarks' => implode("\n", $remarks) ?: 'Work resumed.',
            ]);

            $this->notifyTaskParticipants(
                $task,
                $actor,
                'Work resumed',
                "resumed {$task->task_code}.",
            );
        });
    }

    /**
     * @param  list<int|string|null>  $userIds
     */
    private function notifyUsers(
        Task $task,
        array $userIds,
        User $actor,
        string $title,
        string $message,
        bool $notifyActor = false,
    ): void {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $userId): bool => $userId > 0 && ($notifyActor || $userId !== $actor->id),
        )));

        User::query()->whereIn('id', $userIds)->get()->each(
            fn (User $user) => $user->notify(new TaskLifecycleNotification($task, $actor->name, $title, $message)),
        );
    }

    public function changeStatus(Task $task, string $newStatus, ?string $reason, User $actor, ?string $statusDate = null): TaskStatusHistory
    {
        $this->ensureRecurringOccurrenceIsAvailable($task);
        $allowedTransitions = Task::STATUS_TRANSITIONS[$task->status] ?? [];
        $isCancellation = $newStatus === 'cancelled';
        $ability = match ($newStatus) {
            'cancelled' => 'cancel',
            'submitted_for_review' => 'submitForReview',
            default => 'changeStatus',
        };
        Gate::forUser($actor)->authorize($ability, $task);

        if ($task->status === 'assigned' && $newStatus === 'in_progress') {
            throw ValidationException::withMessages([
                'status' => 'Use the Start Work action to record the required start details.',
            ]);
        }

        if ((! $isCancellation && ! in_array($newStatus, $allowedTransitions, true))
            || $task->status === $newStatus
            || in_array($task->status, ['completed', 'cancelled'], true)
            || ($task->status === 'notified' && $newStatus === 'unassigned')) {
            throw ValidationException::withMessages([
                'status' => 'That status change is not allowed from the task’s current status.',
            ]);
        }

        if (! $this->statusRequirementsAreMet($task, $newStatus)) {
            throw ValidationException::withMessages([
                'status' => 'The task must have a formally assigned employee for that status.',
            ]);
        }

        if (in_array($newStatus, ['on_hold', 'cancelled'], true) && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required for this status change.',
            ]);
        }
        if (in_array($newStatus, ['on_hold', 'submitted_for_review'], true) && blank($statusDate)) {
            throw ValidationException::withMessages([
                'update_date' => 'A date is required for this status change.',
            ]);
        }

        return DB::transaction(function () use ($task, $newStatus, $reason, $actor, $isCancellation, $statusDate): TaskStatusHistory {
            $oldStatus = $task->status;
            $now = now();
            $statusAt = filled($statusDate)
                ? Carbon::parse($statusDate)->setTime($now->hour, $now->minute, $now->second)
                : $now;
            $updates = ['status' => $newStatus];

            if ($newStatus === 'in_progress' && $task->start_date === null) {
                $updates['start_date'] = today()->toDateString();
            }

            if ($newStatus === 'on_hold') {
                $updates['hold_reason'] = trim((string) $reason);
            } elseif ($task->hold_reason !== null) {
                $updates['hold_reason'] = null;
            }

            if ($newStatus === 'submitted_for_review') {
                if (blank($task->expected_output)) {
                    throw ValidationException::withMessages([
                        'status' => 'Define the expected output before submitting this task for review.',
                    ]);
                }

                $updates['submitted_at'] = $statusAt;
                $updates['submission_comment'] = filled($reason) ? trim((string) $reason) : null;
                $updates['reviewed_at'] = null;
                $updates['reviewed_by'] = null;
                $updates['review_comment'] = null;
            }

            if ($isCancellation) {
                $updates['cancellation_reason'] = trim((string) $reason);
                $updates['cancelled_by'] = $actor->id;
                $updates['cancelled_at'] = $now;
                $updates['risk_reason'] = null;
                $updates['risk_flagged_by'] = null;
                $updates['risk_flagged_at'] = null;
            }

            $task->update($updates);
            $history = $task->statusHistory()->make([
                'changed_by' => $actor->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'event_type' => match (true) {
                    $isCancellation => 'cancellation',
                    $newStatus === 'on_hold' => 'hold',
                    $newStatus === 'in_progress' => 'start',
                    $newStatus === 'submitted_for_review' => 'submission',
                    default => 'status',
                },
                'remarks' => filled($reason) ? trim((string) $reason) : null,
            ]);
            if (in_array($newStatus, ['on_hold', 'submitted_for_review'], true)) {
                $history->created_at = $statusAt;
            }
            $history->save();

            $this->notifyTaskParticipants(
                $task,
                $actor,
                match ($newStatus) {
                    'submitted_for_review' => 'Task submitted for review',
                    'cancelled' => 'Task cancelled',
                    default => 'Task status updated',
                },
                match ($newStatus) {
                    'submitted_for_review' => "submitted {$task->task_code} for review.",
                    'cancelled' => "cancelled {$task->task_code}.",
                    default => "changed {$task->task_code} to ".Task::STATUS_LABELS[$newStatus].'.',
                },
            );

            return $history;
        });
    }

    private function ensureRecurringOccurrenceIsAvailable(Task $task): void
    {
        if ($task->recurring_task_id === null) {
            return;
        }

        if ($task->status === 'missed' || $task->available_from === null || $task->expires_at === null
            || now()->lt($task->available_from) || now()->gte($task->expires_at)) {
            throw ValidationException::withMessages([
                'status' => 'This recurring occurrence is outside its permitted time window and cannot be updated.',
            ]);
        }
    }

    public function updateRisk(Task $task, string $action, ?string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('flagRisk', $task);

        if (in_array($task->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'action' => 'Risk status cannot be changed after a task is completed or cancelled.',
            ]);
        }

        if (! in_array($action, ['flag', 'resolve'], true)) {
            throw ValidationException::withMessages([
                'action' => 'Choose whether to flag or resolve the task risk.',
            ]);
        }

        if ($action === 'flag' && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A risk reason is required.',
            ]);
        }

        if ($action === 'resolve' && $task->risk_reason === null) {
            throw ValidationException::withMessages([
                'action' => 'This task does not currently have an active risk flag.',
            ]);
        }

        DB::transaction(function () use ($task, $action, $reason, $actor): void {
            $updates = $action === 'flag'
                ? [
                    'risk_reason' => trim((string) $reason),
                    'risk_flagged_by' => $actor->id,
                    'risk_flagged_at' => now(),
                ]
                : [
                    'risk_reason' => null,
                    'risk_flagged_by' => null,
                    'risk_flagged_at' => null,
                ];
            $task->update($updates);

            $task->statusHistory()->create([
                'changed_by' => $actor->id,
                'old_status' => $task->status,
                'new_status' => $task->status,
                'event_type' => 'risk',
                'remarks' => $action === 'flag'
                    ? 'Flagged at risk: '.trim((string) $reason)
                    : 'At-risk flag resolved.',
            ]);

            $this->notifyTaskParticipants(
                $task,
                $actor,
                $action === 'flag' ? 'Task flagged as at risk' : 'Task risk resolved',
                $action === 'flag'
                    ? "flagged {$task->task_code} as at risk."
                    : "resolved the risk flag on {$task->task_code}.",
            );
        });
    }

    public function review(Task $task, string $decision, ?string $comment, User $reviewer): void
    {
        Gate::forUser($reviewer)->authorize('review', $task);

        if ($task->status !== 'submitted_for_review') {
            throw ValidationException::withMessages([
                'decision' => 'Only tasks submitted for review can be reviewed.',
            ]);
        }

        if (! in_array($decision, ['approve', 'rework'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Choose whether to approve the submission or return it for re-work.',
            ]);
        }

        if (blank($task->expected_output)) {
            throw ValidationException::withMessages([
                'decision' => 'Define the expected output before approving this task.',
            ]);
        }

        if ($decision === 'approve') {
            $incompleteSubtasks = $task->subtasks()
                ->where('is_completed', false)
                ->where('status', '!=', 'completed')
                ->orderBy('sort_order')
                ->pluck('title');

            if ($incompleteSubtasks->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'decision' => 'Complete or review all required subtasks before approving this task: '.$incompleteSubtasks->implode(', ').'.',
                ]);
            }
        }

        DB::transaction(function () use ($task, $decision, $comment, $reviewer): void {
            $oldStatus = $task->status;
            $newStatus = $decision === 'approve' ? 'completed' : 'rework';
            $task->update([
                'status' => $newStatus,
                'progress_percentage' => $decision === 'approve' ? 100 : min(99, $task->progress_percentage),
                'completed_at' => $decision === 'approve' ? now() : null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_comment' => filled($comment) ? trim((string) $comment) : null,
                'risk_reason' => $decision === 'approve' ? null : $task->risk_reason,
                'risk_flagged_by' => $decision === 'approve' ? null : $task->risk_flagged_by,
                'risk_flagged_at' => $decision === 'approve' ? null : $task->risk_flagged_at,
            ]);

            $task->statusHistory()->create([
                'changed_by' => $reviewer->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'event_type' => 'review',
                'remarks' => filled($comment)
                    ? trim((string) $comment)
                    : ($decision === 'approve' ? 'Submission approved.' : 'Re-work requested.'),
            ]);

            $this->notifyTaskParticipants(
                $task,
                $reviewer,
                $decision === 'approve' ? 'Task approved' : 'Re-work requested',
                $decision === 'approve'
                    ? "approved {$task->task_code}; it is completed."
                    : "requested re-work for {$task->task_code}.",
            );
        });
    }

    public function notifyTaskParticipants(
        Task $task,
        User $actor,
        string $title,
        string $message,
    ): void {
        $recipientIds = array_merge(
            $this->administratorAndCeoIds(),
            [$task->created_by],
            [$task->reviewer_id],
            $task->pocs()->pluck('users.id')->all(),
            $task->activeAssignees()->pluck('users.id')->all(),
        );

        $this->notifyUsers($task, $recipientIds, $actor, $title, "{$actor->name} {$message}");
    }

    /**
     * @return list<int>
     */
    private function administratorAndCeoIds(): array
    {
        return User::query()
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', ['Admin', 'CEO']))
                    ->orWhereHas('primaryRole', fn (Builder $roleQuery) => $roleQuery->whereIn('name', ['Admin', 'CEO']));
            })
            ->pluck('id')
            ->all();
    }

    private function statusRequirementsAreMet(Task $task, string $status): bool
    {
        $hasActiveAssignee = $task->activeAssignees()->exists();

        if (in_array($status, ['assigned', 'in_progress', 'on_hold', 'submitted_for_review'], true)) {
            return $hasActiveAssignee;
        }

        if (in_array($status, ['notified', 'unassigned'], true)) {
            return ! $hasActiveAssignee;
        }

        return true;
    }
}

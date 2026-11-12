<?php

namespace App\Services;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskAttachment;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TaskActivityFeedService
{
    /**
     * @return Collection<int, array{
     *     id: string,
     *     at: Carbon,
     *     actor: string,
     *     designation: ?string,
     *     assigned_to: ?string,
     *     text: string,
     *     detail: ?string,
     *     badge: string,
     *     tone: string,
     *     attachments: Collection<int, TaskAttachment>
     * }>
     */
    public function build(Task $task): Collection
    {
        $task->loadMissing('statusHistory.subtask');
        $activities = collect();
        $creator = $task->creator;
        $creationPocs = $task->created_at === null
            ? collect()
            : $task->pocs->filter(
                fn (User $poc): bool => $poc->pivot->created_at?->isSameSecond($task->created_at) ?? false,
            );
        $initialNotifiedHistory = $task->created_at === null
            ? null
            : $task->statusHistory->first(fn (TaskStatusHistory $history): bool => $history->event_type === 'status'
                && $history->remarks === 'Initial task status.'
                && $history->new_status === 'notified'
                && (int) $history->changed_by === (int) $task->created_by
                && ($history->created_at?->isSameSecond($task->created_at) ?? false));
        $combinePocNotification = $creator?->hasAssignedRole('CEO') === true
            && $creationPocs->isNotEmpty()
            && $initialNotifiedHistory !== null;
        $initialNotifiedHistoryId = $initialNotifiedHistory?->getKey();

        if ($task->created_at !== null) {
            $activities->push([
                'id' => $combinePocNotification
                    ? 'task-created-poc-notified-'.$task->id
                    : 'task-created-'.$task->id,
                'at' => $task->created_at,
                'actor' => $creator?->name ?? 'Unknown user',
                'designation' => $creator?->designation,
                'assigned_to' => $combinePocNotification
                    ? $creationPocs->pluck('name')->sort()->implode(', ')
                    : null,
                'text' => $combinePocNotification ? $task->title : 'created the task',
                'detail' => $combinePocNotification ? $task->description : null,
                'badge' => $combinePocNotification ? 'Notified' : 'Created',
                'tone' => $combinePocNotification ? 'poc_notification' : 'created',
                'attachments' => collect(),
            ]);
        }

        $assignments = $task->assignments
            ->filter(fn (TaskAssignment $assignment): bool => $assignment->assigned_at !== null)
            ->sortBy('assigned_at')
            ->groupBy(fn (TaskAssignment $assignment): string => implode('|', [
                $assignment->assigned_at->format('Y-m-d H:i:s.u'),
                (string) $assignment->assigned_by,
                (string) $assignment->reason,
            ]))
            ->values();

        foreach ($assignments as $index => $assignmentGroup) {
            /** @var TaskAssignment $assignment */
            $assignment = $assignmentGroup->first();
            $assignedUsers = $assignmentGroup
                ->map(fn (TaskAssignment $assignment): string => $assignment->user?->name ?? 'Unknown user')
                ->unique()
                ->implode(', ');
            $isReassignment = $index > 0;

            $activities->push([
                'id' => 'task-assignment-'.$assignment->id,
                'at' => $assignment->assigned_at,
                'actor' => $assignment->assignedBy?->name ?? 'System',
                'designation' => $assignment->assignedBy?->designation,
                'assigned_to' => $assignedUsers,
                'text' => ($isReassignment ? 'reassigned the task to ' : 'assigned the task to ').$assignedUsers,
                'detail' => $assignment->reason === 'Initial assignment' ? null : $assignment->reason,
                'badge' => $isReassignment ? 'Reassigned' : 'Assigned',
                'tone' => $isReassignment ? 'reassigned' : 'assigned',
                'attachments' => collect(),
            ]);
        }

        foreach ($task->pocs as $poc) {
            if ($poc->pivot->created_at === null) {
                continue;
            }

            if ($combinePocNotification && $poc->pivot->created_at->isSameSecond($task->created_at)) {
                continue;
            }

            $activities->push([
                'id' => 'task-poc-'.$poc->id,
                'at' => $poc->pivot->created_at,
                'actor' => $poc->name,
                'designation' => $poc->designation,
                'assigned_to' => null,
                'text' => 'added as a department point of contact',
                'detail' => null,
                'badge' => 'Point of Contact',
                'tone' => 'poc',
                'attachments' => collect(),
            ]);
        }

        foreach ($task->statusHistory as $history) {
            if (strtolower((string) $history->event_type) === 'work_update') {
                continue;
            }

            if ($combinePocNotification && $history->getKey() === $initialNotifiedHistoryId) {
                continue;
            }

            $activities->push($this->fromHistory($history));
        }

        return $activities
            ->filter(fn (array $activity): bool => $activity['at'] !== null)
            ->sortByDesc(fn (array $activity): int => $activity['at']->getTimestamp())
            ->values();
    }

    /**
     * @return array{
     *     id: string,
     *     at: Carbon,
     *     actor: string,
     *     designation: ?string,
     *     assigned_to: ?string,
     *     text: string,
     *     detail: ?string,
     *     badge: string,
     *     tone: string,
     *     attachments: Collection<int, TaskAttachment>
     * }
     */
    private function fromHistory(TaskStatusHistory $history): array
    {
        if ($this->isSubtaskActivity($history)) {
            return $this->fromSubtaskHistory($history);
        }

        $oldStatus = $history->old_status === null
            ? null
            : (Task::STATUS_LABELS[$history->old_status] ?? $this->formatStatus($history->old_status));
        $newStatus = Task::STATUS_LABELS[$history->new_status]
            ?? $this->formatStatus($history->new_status);
        $eventType = strtolower((string) $history->event_type);

        return [
            'id' => 'task-history-'.$history->id,
            'at' => $history->created_at,
            'actor' => $history->changedBy?->name ?? 'System',
            'designation' => $history->changedBy?->designation,
            'assigned_to' => null,
            'text' => $this->historyText($history, $oldStatus, $newStatus),
            'detail' => $eventType === 'subtask' ? null : $history->remarks,
            'badge' => $this->historyBadge($history, $newStatus),
            'tone' => $history->new_status === 'completed'
                ? 'completed'
                : ($eventType === 'cancellation' ? 'cancelled' : $eventType),
            'attachments' => $history->attachments,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     at: Carbon,
     *     actor: string,
     *     designation: ?string,
     *     assigned_to: ?string,
     *     text: string,
     *     detail: ?string,
     *     badge: string,
     *     tone: string,
     *     attachments: Collection<int, TaskAttachment>
     * }
     */
    private function fromSubtaskHistory(TaskStatusHistory $history): array
    {
        $eventType = strtolower((string) $history->event_type);
        $event = str_starts_with($eventType, 'subtask_')
            ? substr($eventType, strlen('subtask_'))
            : $eventType;
        $snapshot = json_decode((string) $history->remarks, true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $subtaskTitle = $history->subtask?->title
            ?? ($snapshot['title'] ?? null)
            ?? ($event === 'deleted' ? $history->old_status : null)
            ?? 'Subtask';
        $oldValue = $history->old_status;
        $newValue = $history->new_status;
        $assignedTo = null;
        $detail = $history->remarks;
        $tone = 'updated';
        $badge = 'Subtask Activity';

        if ($event === 'created') {
            $text = "created subtask \"{$subtaskTitle}\"";
            $detail = null;
            $tone = 'created';
            $badge = 'Subtask Created';
        } elseif ($event === 'assignment') {
            $isReassignment = filled($oldValue) && mb_strtolower($oldValue) !== 'unassigned';
            $assignedTo = filled($newValue) && mb_strtolower($newValue) !== 'unassigned' ? $newValue : null;
            $text = $isReassignment
                ? "reassigned subtask \"{$subtaskTitle}\" from {$oldValue} to ".($assignedTo ?? 'Unassigned')
                : "assigned subtask \"{$subtaskTitle}\" to ".($assignedTo ?? 'Unassigned');
            $tone = $isReassignment ? 'reassigned' : 'assigned';
            $badge = $isReassignment ? 'Subtask Reassigned' : 'Subtask Assigned';
        } elseif ($event === 'status') {
            $oldStatus = $this->formatSubtaskStatus($oldValue);
            $newStatus = $this->formatSubtaskStatus($newValue);
            $transition = "Status: {$oldStatus} → {$newStatus}";

            if ($newValue === 'in_progress' && $oldValue === 'on_hold') {
                $text = "resumed subtask \"{$subtaskTitle}\"";
                $tone = 'resume';
                $badge = 'Subtask Resumed';
            } elseif ($newValue === 'in_progress') {
                $text = "started subtask \"{$subtaskTitle}\"";
                $tone = 'start';
                $badge = 'Subtask Started';
            } elseif ($newValue === 'on_hold') {
                $text = "put subtask \"{$subtaskTitle}\" on hold";
                $tone = 'hold';
                $badge = 'Subtask On Hold';
            } elseif ($newValue === 'completed') {
                $text = "completed subtask \"{$subtaskTitle}\"";
                $tone = 'completed';
                $badge = 'Subtask Completed';
            } elseif ($newValue === 'cancelled') {
                $text = "cancelled subtask \"{$subtaskTitle}\"";
                $tone = 'cancelled';
                $badge = 'Subtask Cancelled';
            } else {
                $text = "changed the status of subtask \"{$subtaskTitle}\" from {$oldStatus} to {$newStatus}";
                $tone = 'updated';
                $badge = 'Subtask Status Updated';
            }

            $detail = trim($transition.($history->remarks ? ' · '.$history->remarks : ''));
        } elseif ($event === 'daily_update') {
            $text = "added a daily update to subtask \"{$subtaskTitle}\"";
            $tone = 'updated';
            $badge = 'Subtask Daily Update';
        } elseif ($event === 'deleted') {
            $text = "deleted subtask \"{$subtaskTitle}\"";
            $tone = 'cancelled';
            $badge = 'Subtask Deleted';
            $detail = filled($snapshot['assignee'] ?? null)
                ? 'Assigned employee: '.$snapshot['assignee']
                : null;
        } elseif ($event === 'updated') {
            $text = "updated details for subtask \"{$subtaskTitle}\"";
            $badge = 'Subtask Details Updated';
        } elseif ($event === 'deadline') {
            $text = "updated the timeframe for subtask \"{$subtaskTitle}\"";
            $badge = 'Subtask Dates Updated';
        } elseif ($event === 'conflict') {
            $text = "recorded a date conflict for subtask \"{$subtaskTitle}\"";
            $tone = 'risk';
            $badge = 'Subtask Date Conflict';
        } else {
            $text = "updated subtask \"{$subtaskTitle}\"";
        }

        return [
            'id' => 'task-history-'.$history->id,
            'at' => $history->created_at,
            'actor' => $history->changedBy?->name ?? 'System',
            'designation' => $history->changedBy?->designation,
            'assigned_to' => $assignedTo,
            'text' => $text,
            'detail' => $detail,
            'badge' => $badge,
            'tone' => $tone,
            'attachments' => $history->attachments,
        ];
    }

    private function formatSubtaskStatus(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'Unknown';
        }

        return Subtask::STATUS_LABELS[$status]
            ?? ucwords(str_replace('_', ' ', $status));
    }

    private function isSubtaskActivity(TaskStatusHistory $history): bool
    {
        $eventType = strtolower((string) $history->event_type);

        return $history->subtask_id !== null
            || $history->milestone_id !== null
            || $eventType === 'subtask'
            || str_starts_with($eventType, 'subtask_');
    }

    private function historyText(TaskStatusHistory $history, ?string $oldStatus, string $newStatus): string
    {
        return match ($history->event_type) {
            'risk' => str_starts_with((string) $history->remarks, 'At-risk flag resolved')
                ? 'resolved the task risk flag'
                : 'flagged the task as At Risk',
            'review' => $history->new_status === 'completed'
                ? 'approved the submission'
                : 'returned the task for re-work',
            'accepted' => 'accepted the task for assignment',
            'start' => 'started work',
            'hold' => 'put the task on hold',
            'resume' => 'resumed work',
            'work_update' => 'added a work update',
            'submission' => 'submitted the task for review',
            'cancellation' => 'cancelled the task',
            'deadline_extension_requested' => 'requested a deadline extension',
            'deadline_extension_approved' => 'approved the deadline extension',
            'deadline_extension_rejected' => 'rejected the deadline extension',
            'subtask' => $history->remarks ?? 'updated a subtask',
            default => $oldStatus
                ? 'changed status from '.$oldStatus.' to '.$newStatus
                : 'marked the task '.$newStatus,
        };
    }

    private function historyBadge(TaskStatusHistory $history, string $newStatus): string
    {
        return match ($history->event_type) {
            'risk' => str_starts_with((string) $history->remarks, 'At-risk flag resolved')
                ? 'Risk Resolved'
                : 'At Risk',
            'subtask' => 'Subtask',
            'deadline_extension_requested' => 'Extension Requested',
            'deadline_extension_approved' => 'Extension Approved',
            'deadline_extension_rejected' => 'Extension Rejected',
            default => $newStatus,
        };
    }

    private function formatStatus(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}

<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskAttachment;
use App\Models\TaskStatusHistory;
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
     *     text: string,
     *     detail: ?string,
     *     badge: string,
     *     tone: string,
     *     attachments: Collection<int, TaskAttachment>
     * }>
     */
    public function build(Task $task): Collection
    {
        $activities = collect();

        if ($task->created_at !== null) {
            $activities->push([
                'id' => 'task-created-'.$task->id,
                'at' => $task->created_at,
                'actor' => $task->creator?->name ?? 'Unknown user',
                'designation' => $task->creator?->designation,
                'text' => 'created the task',
                'detail' => null,
                'badge' => 'Created',
                'tone' => 'created',
                'attachments' => collect(),
            ]);
        }

        $assignments = $task->assignments
            ->filter(fn (TaskAssignment $assignment): bool => $assignment->assigned_at !== null)
            ->sortBy('assigned_at')
            ->values();

        foreach ($assignments as $index => $assignment) {
            $previousAssignment = $assignments->get($index - 1);
            $activities->push([
                'id' => 'task-assignment-'.$assignment->id,
                'at' => $assignment->assigned_at,
                'actor' => $assignment->assignedBy?->name ?? 'System',
                'designation' => $assignment->assignedBy?->designation,
                'text' => ($previousAssignment ? 'reassigned the task to ' : 'assigned the task to ')
                    .($assignment->user?->name ?? 'Unknown user'),
                'detail' => $assignment->reason === 'Initial assignment' ? null : $assignment->reason,
                'badge' => $previousAssignment ? 'Reassigned' : 'Assigned',
                'tone' => $previousAssignment ? 'reassigned' : 'assigned',
                'attachments' => collect(),
            ]);
        }

        foreach ($task->pocs as $poc) {
            if ($poc->pivot->created_at === null) {
                continue;
            }

            $activities->push([
                'id' => 'task-poc-'.$poc->id,
                'at' => $poc->pivot->created_at,
                'actor' => $poc->name,
                'designation' => $poc->designation,
                'text' => 'added as a department point of contact',
                'detail' => null,
                'badge' => 'Point of Contact',
                'tone' => 'poc',
                'attachments' => collect(),
            ]);
        }

        foreach ($task->statusHistory as $history) {
            if ($this->isSubtaskActivity($history)) {
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
     *     text: string,
     *     detail: ?string,
     *     badge: string,
     *     tone: string,
     *     attachments: Collection<int, TaskAttachment>
     * }
     */
    private function fromHistory(TaskStatusHistory $history): array
    {
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
            'text' => $this->historyText($history, $oldStatus, $newStatus),
            'detail' => $eventType === 'subtask' ? null : $history->remarks,
            'badge' => $this->historyBadge($history, $newStatus),
            'tone' => $history->new_status === 'completed'
                ? 'completed'
                : ($eventType === 'cancellation' ? 'cancelled' : $eventType),
            'attachments' => $history->attachments,
        ];
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

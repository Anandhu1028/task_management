<?php

namespace App\Services;

use App\Models\Milestone;
use App\Models\MilestoneActivity;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MilestoneService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $assigneeIds
     */
    public function create(Task $task, User $actor, array $attributes, array $assigneeIds = []): Milestone
    {
        return DB::transaction(function () use ($task, $actor, $attributes, $assigneeIds): Milestone {
            $milestone = $task->milestones()->create([
                'created_by' => $actor->id,
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'milestone_date' => $attributes['milestone_date']
                    ?? today(config('app.timezone'))->toDateString(),
            ]);

            $milestone->assignees()->sync($assigneeIds);
            $this->recordActivity($milestone, $actor, 'created', 'Milestone created');
            $this->recordTaskHistory($task, $milestone, $actor, 'milestone_created', null, $milestone->status, 'Created milestone '.$milestone->title);

            if ($assigneeIds !== []) {
                $this->recordTaskHistory(
                    $task,
                    $milestone,
                    $actor,
                    'milestone_assigned',
                    null,
                    implode(',', $assigneeIds),
                    'Assigned milestone responsibilities.',
                );
            }

            return $milestone->load(['creator:id,name', 'assignees:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>|null  $assigneeIds
     * @return array{milestone: Milestone, attached_user_ids: list<int>}
     */
    public function update(
        Task $task,
        Milestone $milestone,
        User $actor,
        array $attributes,
        ?array $assigneeIds = null,
        ?string $remarks = null,
    ): array {
        return DB::transaction(function () use ($task, $milestone, $actor, $attributes, $assigneeIds, $remarks): array {
            $milestone = $task->milestones()
                ->whereKey($milestone->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $milestone->status;
            $newStatus = $attributes['status'] ?? $oldStatus;
            if (! in_array($newStatus, Milestone::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Select a valid milestone status.',
                ]);
            }

            $milestone->fill(array_intersect_key($attributes, array_flip([
                'title',
                'description',
                'milestone_date',
            ])));
            $detailsChanged = $milestone->isDirty(['title', 'description', 'milestone_date']);
            $statusChanged = $oldStatus !== $newStatus;

            if ($statusChanged) {
                $milestone->status = $newStatus;
                if ($newStatus === 'completed') {
                    $milestone->completed_at = now();
                    $milestone->completed_by = $actor->id;
                } else {
                    $milestone->completed_at = null;
                    $milestone->completed_by = null;
                }
            }

            if ($detailsChanged || $statusChanged) {
                $milestone->save();
            }

            if ($detailsChanged) {
                $this->recordActivity($milestone, $actor, 'updated', 'Milestone updated');
                $this->recordTaskHistory(
                    $task,
                    $milestone,
                    $actor,
                    'milestone_updated',
                    null,
                    'updated',
                    $remarks ?? 'Updated milestone details.',
                );
            }

            if ($statusChanged) {
                $this->recordActivity(
                    $milestone,
                    $actor,
                    'status_changed',
                    'Status updated to: '.Milestone::STATUS_LABELS[$newStatus],
                    $oldStatus,
                    $newStatus,
                );
                $this->recordTaskHistory(
                    $task,
                    $milestone,
                    $actor,
                    'milestone_status',
                    $oldStatus,
                    $newStatus,
                    $remarks,
                );
            }

            $attachedUserIds = [];
            if ($assigneeIds !== null) {
                $changes = $milestone->assignees()->sync($assigneeIds);
                $attachedUserIds = array_map('intval', $changes['attached']);
                if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                    if (! $detailsChanged && ! $statusChanged) {
                        $milestone->touch();
                    }

                    $this->recordTaskHistory(
                        $task,
                        $milestone,
                        $actor,
                        'milestone_assigned',
                        implode(',', $changes['detached']),
                        $assigneeIds === [] ? '(none)' : implode(',', $assigneeIds),
                        $remarks ?? 'Updated milestone responsibilities.',
                    );
                }
            }

            return [
                'milestone' => $milestone->load(['creator:id,name', 'assignees:id,name']),
                'attached_user_ids' => $attachedUserIds,
            ];
        });
    }

    private function recordActivity(
        Milestone $milestone,
        User $actor,
        string $type,
        string $label,
        ?string $oldStatus = null,
        ?string $newStatus = null,
    ): void {
        MilestoneActivity::query()->create([
            'milestone_id' => $milestone->id,
            'task_id' => $milestone->task_id,
            'type' => $type,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'label' => $label,
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function recordTaskHistory(
        Task $task,
        Milestone $milestone,
        User $actor,
        string $eventType,
        ?string $oldStatus,
        string $newStatus,
        ?string $remarks,
    ): void {
        $task->statusHistory()->create([
            'milestone_id' => $milestone->id,
            'changed_by' => $actor->id,
            'event_type' => $eventType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'remarks' => $remarks,
        ]);
    }
}

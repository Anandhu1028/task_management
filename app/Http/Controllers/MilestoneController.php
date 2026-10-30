<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMilestoneRequest;
use App\Http\Requests\UpdateMilestoneRequest;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Notifications\TaskLifecycleNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MilestoneController extends Controller
{
    public function store(StoreMilestoneRequest $request, Task $task): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();
        $assigneeIds = $validated['assignee_ids'] ?? [];
        unset($validated['assignee_ids']);

        $milestone = DB::transaction(function () use ($validated, $assigneeIds, $request, $task): Milestone {
            $milestone = $task->milestones()->create([
                ...$validated,
                'created_by' => $request->user()->id,
            ]);
            $milestone->assignees()->sync($assigneeIds);

            $this->recordActivity(
                $task,
                $milestone,
                $request->user()->id,
                'milestone_created',
                null,
                $milestone->status,
                'Created milestone '.$milestone->title,
            );

            if ($assigneeIds !== []) {
                $this->recordActivity(
                    $task,
                    $milestone,
                    $request->user()->id,
                    'milestone_assigned',
                    null,
                    implode(',', $assigneeIds),
                    'Assigned milestone responsibilities.',
                );
                $this->notifyAssignees($task, $assigneeIds, $request->user(), $milestone);
            }

            return $milestone->load(['creator:id,name', 'assignees:id,name']);
        });

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'milestone' => $milestone], 201);
        }

        return back()->with('success', 'Milestone created successfully.');
    }

    public function update(UpdateMilestoneRequest $request, Task $task, Milestone $milestone): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();
        $assigneeIds = array_key_exists('assignee_ids', $validated) ? ($validated['assignee_ids'] ?? []) : null;
        $remarks = $validated['remarks'] ?? null;
        unset($validated['assignee_ids'], $validated['remarks']);

        $milestone = DB::transaction(function () use (
            $validated,
            $assigneeIds,
            $remarks,
            $request,
            $task,
            $milestone,
        ): Milestone {
            $oldStatus = $milestone->status;
            $newStatus = $validated['status'] ?? $oldStatus;

            if ($newStatus !== $oldStatus
                && ! in_array($newStatus, Milestone::STATUS_TRANSITIONS[$oldStatus] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This milestone cannot move from '.Milestone::STATUS_LABELS[$oldStatus]
                        .' to '.Milestone::STATUS_LABELS[$newStatus].'.',
                ]);
            }

            $milestone->fill($validated);
            if ($newStatus === 'completed') {
                $milestone->progress_percentage = 100;
                $milestone->completed_at = now();
                $milestone->completed_by = $request->user()->id;
            } elseif ($oldStatus === 'completed') {
                $milestone->completed_at = null;
                $milestone->completed_by = null;
                $milestone->progress_percentage = 0;
            }
            $milestone->save();

            if ($oldStatus !== $newStatus) {
                $this->recordActivity(
                    $task,
                    $milestone,
                    $request->user()->id,
                    'milestone_status',
                    $oldStatus,
                    $newStatus,
                    $remarks,
                );
            } elseif ($validated !== []) {
                $this->recordActivity(
                    $task,
                    $milestone,
                    $request->user()->id,
                    'milestone_updated',
                    null,
                    'updated',
                    $remarks ?? 'Updated milestone details.',
                );
            }

            if ($assigneeIds !== null) {
                $changes = $milestone->assignees()->sync($assigneeIds);
                if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                    $this->recordActivity(
                        $task,
                        $milestone,
                        $request->user()->id,
                        'milestone_assigned',
                        implode(',', $changes['detached']),
                        implode(',', $assigneeIds),
                        $remarks ?? 'Updated milestone responsibilities.',
                    );
                    $this->notifyAssignees($task, $changes['attached'], $request->user(), $milestone);
                }
            }

            return $milestone->load(['creator:id,name', 'assignees:id,name']);
        });

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'milestone' => $milestone]);
        }

        return back()->with('success', 'Milestone updated successfully.');
    }

    /**
     * @param  list<int|string>  $userIds
     */
    private function notifyAssignees(Task $task, array $userIds, User $actor, Milestone $milestone): void
    {
        User::query()
            ->whereIn('id', $userIds)
            ->where('status', 'active')
            ->whereKeyNot($actor->id)
            ->each(fn (User $assignee) => $assignee->notify(new TaskLifecycleNotification(
                $task,
                $actor->name,
                'Milestone responsibility assigned',
                "{$actor->name} assigned you to milestone {$milestone->title} on {$task->task_code}.",
            )));
    }

    private function recordActivity(
        Task $task,
        Milestone $milestone,
        int $actorId,
        string $event,
        ?string $oldValue,
        string $newValue,
        ?string $remarks,
    ): TaskStatusHistory {
        return TaskStatusHistory::query()->create([
            'task_id' => $task->id,
            'milestone_id' => $milestone->id,
            'changed_by' => $actorId,
            'event_type' => $event,
            'old_status' => $oldValue,
            'new_status' => $newValue,
            'remarks' => $remarks,
        ]);
    }
}

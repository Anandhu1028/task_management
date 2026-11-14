<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMilestoneRequest;
use App\Http\Requests\UpdateMilestoneRequest;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskLifecycleNotification;
use App\Services\MilestoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MilestoneController extends Controller
{
    public function store(
        StoreMilestoneRequest $request,
        Task $task,
        MilestoneService $milestones,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validated();
        $assigneeIds = $validated['assignee_ids'] ?? [];
        unset($validated['assignee_ids']);

        $milestone = $milestones->create($task, $request->user(), $validated, $assigneeIds);
        if ($assigneeIds !== []) {
            $this->notifyAssignees($task, $assigneeIds, $request->user(), $milestone);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'milestone' => $milestone], 201);
        }

        return back()->with('success', 'Milestone created successfully.');
    }

    public function update(
        UpdateMilestoneRequest $request,
        Task $task,
        Milestone $milestone,
        MilestoneService $milestones,
    ): JsonResponse|RedirectResponse {
        $validated = $request->validated();
        $assigneeIds = array_key_exists('assignee_ids', $validated) ? ($validated['assignee_ids'] ?? []) : null;
        $remarks = $validated['remarks'] ?? null;
        unset($validated['assignee_ids'], $validated['remarks']);

        $result = $milestones->update(
            $task,
            $milestone,
            $request->user(),
            $validated,
            $assigneeIds,
            $remarks,
        );
        $milestone = $result['milestone'];
        if ($result['attached_user_ids'] !== []) {
            $this->notifyAssignees($task, $result['attached_user_ids'], $request->user(), $milestone);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'milestone' => $milestone]);
        }

        return back()->with('success', 'Milestone updated successfully.');
    }

    public function timeline(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $milestones = $task->milestones()
            ->with([
                'activities' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'activities.creator:id,name',
            ])
            ->get();
        $totalCount = $milestones->count();
        $completedCount = $milestones->where('status', 'completed')->count();

        return response()->json([
            'milestones' => $milestones->map(fn (Milestone $milestone): array => [
                'id' => $milestone->id,
                'title' => $milestone->title,
                'description' => $milestone->description,
                'status' => $milestone->status,
                'milestone_date' => $milestone->milestone_date->toDateString(),
                'completed_at' => $milestone->completed_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i:sP'),
                'completed_by' => $milestone->completed_by,
                'can_update' => $request->user()?->can('updateMilestone', [$task, $milestone]) ?? false,
                'activities' => $milestone->activities->map(fn ($activity): array => [
                    'id' => $activity->id,
                    'type' => $activity->type,
                    'label' => $activity->label,
                    'new_status' => $activity->new_status,
                    'actor_name' => $activity->creator?->name,
                    'created_at' => $activity->created_at->timezone(config('app.timezone'))->format('Y-m-d\TH:i:sP'),
                ])->values()->all(),
            ])->values()->all(),
            'summary' => [
                'completed_count' => $completedCount,
                'total_count' => $totalCount,
                'progress_percent' => $totalCount > 0
                    ? (int) round($completedCount / $totalCount * 100)
                    : 0,
            ],
        ]);
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
}

<?php

namespace App\Http\Controllers;

use App\Models\DeadlineExtensionRequest;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskLifecycleNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeadlineExtensionRequestController extends Controller
{
    public function store(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('requestDeadlineExtension', $task);

        $extensionRequest = DB::transaction(function () use ($request, $task): DeadlineExtensionRequest {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $this->authorize('requestDeadlineExtension', $lockedTask);

            if ($lockedTask->deadlineExtensionRequests()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages([
                    'deadline_extension' => 'A deadline extension request is already pending for this task.',
                ]);
            }

            $currentDeadline = $lockedTask->due_date ?? $lockedTask->original_deadline;
            $validated = $request->validate([
                'requested_deadline' => [
                    'required',
                    'date_format:Y-m-d',
                    'after:'.($currentDeadline?->format('Y-m-d') ?? today()->toDateString()),
                ],
                'reason' => ['required', 'string', 'max:5000'],
            ]);

            $extensionRequest = $lockedTask->deadlineExtensionRequests()->create([
                'requested_by' => $request->user()->id,
                'task_title' => $lockedTask->title,
                'current_deadline' => $currentDeadline?->toDateString(),
                'requested_deadline' => $validated['requested_deadline'],
                'reason' => trim($validated['reason']),
                'status' => DeadlineExtensionRequest::STATUS_PENDING,
            ]);

            $lockedTask->statusHistory()->create([
                'changed_by' => $request->user()->id,
                'old_status' => $lockedTask->status,
                'new_status' => $lockedTask->status,
                'event_type' => 'deadline_extension_requested',
                'remarks' => sprintf(
                    'Current deadline: %s. Requested deadline: %s. Reason: %s',
                    $currentDeadline?->format('M d, Y') ?? 'No deadline',
                    $extensionRequest->requested_deadline->format('M d, Y'),
                    $extensionRequest->reason,
                ),
            ]);

            return $extensionRequest;
        });

        $this->notifyReviewers($task, $request->user(), $extensionRequest);

        return back()->with('success', 'Deadline extension request submitted for review.');
    }

    public function review(
        Request $request,
        Task $task,
        DeadlineExtensionRequest $extensionRequest,
    ): RedirectResponse {
        abort_if((int) $extensionRequest->task_id !== (int) $task->id, 404);

        $this->authorize('review', $extensionRequest);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $extensionRequest = DB::transaction(function () use ($request, $task, $extensionRequest, $validated): DeadlineExtensionRequest {
            $lockedRequest = DeadlineExtensionRequest::query()->lockForUpdate()->findOrFail($extensionRequest->id);
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            abort_if((int) $lockedRequest->task_id !== (int) $lockedTask->id, 404);
            $this->authorize('review', $lockedRequest);

            if ($validated['decision'] === 'approve') {
                $currentDeadline = $lockedTask->due_date ?? $lockedTask->original_deadline;
                if ($currentDeadline?->toDateString() !== $lockedRequest->current_deadline?->toDateString()) {
                    throw ValidationException::withMessages([
                        'decision' => 'The task deadline changed after this request was submitted. Review the current deadline before deciding.',
                    ]);
                }
                if ($currentDeadline && $lockedRequest->requested_deadline->lte($currentDeadline)) {
                    throw ValidationException::withMessages([
                        'decision' => 'The requested deadline is no longer later than the task deadline.',
                    ]);
                }

                $lockedTask->update([
                    'due_date' => $lockedRequest->requested_deadline->toDateString(),
                ]);
            }

            $lockedRequest->update([
                'status' => $validated['decision'] === 'approve'
                    ? DeadlineExtensionRequest::STATUS_APPROVED
                    : DeadlineExtensionRequest::STATUS_REJECTED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => filled($validated['review_note'] ?? null)
                    ? trim($validated['review_note'])
                    : null,
            ]);

            $lockedTask->statusHistory()->create([
                'changed_by' => $request->user()->id,
                'old_status' => $lockedTask->status,
                'new_status' => $lockedTask->status,
                'event_type' => $validated['decision'] === 'approve'
                    ? 'deadline_extension_approved'
                    : 'deadline_extension_rejected',
                'remarks' => sprintf(
                    'Current deadline: %s. Requested deadline: %s.',
                    $lockedRequest->current_deadline?->format('M d, Y') ?? 'No deadline',
                    $lockedRequest->requested_deadline->format('M d, Y'),
                ),
            ]);

            return $lockedRequest->fresh(['task', 'requester']);
        });

        $decision = $extensionRequest->status === DeadlineExtensionRequest::STATUS_APPROVED
            ? 'approved'
            : 'rejected';
        $extensionRequest->requester?->notify(new TaskLifecycleNotification(
            $extensionRequest->task,
            $request->user()->name,
            "Deadline extension {$decision}",
            sprintf(
                'Your deadline extension request for %s from %s to %s was %s by %s.',
                $task->task_code,
                $extensionRequest->current_deadline?->format('M d, Y') ?? 'No deadline',
                $extensionRequest->requested_deadline->format('M d, Y'),
                $decision,
                $request->user()->name,
            ),
        ));

        return back()->with(
            'success',
            $extensionRequest->status === DeadlineExtensionRequest::STATUS_APPROVED
                ? 'Deadline extension approved.'
                : 'Deadline extension rejected.',
        );
    }

    private function notifyReviewers(
        Task $task,
        User $requester,
        DeadlineExtensionRequest $extensionRequest,
    ): void {
        $reviewers = User::query()
            ->where('status', 'active')
            ->where(function ($query) use ($task): void {
                $query->whereHas('primaryRole', fn ($roles) => $roles->whereIn('name', ['Admin', 'CEO']))
                    ->orWhereHas('roles', fn ($roles) => $roles->whereIn('name', ['Admin', 'CEO']))
                    ->orWhere(function ($managerQuery) use ($task): void {
                        $managerQuery->where('company_id', $task->company_id)
                            ->whereHas('roles', fn ($roles) => $roles->where('name', 'Manager'));
                    })
                    ->orWhere(function ($leaderQuery) use ($task): void {
                        $leaderQuery->where('company_id', $task->company_id)
                            ->where('department_id', $task->department_id)
                            ->where(function ($roleQuery): void {
                                $roleQuery->whereHas('primaryRole', fn ($roles) => $roles->where('name', 'Team Leader'))
                                    ->orWhereHas('roles', fn ($roles) => $roles->where('name', 'Team Leader'));
                            });
                    })
                    ->orWhereHas('pocTasks', fn ($tasks) => $tasks->whereKey($task->id));
            })
            ->where('id', '!=', $requester->id)
            ->get()
            ->unique('id');

        foreach ($reviewers as $reviewer) {
            $reviewer->notify(new TaskLifecycleNotification(
                $task,
                $requester->name,
                'Deadline extension requested',
                sprintf(
                    '%s requested a deadline extension for %s from %s to %s. Reason: %s',
                    $requester->name,
                    $task->task_code,
                    $extensionRequest->current_deadline?->format('M d, Y') ?? 'No deadline',
                    $extensionRequest->requested_deadline->format('M d, Y'),
                    $extensionRequest->reason,
                ),
            ));
        }
    }
}

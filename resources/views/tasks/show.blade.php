@extends('layouts.app')

@section('title', $task->task_code . ' · ' . $task->title)

@section('content')
    @php
        $status = $task->display_status;
        $statusLabels = \App\Models\Task::STATUS_LABELS;
        $deadlineCondition = $task->deadline_condition;
        $deadline = $task->due_date ?? $task->original_deadline;
        $daysUntilDeadline = $deadline
            ? (int) today()->diffInDays($deadline, false)
            : null;
        $currentAssignees = $task->activeAssignees;
        if ($currentAssignees->isEmpty() && $task->assignee) {
            $currentAssignees = collect([$task->assignee]);
        }
        $assigneeNames = $currentAssignees->pluck('name')->implode(', ');
        $previousAssigneeCount = $task->assignments->where('is_active', false)->count();
        $mainTaskHistory = $task->statusHistory->reject(function ($history): bool {
            $eventType = strtolower((string) $history->event_type);

            return $history->subtask_id !== null
                || $history->daily_task_id !== null
                || $history->milestone_id !== null
                || $eventType === 'subtask'
                || str_starts_with($eventType, 'subtask_');
        });

        $activity = collect();

        if ($task->created_at) {
            $activity->push([
                'at' => $task->created_at,
                'tone' => 'created',
                'actor' => $task->creator?->name ?? 'Unknown user',
                'text' => 'created the task',
                'detail' => null,
                'badge' => 'Created',
                'attachments' => collect(),
            ]);
        }

        $assignmentHistory = $task->assignments->sortBy('assigned_at')->values();

        foreach ($assignmentHistory as $index => $assignment) {
            $previousAssignment = $assignmentHistory->get($index - 1);
            $assignedUser = $assignment->user?->name ?? 'Unknown user';
            $activity->push([
                'at' => $assignment->assigned_at,
                'tone' => $previousAssignment ? 'reassigned' : 'assigned',
                'actor' => $assignment->assignedBy?->name ?? 'System',
                'text' => 'assigned the task to ' . $assignedUser,
                'detail' => $assignment->reason === 'Initial assignment' ? null : $assignment->reason,
                'badge' => $previousAssignment ? 'Reassigned' : 'Assigned',
                'attachments' => collect(),
            ]);
        }

        foreach ($task->pocs as $poc) {
            if ($poc->pivot->created_at) {
                $activity->push([
                    'at' => $poc->pivot->created_at,
                    'tone' => 'poc',
                    'actor' => $poc->name,
                    'text' => 'added as a department point of contact',
                    'detail' => null,
                    'badge' => 'Point of Contact',
                    'attachments' => collect(),
                ]);
            }
        }

        foreach ($mainTaskHistory as $history) {
            $oldStatus = $history->old_status
                ? ($statusLabels[$history->old_status] ?? ucwords(str_replace('_', ' ', $history->old_status)))
                : null;
            $newStatus = $statusLabels[$history->new_status]
                ?? ucwords(str_replace('_', ' ', $history->new_status));
            $eventText = match ($history->event_type) {
                'risk' => str_starts_with((string) $history->remarks, 'At-risk flag resolved')
                    ? 'resolved the task risk flag'
                    : 'flagged the task as At Risk',
                'review' => $history->new_status === 'completed'
                    ? 'approved the submission'
                    : 'returned the task for re-work',
                'accepted' => 'accepted the task for assignment',
                'start' => 'started work',
                'hold' => 'put the task',
                'resume' => 'resumed work',
                'work_update' => 'added a work update',
                'submission' => 'submitted the task for review',
                'cancellation' => 'cancelled the task',
                'deadline_extension_requested' => 'requested a deadline extension',
                'deadline_extension_approved' => 'approved the deadline extension',
                'deadline_extension_rejected' => 'rejected the deadline extension',
                'subtask' => $history->remarks,
                default => $oldStatus
                    ? 'changed status from ' . $oldStatus . ' to ' . $newStatus
                    : 'marked the task ' . $newStatus,
            };
            $activity->push([
                'at' => $history->created_at,
                'tone' => $history->event_type === 'cancellation' ? 'cancelled' : $history->event_type,
                'actor' => $history->changedBy?->name ?? 'System',
                'text' => $eventText,
                'detail' => $history->event_type === 'subtask' ? null : $history->remarks,
                'badge' => match ($history->event_type) {
                    'risk' => str_starts_with((string) $history->remarks, 'At-risk flag resolved')
                        ? 'Risk Resolved'
                        : 'At Risk',
                    'subtask' => 'Subtask',
                    'deadline_extension_requested' => 'Extension Requested',
                    'deadline_extension_approved' => 'Extension Approved',
                    'deadline_extension_rejected' => 'Extension Rejected',
                    default => $newStatus,
                },
                'attachments' => $task->attachments->where('status_history_id', $history->id),
            ]);
        }

        $activity = $activity
            ->filter(fn(array $event): bool => $event['at'] !== null)
            ->sortByDesc('at')
            ->take(10)
            ->values();

        $priorityTone = [
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
        ][$task->priority] ?? 'default';

        $hasReworkStage = $status === 'rework' || $mainTaskHistory->contains('new_status', 'rework');
        $workflowStages = [
            ['status' => 'notified', 'label' => 'Notified'],
            ['status' => 'unassigned', 'label' => 'Unassigned'],
            ['status' => 'assigned', 'label' => 'Assigned'],
            ['status' => 'in_progress', 'label' => 'In Progress'],
            ['status' => 'submitted_for_review', 'label' => 'Review'],
            ...($hasReworkStage ? [['status' => 'rework', 'label' => 'Re-work']] : []),
            ['status' => 'completed', 'label' => 'Completed'],
        ];
        $workflowStatus = $status === 'on_hold' ? 'in_progress' : $status;
        $currentWorkflowStage = array_search($workflowStatus, array_column($workflowStages, 'status'), true);
        $currentWorkflowStage = $currentWorkflowStage === false ? null : $currentWorkflowStage;

        // Build a map of status => first-reached date for the stepper
        $stageDateMap = [
            'notified' => $task->created_at,
            'unassigned' => null,
            'assigned' => null,
            'in_progress' => null,
            'submitted_for_review' => null,
            'rework' => null,
            'completed' => null,
        ];
        foreach ($mainTaskHistory->sortBy('created_at') as $h) {
            $ns = $h->new_status;
            // map on_hold to in_progress slot
            if ($ns === 'on_hold') {
                $ns = 'in_progress';
            }
            if (array_key_exists($ns, $stageDateMap) && $stageDateMap[$ns] === null) {
                $stageDateMap[$ns] = $h->created_at;
            }
        }
    @endphp

    <div class="tf-task-detail">
        @php
            $hasActions = !$canReviewSubmission && ($canAcceptTask || $canStartWork || $canAddWorkUpdate || $canResumeWork
                || isset($statusOptions['on_hold']) || isset($statusOptions['submitted_for_review'])
                || isset($statusOptions['cancelled']) || $canFlagRisk);
        @endphp

        @if ($hasActions || $canReviewSubmission || auth()->user()?->can('tasks.update'))
            <header class="tf-task-detail__header">
                <div class="tf-task-detail__actions">
                    @if ($canReviewSubmission)
                        <div class="tf-task-workflow__action-bar">
                            @if (isset($statusOptions['on_hold']))
                                <button type="button" class="tf-wf-action-btn tf-wf-action-btn--hold" data-wf-toggle="top-hold">
                                    <i class="bi bi-pause-circle" aria-hidden="true"></i> On Hold
                                </button>
                            @endif
                            <form method="POST" action="{{ route('tasks.review', $task) }}" class="tf-wf-action-form">
                                @csrf
                                <button type="submit" name="decision" value="approve"
                                    class="tf-wf-action-btn tf-wf-action-btn--primary">
                                    <i class="bi bi-check-circle" aria-hidden="true"></i> Approve
                                </button>
                            </form>
                            <form method="POST" action="{{ route('tasks.review', $task) }}" class="tf-wf-action-form">
                                @csrf
                                <button type="submit" name="decision" value="rework"
                                    class="tf-wf-action-btn tf-wf-action-btn--primary">
                                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Re-work
                                </button>
                            </form>
                        </div>
                    @endif
                    @if ($hasActions)
                        <div class="tf-task-workflow__action-bar">
                            @if ($canAcceptTask)
                                <button type="button" class="tf-wf-action-btn tf-wf-action-btn--primary" data-wf-toggle="top-accept">
                                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Accept Task
                                </button>
                            @endif
                            @if ($task->status === 'rework' && isset($statusOptions['submitted_for_review']))
                                <button type="button" class="tf-wf-action-btn tf-wf-action-btn--review"
                                    data-wf-toggle="top-submit-review">
                                    <i class="bi bi-check-circle" aria-hidden="true"></i> Work Done
                                </button>
                            @elseif ($canStartWork)
                                <button type="button" class="tf-wf-action-btn tf-wf-action-btn--primary" data-wf-toggle="top-start">
                                    <i class="bi bi-play-circle" aria-hidden="true"></i> Start Work
                                </button>
                            @endif
                            @if ($canResumeWork)
                                <button type="button" class="tf-wf-action-btn tf-wf-action-btn--primary" data-wf-toggle="top-resume">
                                    <i class="bi bi-play-circle" aria-hidden="true"></i> Unhold
                                </button>
                            @endif
                            @if (isset($statusOptions['on_hold']) && !$canReviewSubmission)
                                <button type="button" class="tf-wf-action-btn tf-wf-action-btn--hold" data-wf-toggle="top-hold">
                                    <i class="bi bi-pause-circle" aria-hidden="true"></i> On Hold
                                </button>
                            @endif
                            @if (isset($statusOptions['submitted_for_review']) && !$resumeToReview && $task->status !== 'rework')
                                <button type="button" class="tf-wf-action-btn tf-wf-action-btn--review"
                                    data-wf-toggle="top-submit-review">
                                    <i class="bi bi-check-circle" aria-hidden="true"></i> Work Done
                                </button>
                            @endif
                            @if (isset($statusOptions['cancelled']) && !$canReviewSubmission)
                                <button type="button" class="tf-wf-action-btn tf-wf-action-btn--cancel" data-wf-toggle="top-cancel">
                                    <i class="bi bi-x-circle" aria-hidden="true"></i> Cancel Task
                                </button>
                            @endif
                        </div>
                    @endif
                    @can('tasks.update')
                        @if (!$canReviewSubmission && !in_array($task->status, ['notified', 'completed', 'cancelled'], true))
                            <button type="button" class="tf-wf-action-btn tf-wf-action-btn--primary" data-reassign-trigger
                                data-task-id="{{ $task->id }}" data-task-code="{{ $task->task_code }}"
                                data-task-title="{{ $task->title }}" data-task-company-id="{{ $task->company_id }}"
                                data-task-company-name="{{ $task->company?->name }}"
                                data-task-department-id="{{ $task->department_id }}"
                                data-task-department-name="{{ $task->department?->name }}"
                                data-task-assignee-ids="{{ json_encode($currentAssignees->pluck('id')->all()) }}"
                                data-task-assignee-name="{{ $assigneeNames ?: 'Unassigned' }}"
                                data-reassign-action="{{ route('tasks.reassign', $task) }}" @if (session('auto_open_reassign') && $currentAssignees->isEmpty()) data-reassign-auto-open @endif>
                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                                Re-assign
                            </button>
                        @endif
                        @if (!$canReviewSubmission)
                            <a href="{{ route('tasks.index', ['edit' => $task->id]) }}"
                                class="tf-wf-action-btn tf-wf-action-btn--primary">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                Edit Task
                            </a>
                        @endif
                    @endcan
                </div>
            </header>
        @endif

        <section class="tf-task-detail__summary" aria-label="Task summary">
            <div class="tf-task-detail__heading">
                <h1>{{ $task->title }}</h1>
                <p>{{ $task->company?->name ?? 'No company' }} <span aria-hidden="true">·</span>
                    {{ $task->department?->name ?? 'No department' }}</p>
                <div class="tf-task-detail__summary-badges" aria-label="Task status details">
                    <div class="tf-task-detail__summary-badge tf-task-detail__summary-badge--workflow">
                        <span class="tf-task-detail__summary-badge-label"><i class="bi bi-arrow-repeat"
                                aria-hidden="true"></i> Workflow</span>
                        <strong>{{ $statusLabels[$status] ?? $status }}</strong>
                    </div>
                    <div class="tf-task-detail__summary-badge tf-task-detail__summary-badge--start">
                        <span class="tf-task-detail__summary-badge-label"><i class="bi bi-calendar2-check"
                                aria-hidden="true"></i> Planned start</span>
                        <strong>{{ $task->start_date?->format('M d, Y') ?? 'Not started' }}</strong>
                    </div>
                    <div class="tf-task-detail__summary-badge tf-task-detail__summary-badge--deadline">
                        <span class="tf-task-detail__summary-badge-label"><i class="bi bi-alarm" aria-hidden="true"></i>
                            Deadline health</span>
                        <strong
                            class="tf-task-status tf-task-status--{{ $deadlineCondition }}">{{ \App\Models\Task::DEADLINE_CONDITION_LABELS[$deadlineCondition] }}</strong>
                        <small>
                            @if ($daysUntilDeadline === null)
                                No deadline set
                            @elseif ($daysUntilDeadline < 0 && !in_array($status, ['completed', 'cancelled'], true))
                                {{ abs($daysUntilDeadline) }}
                                {{ \Illuminate\Support\Str::plural('day', abs($daysUntilDeadline)) }} overdue
                            @elseif ($daysUntilDeadline === 0)
                                Due today
                            @else
                                {{ $daysUntilDeadline }} {{ \Illuminate\Support\Str::plural('day', $daysUntilDeadline) }} left
                            @endif
                        </small>
                    </div>
                    <div class="tf-task-detail__summary-badge tf-task-detail__summary-badge--effort">
                        <span class="tf-task-detail__summary-badge-label"><i class="bi bi-hourglass-split"
                                aria-hidden="true"></i> Estimated effort</span>
                        <strong>{{ $task->estimated_hours ? number_format((float) $task->estimated_hours, 2) . ' hours' : 'Not set' }}</strong>
                    </div>
                </div>
            </div>
            <section class="tf-task-detail__workflow-progress" aria-labelledby="task-workflow-title-top">
                <header class="tf-task-detail__workflow-progress-header">
                    <h2 id="task-workflow-title-top"><i class="bi bi-diagram-3" aria-hidden="true"></i> Task Workflow</h2>
                    <span class="tf-task-detail__count">{{ $statusLabels[$task->status] ?? $task->status }}</span>
                </header>
                @if ($status === 'cancelled')
                    <p class="tf-task-workflow__terminal-state"><i class="bi bi-x-circle" aria-hidden="true"></i> This task was
                        cancelled.</p>
                @else
                    <ol class="tf-task-workflow__steps" aria-label="Workflow progress">
                        @foreach ($workflowStages as $index => $stage)
                            @php
                                $isCompleteStage = $status === 'completed' || ($currentWorkflowStage !== null && $index < $currentWorkflowStage);
                                $isCurrentStage = $status !== 'completed' && $index === $currentWorkflowStage;
                                $stageLabel = $status === 'on_hold' && $isCurrentStage ? 'On Hold' : $stage['label'];
                                $stageDate = $stageDateMap[$stage['status']] ?? null;
                            @endphp
                            <li
                                class="tf-task-workflow__step{{ $isCompleteStage ? ' is-complete' : '' }}{{ $isCurrentStage ? ' is-current' : '' }}">
                                <span class="tf-task-workflow__step-marker" aria-hidden="true">
                                    @if ($isCompleteStage)
                                        <i class="bi bi-check-lg"></i>
                                    @elseif ($isCurrentStage)
                                        <span class="tf-task-workflow__step-inner-ring"></span>
                                    @else
                                        <i class="bi bi-hourglass-split"></i>
                                    @endif
                                </span>
                                <span class="tf-task-workflow__step-label">{{ $stageLabel }}</span>
                                @if ($stageDate)
                                    <span class="tf-task-workflow__step-date">{{ $stageDate->format('M j') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </section>

        <div class="tf-task-detail__grid" data-activity-view="overview">
            @if ($hasActions || ($canReviewSubmission && isset($statusOptions['on_hold'])))
                <div class="tf-task-detail__workflow-actions">
                    {{-- Expandable Form Panels --}}
                    <div class="tf-task-workflow__panels">
                        @if ($canAcceptTask)
                            <div class="tf-wf-panel" id="top-accept" hidden>
                                <form method="POST" action="{{ route('tasks.accept', $task) }}" class="tf-wf-panel__form">
                                    @csrf
                                    <p>Accept this task into the department assignment queue before assigning an employee.</p>
                                    <div class="tf-wf-panel__footer">
                                        <button type="submit" class="tf-btn tf-btn--primary tf-btn--sm">Accept Task</button>
                                        <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm"
                                            data-wf-close="top-accept">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                        @if ($canStartWork && $task->status !== 'rework')
                            <div class="tf-wf-panel" id="top-start" hidden>
                                <form method="POST" action="{{ route('tasks.start', $task) }}" class="tf-wf-panel__form"
                                    enctype="multipart/form-data">
                                    @csrf
                                    <label for="topTaskStartNote">Start note <span>(required)</span></label>
                                    <textarea class="form-control" id="topTaskStartNote" name="note" rows="2" maxlength="2000"
                                        required placeholder="Add a brief note about starting work"></textarea>
                                    <label for="topTaskStartDate">Start date <span>(required)</span></label>
                                    <input type="date" class="form-control" id="topTaskStartDate" name="start_date"
                                        value="{{ today()->format('Y-m-d') }}" required>
                                    <label for="topTaskStartAttachments">Attachments <span>(optional)</span></label>
                                    <input type="file" class="form-control" id="topTaskStartAttachments" name="attachments[]"
                                        multiple>
                                    <div class="tf-wf-panel__footer">
                                        <button type="submit" class="tf-btn tf-btn--primary tf-btn--sm">Mark In Progress</button>
                                        <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm"
                                            data-wf-close="top-start">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                        @if ($canAddWorkUpdate)
                            <div class="tf-wf-panel" id="top-update" hidden>
                                <form method="POST" action="{{ route('tasks.work-updates.store', $task) }}"
                                    class="tf-wf-panel__form tf-wf-panel__form--work-update" id="workUpdateForm">
                                    @csrf
                                    <label for="topTaskWorkUpdate">Work update</label>
                                    <input type="text" class="form-control" id="topTaskWorkUpdate" name="note" maxlength="5000"
                                        required placeholder="Enter a short progress update">
                                    <label for="topWorkUpdateDate">Date</label>
                                    <input type="date" class="form-control" id="topWorkUpdateDate" name="update_date"
                                        value="{{ today()->format('Y-m-d') }}" required>
                                    <div class="tf-wf-panel__footer">
                                        <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm"
                                            data-wf-close="top-update">Cancel</button>
                                        <button type="submit" class="tf-btn tf-btn--primary tf-btn--sm">Save Update</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                        @if ($canResumeWork)
                            <div class="tf-wf-panel" id="top-resume" hidden>
                                <form method="POST" action="{{ route('tasks.resume', $task) }}" class="tf-wf-panel__form">
                                    @csrf
                                    <label for="topTaskResumeNote">Resume note <span>(optional)</span></label>
                                    <textarea class="form-control" id="topTaskResumeNote" name="note" rows="2" maxlength="2000"
                                        placeholder="Add a note when resuming"></textarea>
                                    <div class="tf-wf-panel__footer">
                                        <button type="submit" class="tf-btn tf-btn--primary tf-btn--sm">Unhold</button>
                                        <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm"
                                            data-wf-close="top-resume">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                        @if (isset($statusOptions['on_hold']))
                            <div class="tf-wf-panel" id="top-hold" hidden>
                                <form method="POST" action="{{ route('tasks.status.update', $task) }}" class="tf-wf-panel__form"
                                    enctype="multipart/form-data">
                                    @csrf
                                    <input type="hidden" name="status" value="on_hold">
                                    <label for="topTaskHoldReason">Hold note <span>(required)</span></label>
                                    <textarea class="form-control" id="topTaskHoldReason" name="reason" rows="2" maxlength="2000"
                                        required placeholder="Explain why work needs to pause"></textarea>
                                    <label for="topTaskHoldDate">Date <span>(required)</span></label>
                                    <input type="date" class="form-control" id="topTaskHoldDate" name="update_date"
                                        value="{{ today()->format('Y-m-d') }}" required>
                                    <label for="topTaskHoldAttachments">Attachments <span>(optional)</span></label>
                                    <input type="file" class="form-control" id="topTaskHoldAttachments" name="attachments[]"
                                        multiple>
                                    <div class="tf-wf-panel__footer">
                                        <button type="submit" class="tf-btn tf-btn--sm tf-task-workflow__action--hold">Confirm
                                            Hold</button>
                                        <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm"
                                            data-wf-close="top-hold">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                        @if (isset($statusOptions['submitted_for_review']) && !$resumeToReview)
                            <div class="tf-wf-panel" id="top-submit-review" hidden>
                                <form method="POST" action="{{ route('tasks.status.update', $task) }}" class="tf-wf-panel__form"
                                    enctype="multipart/form-data">
                                    @csrf
                                    <input type="hidden" name="status" value="submitted_for_review">
                                    <label for="topTaskSubmissionNote">Work done note <span>(required)</span></label>
                                    <textarea class="form-control" id="topTaskSubmissionNote" name="reason" rows="2"
                                        maxlength="2000" required placeholder="Summarize the completed work"></textarea>
                                    <label for="topTaskSubmissionDate">Date <span>(required)</span></label>
                                    <input type="date" class="form-control" id="topTaskSubmissionDate" name="update_date"
                                        value="{{ today()->format('Y-m-d') }}" required>
                                    <label for="topTaskSubmissionFiles">Attachments <span>(optional)</span></label>
                                    <input type="file" class="form-control" id="topTaskSubmissionFiles" name="attachments[]"
                                        multiple>
                                    <div class="tf-wf-panel__footer">
                                        <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm"
                                            data-wf-close="top-submit-review">Cancel</button>
                                        <button type="submit" class="tf-btn tf-btn--primary tf-btn--sm">Submit for Review</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                        @if (isset($statusOptions['cancelled']) && !$canReviewSubmission)
                            <div class="tf-wf-panel" id="top-cancel" hidden>
                                <form method="POST" action="{{ route('tasks.status.update', $task) }}" class="tf-wf-panel__form">
                                    @csrf
                                    <input type="hidden" name="status" value="cancelled">
                                    <label for="topTaskCancelReason">Cancellation reason <span>(required)</span></label>
                                    <textarea class="form-control" id="topTaskCancelReason" name="reason" rows="2" maxlength="2000"
                                        required placeholder="Explain why this task is being cancelled"></textarea>
                                    <div class="tf-wf-panel__footer">
                                        <button type="submit" class="tf-btn tf-btn--sm tf-task-workflow__action--cancel">Confirm
                                            Cancel</button>
                                        <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm"
                                            data-wf-close="top-cancel">Keep Task</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                        @if ($canFlagRisk && !$canReviewSubmission)
                            <div class="tf-wf-panel" id="top-risk" hidden>
                                <form method="POST" action="{{ route('tasks.risk.update', $task) }}" class="tf-wf-panel__form">
                                    @csrf
                                    @if ($task->risk_reason)
                                        <p class="tf-task-workflow__risk-reason"><strong>Risk reason:</strong> {{ $task->risk_reason }}
                                        </p>
                                        <input type="hidden" name="action" value="resolve">
                                        <div class="tf-wf-panel__footer">
                                            <button type="submit" class="tf-btn tf-btn--sm tf-task-workflow__action--hold">Resolve
                                                Risk</button>
                                            <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm"
                                                data-wf-close="top-risk">Cancel</button>
                                        </div>
                                    @else
                                        <input type="hidden" name="action" value="flag">
                                        <label for="topTaskRiskReason">Risk description <span>(required)</span></label>
                                        <textarea class="form-control" id="topTaskRiskReason" name="reason" rows="2" maxlength="2000"
                                            required placeholder="Describe the deadline or delivery risk"></textarea>
                                        <div class="tf-wf-panel__footer">
                                            <button type="submit" class="tf-btn tf-btn--sm tf-task-workflow__action--hold">Flag as At
                                                Risk</button>
                                            <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm"
                                                data-wf-close="top-risk">Cancel</button>
                                        </div>
                                    @endif
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <div class="tf-task-detail__main">
                <nav class="tf-task-detail__tabs" aria-label="Task details" role="tablist">
                    <button type="button" class="is-active" role="tab" aria-selected="true"
                        aria-controls="task-details-overview" data-task-detail-tab="overview">
                        <i class="bi bi-grid" aria-hidden="true"></i> Overview
                    </button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="task-details-checklist"
                        data-task-detail-tab="checklist">
                        <i class="bi bi-check2-square" aria-hidden="true"></i> Checklist
                    </button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="task-details-files"
                        data-task-detail-tab="files">
                        <i class="bi bi-file-earmark" aria-hidden="true"></i> Files
                        <span class="tf-task-detail__file-tab-count">{{ $task->attachments->count() }}</span>
                    </button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="task-details-comments"
                        data-task-detail-tab="comments">
                        <i class="bi bi-chat" aria-hidden="true"></i> Comments
                    </button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="task-details-related"
                        data-task-detail-tab="related">
                        <i class="bi bi-diagram-2" aria-hidden="true"></i> Related Tasks
                    </button>
                </nav>

                <div id="task-details-overview" role="tabpanel" data-task-detail-panel="overview">
                    {{-- In-tab workflow: hidden at desktop widths via CSS (only top-level visible) --}}

                    <section class="tf-task-detail__card" aria-labelledby="task-description-title">
                        <header class="tf-task-detail__card-header">
                            <h2 id="task-description-title"><i class="bi bi-file-text" aria-hidden="true"></i> Description
                                &amp; Details</h2>
                        </header>
                        <div class="tf-task-detail__card-body">
                            <h3 class="tf-task-detail__section-label">Description</h3>
                            @if ($task->description)
                                <div class="tf-task-detail__description">{{ $task->description }}</div>
                            @else
                                <p class="tf-task-detail__empty-copy">No additional description provided.</p>
                            @endif
                            <dl class="tf-task-detail__metadata">
                                <div>
                                    <dt>Task Created by</dt>
                                    <dd>{{ $task->creator?->name ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Priority</dt>
                                    <dd>{{ ucfirst($task->priority) }}</dd>
                                </div>
                                <div>
                                    <dt>Task Type</dt>
                                    <dd>{{ ucfirst($task->task_type ?? 'process') }}</dd>
                                </div>
                                <div>
                                    <dt>Task Created on</dt>
                                    <dd>{{ $task->created_at?->format('M d, Y · g:i A') ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Company</dt>
                                    <dd>{{ $task->company?->name ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Department</dt>
                                    <dd>{{ $task->department?->name ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Last updated</dt>
                                    <dd>{{ $task->updated_at?->format('M d, Y · g:i A') ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Estimated effort</dt>
                                    <dd>{{ $task->estimated_hours ? number_format((float) $task->estimated_hours, 2) . ' hours' : '—' }}
                                    </dd>
                                </div>
                            </dl>
                            @if ($task->recurringSchedule || $task->recurringTask)
                                @php
                                    $recurringTemplate = $task->recurringSchedule ?? $task->recurringTask;
                                    $nextOccurrence = $recurringTemplate->nextOccurrenceDate();
                                @endphp
                                <section class="tf-task-detail__output-section" aria-labelledby="task-recurrence-title">
                                    <h3 class="tf-task-detail__section-label" id="task-recurrence-title">Recurring Schedule</h3>
                                    <dl class="tf-task-detail__metadata">
                                        <div>
                                            <dt>Record type</dt>
                                            <dd>{{ $task->recurringSchedule ? 'Recurring master task' : 'Generated occurrence' }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Schedule</dt>
                                            <dd>{{ ucfirst($recurringTemplate->repeat_frequency) }} ·
                                                {{ \Illuminate\Support\Carbon::parse($recurringTemplate->start_time)->format('g:i A') }}–{{ \Illuminate\Support\Carbon::parse($recurringTemplate->end_time)->format('g:i A') }}
                                                ({{ $recurringTemplate->timezone }})</dd>
                                        </div>
                                        <div>
                                            <dt>Schedule dates</dt>
                                            <dd>{{ $recurringTemplate->start_date->format('M d, Y') }} –
                                                {{ $recurringTemplate->end_date->format('M d, Y') }}</dd>
                                        </div>
                                        <div>
                                            <dt>Occurrence date</dt>
                                            <dd>{{ $task->occurrence_date?->format('M d, Y') ?? '—' }}</dd>
                                        </div>
                                        <div>
                                            <dt>Next occurrence</dt>
                                            <dd>{{ $nextOccurrence?->format('M d, Y') ?? 'No upcoming occurrence' }}</dd>
                                        </div>
                                        <div>
                                            <dt>Recurrence status</dt>
                                            <dd>{{ ucfirst($recurringTemplate->status) }}</dd>
                                        </div>
                                    </dl>
                                </section>
                            @endif
                            <section class="tf-task-detail__output-section" aria-labelledby="task-output-title">
                                <h3 class="tf-task-detail__section-label" id="task-output-title">Expected Output /
                                    Deliverable Criteria</h3>
                                @if ($task->expected_output)
                                    <div class="tf-task-detail__output">{{ $task->expected_output }}</div>
                                @else
                                    <p class="tf-task-detail__empty-copy">No specific expected output defined for this task.</p>
                                @endif
                            </section>
                        </div>
                    </section>

                    <div class="tf-task-detail__people-cards">
                        <section class="tf-task-detail__card" aria-labelledby="task-pocs-title">
                            <header class="tf-task-detail__card-header">
                                <h2 id="task-pocs-title"><i class="bi bi-people" aria-hidden="true"></i> Department Points
                                    of Contact</h2>
                                <span class="tf-task-detail__count">{{ $task->pocs->count() }}
                                    POC{{ $task->pocs->count() === 1 ? '' : 's' }}</span>
                            </header>
                            @if ($task->pocs->isNotEmpty())
                                <ul class="tf-task-detail__people-list">
                                    @foreach ($task->pocs as $poc)
                                        @php
                                            $pocInitials = collect(preg_split('/\s+/', trim($poc->name)))
                                                ->filter()
                                                ->take(2)
                                                ->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                                ->implode('');
                                        @endphp
                                        <li class="tf-task-detail__person">
                                            <span
                                                class="tf-task-detail__avatar tf-task-detail__avatar--poc">{{ $pocInitials ?: '?' }}</span>
                                            <span class="tf-task-detail__person-copy">
                                                <strong>{{ $poc->name }}</strong>
                                                <small>{{ $poc->designation ?: 'Department representative' }}@if ($poc->email) <span
                                                aria-hidden="true">·</span> {{ $poc->email }}@endif</small>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="tf-task-detail__card-body">
                                    <p class="tf-task-detail__empty-copy">No department points of contact linked to this task.
                                    </p>
                                </div>
                            @endif
                        </section>

                        <section class="tf-task-detail__card" aria-labelledby="task-assignment-title">
                            <header class="tf-task-detail__card-header">
                                <h2 id="task-assignment-title"><i class="bi bi-people" aria-hidden="true"></i> Assignment
                                </h2>
                                <span class="tf-task-detail__count">{{ $currentAssignees->count() }} current ·
                                    {{ $previousAssigneeCount }} previous</span>
                            </header>
                            @if ($task->assignments->isNotEmpty() || $currentAssignees->isNotEmpty())
                                <ul class="tf-task-detail__people-list tf-task-detail__people-list--assignments">
                                    @foreach ($currentAssignees as $currentAssignee)
                                        @php
                                            $assignmentName = $currentAssignee->name;
                                            $assignmentInitials = collect(preg_split('/\s+/', trim($assignmentName)))
                                                ->filter()
                                                ->take(2)
                                                ->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                                ->implode('');
                                        @endphp
                                        <li class="tf-task-detail__person tf-task-detail__person--assignment">
                                            <span class="tf-task-detail__avatar">{{ $assignmentInitials ?: '?' }}</span>
                                            <span class="tf-task-detail__person-copy">
                                                <span class="tf-task-detail__person-heading">
                                                    <strong>{{ $assignmentName }}</strong>
                                                    <span class="tf-task-detail__current-badge">Current</span>
                                                </span>
                                                <small>
                                                    {{ $currentAssignee->designation ?: 'Team member' }}
                                                    @if ($currentAssignee->pivot?->assigned_at)
                                                        <span aria-hidden="true">·</span>
                                                        Assigned
                                                        {{ \Illuminate\Support\Carbon::parse($currentAssignee->pivot->assigned_at)->format('M d, Y · g:i A') }}
                                                    @endif
                                                </small>
                                                @if ($currentAssignee->pivot?->reason && $currentAssignee->pivot->reason !== 'Initial assignment')
                                                    <small class="tf-task-detail__assignment-reason">Reason:
                                                        {{ $currentAssignee->pivot->reason }}</small>
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                    @foreach ($task->assignments->where('is_active', false)->sortByDesc('assigned_at') as $assignment)
                                        @php
                                            $assignmentName = $assignment->user?->name ?? 'Unknown user';
                                            $assignmentInitials = collect(preg_split('/\s+/', trim($assignmentName)))
                                                ->filter()
                                                ->take(2)
                                                ->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                                ->implode('');
                                        @endphp
                                        <li class="tf-task-detail__person tf-task-detail__person--assignment">
                                            <span
                                                class="tf-task-detail__avatar tf-task-detail__avatar--previous">{{ $assignmentInitials ?: '?' }}</span>
                                            <span class="tf-task-detail__person-copy">
                                                <span class="tf-task-detail__person-heading">
                                                    <strong>{{ $assignmentName }}</strong>
                                                    <span class="tf-task-detail__previous-badge">Previous</span>
                                                </span>
                                                <small>{{ $assignment->user?->designation ?: 'Team member' }} <span
                                                        aria-hidden="true">·</span> Assigned
                                                    {{ $assignment->assigned_at?->format('M d, Y · g:i A') ?? 'Date unavailable' }}</small>
                                                @if ($assignment->reason && $assignment->reason !== 'Initial assignment')
                                                    <small class="tf-task-detail__assignment-reason">Reason:
                                                        {{ $assignment->reason }}</small>
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="tf-task-detail__card-body">
                                    <p class="tf-task-detail__empty-copy">No employees are assigned to this task.</p>
                                </div>
                            @endif
                        </section>
                    </div>

                </div>

                <div id="task-details-checklist" class="tf-task-detail__empty-panel tf-task-detail__card" role="tabpanel"
                    data-task-detail-panel="checklist" hidden>
                    <i class="bi bi-check2-square" aria-hidden="true"></i>
                    <strong>No checklist items</strong>
                    <p>Checklist items are not available for this task yet.</p>
                </div>
                <div id="task-details-files" role="tabpanel" data-task-detail-panel="files">
                    <section class="tf-task-detail__card" aria-labelledby="task-files-title">
                        <header class="tf-task-detail__card-header">
                            <h2 id="task-files-title"><i class="bi bi-paperclip" aria-hidden="true"></i> Task files</h2>
                            <span class="tf-task-detail__count">{{ $task->attachments->count() }}
                                file{{ $task->attachments->count() === 1 ? '' : 's' }}</span>
                        </header>
                        @if ($task->attachments->isNotEmpty())
                            <ul class="tf-task-detail__attachments">
                                @foreach ($task->attachments as $attachment)
                                    @php
                                        $isPreviewableAttachment = in_array($attachment->mime_type, [
                                            'application/pdf',
                                            'image/jpeg',
                                            'image/png',
                                            'image/webp',
                                            'text/csv',
                                            'text/plain',
                                        ], true);
                                        $isImageAttachment = str_starts_with($attachment->mime_type ?? '', 'image/');
                                        $previewUrl = route('tasks.attachments.preview', [$task, $attachment]);
                                    @endphp
                                    <li class="tf-task-detail__attachment">
                                        @if ($isImageAttachment)
                                            <a class="tf-task-detail__attachment-thumbnail" href="{{ $previewUrl }}" target="_blank"
                                                rel="noopener noreferrer" aria-label="View {{ $attachment->original_name }}">
                                                <img src="{{ $previewUrl }}" alt="" loading="lazy">
                                            </a>
                                        @else
                                            <span class="tf-task-detail__attachment-icon" aria-hidden="true">
                                                <i
                                                    class="bi {{ $attachment->mime_type === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark' }}"></i>
                                            </span>
                                        @endif
                                        <span class="tf-task-detail__attachment-copy">
                                            <strong>{{ $attachment->original_name }}</strong>
                                            <small>
                                                {{ $attachment->size_bytes >= 1048576 ? number_format($attachment->size_bytes / 1048576, 1) . ' MB' : number_format($attachment->size_bytes / 1024, 0) . ' KB' }}
                                                <span aria-hidden="true">·</span>
                                                Uploaded by {{ $attachment->uploadedBy?->name ?? 'Unknown user' }}
                                                <span aria-hidden="true">·</span>
                                                {{ $attachment->created_at?->format('M d, Y') ?? 'Date unavailable' }}
                                            </small>
                                        </span>
                                        <span class="tf-task-detail__attachment-actions" role="group"
                                            aria-label="Actions for {{ $attachment->original_name }}">
                                            @if ($isPreviewableAttachment)
                                                <a class="tf-task-detail__attachment-action tf-task-detail__attachment-action--preview"
                                                    href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer"
                                                    aria-label="Preview {{ $attachment->original_name }}" title="Preview file">
                                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                                </a>
                                            @endif
                                            <a class="tf-task-detail__attachment-action"
                                                href="{{ route('tasks.attachments.download', [$task, $attachment]) }}"
                                                aria-label="Download {{ $attachment->original_name }}" title="Download file">
                                                <i class="bi bi-download" aria-hidden="true"></i>
                                            </a>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <div class="tf-task-detail__empty-panel">
                                <i class="bi bi-file-earmark" aria-hidden="true"></i>
                                <strong>No files attached</strong>
                                <p>There are no files attached to this task.</p>
                            </div>
                        @endif
                    </section>
                </div>
                <div id="task-details-comments" class="tf-task-detail__empty-panel tf-task-detail__card" role="tabpanel"
                    data-task-detail-panel="comments" hidden>
                    <i class="bi bi-chat" aria-hidden="true"></i>
                    <strong>No comments yet</strong>
                    <p>There are no comments on this task.</p>
                </div>
                <div id="task-details-related" class="tf-task-detail__empty-panel tf-task-detail__card" role="tabpanel"
                    data-task-detail-panel="related" hidden>
                    <i class="bi bi-diagram-2" aria-hidden="true"></i>
                    <strong>No related tasks</strong>
                    <p>There are no related tasks linked to this task.</p>
                </div>
            </div>

            <aside class="tf-task-detail__sidebar" aria-label="Task activity and subtasks">
                {{-- Separate Tab Card --}}
                <div class="tf-task-detail__card tf-activity-tabs-card">
                    <nav class="tf-activity-tabs-nav" role="tablist" aria-label="Overview and task activity">
                        <button type="button" class="tf-activity-tab-btn is-active" id="tab-overview-btn"
                            data-activity-tab="overview" role="tab" aria-selected="true" aria-controls="task-details-overview">
                            <i class="bi bi-grid" aria-hidden="true"></i> Overview
                        </button>
                        <button type="button" class="tf-activity-tab-btn" id="tab-daily-tasks-btn" data-activity-tab="daily-tasks"
                            role="tab" aria-selected="false" aria-controls="panel-daily-tasks">
                            <i class="bi bi-calendar2-week" aria-hidden="true"></i> Daily Tasks
                            <span class="tf-activity-tab-badge">{{ $dailyTasks->count() }}</span>
                        </button>
                        <button type="button" class="tf-activity-tab-btn" id="tab-timeline-btn"
                            data-activity-tab="timeline" role="tab" aria-selected="false" aria-controls="panel-timeline">
                            <i class="bi bi-clock-history" aria-hidden="true"></i> Timeline
                            <span class="tf-activity-tab-badge">{{ $activity->count() }}</span>
                        </button>
                        <button type="button" class="tf-activity-tab-btn" id="tab-subtasks-btn"
                            data-activity-tab="subtasks" role="tab" aria-selected="false" aria-controls="panel-subtasks">
                            <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i> Subtasks
                            <span class="tf-activity-tab-badge">{{ $task->subtasks->count() }}</span>
                        </button>
                        <button type="button" class="tf-activity-tab-btn" id="tab-milestones-btn" data-activity-tab="milestones"
                            role="tab" aria-selected="false" aria-controls="panel-milestones">
                            <i class="bi bi-flag" aria-hidden="true"></i> Milestones
                            <span class="tf-activity-tab-badge">{{ $milestones->count() }}</span>
                        </button>
                        <button type="button" class="tf-activity-tab-btn" id="tab-requests-btn" data-activity-tab="requests"
                            role="tab" aria-selected="false" aria-controls="panel-requests">
                            <i class="bi bi-calendar2-plus" aria-hidden="true"></i> Requests
                            <span class="tf-activity-tab-badge">{{ $deadlineExtensionRequests->count() }}</span>
                        </button>
                    </nav>
                </div>

                <div class="tf-activity-tab-panel" id="panel-subtasks" role="tabpanel"
                    aria-labelledby="tab-subtasks-btn" hidden>
                    @php
                        $subtaskTotal = $task->subtasks->count();
                        $subtaskDone = $task->subtasks->where('is_completed', true)->count();
                        $subtaskPct = $subtaskTotal > 0 ? (int) round($subtaskDone / $subtaskTotal * 100) : 0;
                    @endphp

                    {{-- Card 1: Subtasks Table Card --}}
                    <div class="tf-subtask-card">
                        <div class="tf-subtask-card__header">
                            <div class="tf-subtask-card__title-group">
                                <span class="tf-subtask-card__icon" aria-hidden="true">
                                    <i class="bi bi-grid-3x3-gap"></i>
                                </span>
                                <h3 class="tf-subtask-card__title">Subtasks</h3>
                                <span class="tf-subtask-card__subtitle" id="subtasks-count-subtitle">
                                    {{ $subtaskTotal }} total ({{ $subtaskDone }} completed)
                                </span>
                            </div>
                            <div class="tf-subtask-card__actions">
                                @can('createSubtask', $task)
                                    <div class="tf-quick-add-anchor">
                                        <button type="button" class="tf-btn-add-subtask" id="subtask-add-btn"
                                            aria-expanded="false" aria-controls="subtask-quick-add-panel">
                                            <i class="bi bi-plus-lg"></i> Add Subtask
                                        </button>
                                        <section class="tf-quick-add-panel" id="subtask-quick-add-panel"
                                            aria-labelledby="quick-add-heading" hidden>
                                            <div class="tf-quick-add-panel__header">
                                                <div>
                                                    <h4 id="quick-add-heading">New subtask</h4>
                                                    <p>Add a subtask and its details.</p>
                                                </div>
                                                <button type="button" class="tf-quick-add-panel__close"
                                                    id="quick-add-cancel-btn" aria-label="Close">
                                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                            <div id="quick-add-alert" class="tf-quick-add-panel__alert" role="alert" hidden>
                                            </div>
                                            <form method="POST" action="{{ route('subtasks.store', $task) }}"
                                                id="subtask-quick-add-form" class="tf-quick-add-form">
                                                @csrf
                                                <div class="tf-quick-add-fields">
                                                    <label class="tf-quick-add-field tf-quick-add-field--full">
                                                        <span>Title <em>*</em></span>
                                                        <input type="text" name="title" id="quick-add-title"
                                                            class="form-control form-control-sm"
                                                            placeholder="Enter subtask title" required maxlength="500"
                                                            autocomplete="off">
                                                    </label>
                                                    <label class="tf-quick-add-field tf-quick-add-field--full">
                                                        <span>Description</span>
                                                        <textarea name="description" id="quick-add-desc"
                                                            class="form-control form-control-sm"
                                                            placeholder="Short description or details" rows="2"
                                                            maxlength="1000"></textarea>
                                                    </label>
                                                    <label class="tf-quick-add-field tf-quick-add-field--full">
                                                        <span>Assignee</span>
                                                        <select name="assigned_to" class="form-select form-select-sm"
                                                            aria-label="Assign to">
                                                            <option value="">Unassigned</option>
                                                            @foreach ($employees->where('company_id', $task->company_id)->where('department_id', $task->department_id) as $emp)
                                                                <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="tf-quick-add-field">
                                                        <span>Start date</span>
                                                        <input type="date" name="start_date"
                                                            class="form-control form-control-sm" aria-label="Start date">
                                                    </label>
                                                    <label class="tf-quick-add-field">
                                                        <span>Due date</span>
                                                        <input type="date" name="due_date" class="form-control form-control-sm"
                                                            aria-label="Due date" @if ($task->due_date)
                                                            max="{{ $task->due_date->format('Y-m-d') }}" @endif>
                                                    </label>
                                                    <label class="tf-quick-add-field">
                                                        <span>Priority</span>
                                                        <select name="priority" class="form-select form-select-sm"
                                                            aria-label="Priority">
                                                            <option value="medium" selected>Medium</option>
                                                            <option value="high">High</option>
                                                            <option value="critical">Critical</option>
                                                            <option value="low">Low</option>
                                                        </select>
                                                    </label>
                                                </div>
                                                <div class="tf-quick-add-actions">
                                                    <button type="button" class="btn btn-sm btn-light"
                                                        id="quick-add-cancel-footer">Cancel</button>
                                                    <button type="submit" class="btn btn-sm btn-primary" id="quick-add-submit">
                                                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add Subtask
                                                    </button>
                                                </div>
                                            </form>
                                        </section>
                                    </div>
                                @endcan
                            </div>
                        </div>

                        {{-- Progress bar --}}
                        <div class="tf-subtask-card__progress-wrap">
                            <div class="tf-subtask-card__progress-bar-track">
                                <div class="tf-subtask-card__progress-bar-fill" id="subtasks-progress-fill"
                                    style="width: {{ $subtaskPct }}%"></div>
                            </div>
                            <span class="tf-subtask-card__progress-text"
                                id="subtasks-progress-pct">{{ $subtaskPct }}%</span>
                        </div>

                        {{-- Subtasks Table --}}
                        <div class="tf-subtask-table-responsive">
                            <table class="tf-subtask-table" id="subtasks-table">
                                <thead>
                                    <tr>
                                        <th class="col-subtask">Subtask</th>
                                        <th class="col-assignee">Assignee</th>
                                        <th class="col-timeframe">Timeframe</th>
                                        <th class="col-priority">Priority</th>
                                        <th class="col-status">Status</th>
                                        <th class="col-actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="subtasks-list" data-reorder-url="{{ route('subtasks.reorder', $task) }}">
                                    @forelse ($task->subtasks->sortBy('sort_order') as $subtask)
                                        @php
                                            $stStart = $subtask->start_date;
                                            $stDue = $subtask->due_date;
                                            $stDurationDays = ($stStart && $stDue)
                                                ? max(1, (int) $stStart->diffInDays($stDue) + 1)
                                                : null;
                                            $stStatus = $subtask->status ?? ($subtask->is_completed ? 'completed' : 'todo');
                                            $statusLabel = match ($stStatus) {
                                                'completed' => 'Completed',
                                                'in_progress' => 'In Progress',
                                                'on_hold' => 'On Hold',
                                                'in_review' => 'In Review',
                                                default => 'Pending',
                                            };
                                            $statusClass = match ($stStatus) {
                                                'completed' => 'is-completed',
                                                'in_progress' => 'is-in-progress',
                                                'on_hold' => 'is-on-hold',
                                                'in_review' => 'is-in-review',
                                                default => 'is-pending',
                                            };
                                            $avatarColors = ['#3b82f6', '#2563eb', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#10b981'];
                                            $assigneeId = $subtask->assignee?->id ?? 0;
                                            $avatarBg = $avatarColors[$assigneeId % count($avatarColors)];
                                            $assigneeInitial = $subtask->assignee ? mb_strtoupper(mb_substr($subtask->assignee->name, 0, 1)) : '?';
                                        @endphp
                                        <tr class="tf-subtasks__item tf-subtask-row{{ $subtask->is_completed ? ' is-done' : '' }}"
                                            id="subtask-{{ $subtask->id }}" data-subtask-id="{{ $subtask->id }}"
                                            data-status="{{ $stStatus }}"
                                            data-subtask-url="{{ route('subtasks.show', [$task, $subtask]) }}"
                                            data-update-url="{{ route('subtasks.update', [$task, $subtask]) }}"
                                            data-delete-url="{{ route('subtasks.destroy', [$task, $subtask]) }}"
                                            draggable="true">
                                            <td class="col-subtask">
                                                <div class="tf-st-subtask-cell">
                                                    <div class="tf-st-title-desc">
                                                        <span class="tf-subtasks__title tf-st-title" contenteditable="false"
                                                            data-update-url="{{ route('subtasks.update', [$task, $subtask]) }}"
                                                            data-original="{{ e($subtask->title) }}"
                                                            title="Double-click to inline edit">{{ $subtask->title }}</span>
                                                        <div class="tf-st-description"
                                                            title="{{ $subtask->description ?: 'No details provided.' }}">
                                                            {{ $subtask->description ?: 'No details provided.' }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="col-assignee">
                                                @if ($subtask->assignee)
                                                    <div class="tf-st-assignee" title="Assigned to {{ $subtask->assignee->name }}">
                                                        <span class="tf-st-avatar"
                                                            style="background-color: {{ $avatarBg }};">{{ $assigneeInitial }}</span>
                                                        <span
                                                            class="tf-st-assignee-name">{{ Str::limit($subtask->assignee->name, 16) }}</span>
                                                    </div>
                                                @else
                                                    <div class="tf-st-assignee is-unassigned" title="Unassigned">
                                                        <span class="tf-st-avatar is-unassigned">?</span>
                                                        <span class="tf-st-assignee-name text-muted">Unassigned</span>
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="col-timeframe">
                                                <div class="tf-st-timeframe">
                                                    @if ($stStart && $stDue)
                                                        <span class="tf-st-dates">From {{ $stStart->format('M d') }} to
                                                            {{ $stDue->format('M d') }}</span>
                                                        <span class="tf-st-duration-badge">{{ $stDurationDays }}
                                                            {{ Str::plural('day', $stDurationDays) }}</span>
                                                    @elseif ($stDue)
                                                        <span class="tf-st-dates">Due {{ $stDue->format('M d') }}</span>
                                                    @elseif ($stStart)
                                                        <span class="tf-st-dates">From {{ $stStart->format('M d') }}</span>
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="col-priority">
                                                <span
                                                    class="tf-st-priority-badge tf-subtasks__meta tf-subtasks__priority--{{ $subtask->priority ?? 'medium' }}">
                                                    {{ ucfirst($subtask->priority ?? 'medium') }}
                                                </span>
                                            </td>
                                            <td class="col-status">
                                                <span class="tf-st-status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
                                            </td>
                                            <td class="col-actions">
                                                <div class="tf-st-actions-wrap">
                                                    <button type="button" class="tf-st-action-btn tf-subtask-status-trigger"
                                                        data-subtask-url="{{ route('subtasks.show', [$task, $subtask]) }}"
                                                        data-update-url="{{ route('subtasks.update', [$task, $subtask]) }}"
                                                        aria-haspopup="menu" aria-expanded="false"
                                                        aria-controls="subtaskActionsMenu" aria-label="Update subtask status"
                                                        title="Update subtask status">
                                                        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr id="subtasks-empty-row">
                                            <td colspan="6" class="text-center py-4 text-muted">
                                                <i class="bi bi-check2-square d-block fs-3 mb-2 text-primary opacity-50"></i>
                                                No subtasks created yet. Click <strong>+ Add Subtask</strong> above to create
                                                one.
                                            </td>
                                        </tr>
                                    @endforelse

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- ── TAB 1: TIMELINE VIEW ── --}}
                <div class="tf-activity-tab-panel" id="panel-timeline" role="tabpanel"
                    aria-labelledby="tab-timeline-btn" hidden>
                    @php
                        $timelineStart = ($task->start_date ?? $task->created_at ?? now())->copy()->startOfDay();
                        $timelineDue = ($deadline ?? $timelineStart->copy()->addDays(2))->copy()->startOfDay();
                        if ($timelineDue->lt($timelineStart)) {
                            $timelineDue = $timelineStart->copy();
                        }

                        $timelineRangeStart = $timelineStart->copy()->subDays(3);
                        $timelineRangeEnd = $timelineDue->copy()->addDays(5);
                        $timelineDate = fn($date): ?string => $date?->copy()
                            ->timezone(config('app.timezone'))
                            ->toDateString();
                        // Use the first chronological assignment event for the Assigned milestone.
                        // Do not rely on the database/relationship collection's implicit order.
                        $initialAssignment = $task->assignments
                            ->filter(fn($assignment) => $assignment->assigned_at !== null)
                            ->sortBy(fn($assignment) => $assignment->assigned_at->getTimestamp())
                            ->first();

                        $createdByName = $task->creator?->name ?? 'Unknown user';
                        $assignedUserName = $initialAssignment?->user?->name;
                        $assignedByUser = $initialAssignment?->assignedBy;
                        $assignedByName = $assignedByUser?->name ?? 'System';
                        $assignedByRole = $assignedByUser && method_exists($assignedByUser, 'getRoleNames')
                            ? $assignedByUser->getRoleNames()->first()
                            : ($assignedByUser?->designation ?? null);
                        $assignedByLabel = $assignedByRole
                            ? $assignedByName . ' (' . $assignedByRole . ')'
                            : $assignedByName;
                        $assignedMilestoneBadge = $assignedUserName
                            ? $assignedByLabel . ' assigned to ' . $assignedUserName
                            : null;
                        $timelineMilestones = collect([
                            [
                                'type' => 'created',
                                'label' => 'Task Created By',
                                'badge' => 'Created by ' . $createdByName,
                                'date' => $timelineDate($task->created_at),
                                'color' => 'primary',
                            ],
                            [
                                'type' => 'assigned',
                                'label' => 'Assigned',
                                'badge' => $assignedMilestoneBadge,
                                'date' => $timelineDate($initialAssignment?->assigned_at),
                                'color' => 'primary',
                            ],
                            [
                                'type' => 'deadline',
                                'label' => 'Deadline',
                                'badge' => null,
                                'date' => $timelineDate($deadline),
                                'color' => 'danger',
                            ],
                        ])->filter(fn(array $milestone): bool => filled($milestone['date']))->values();
                        $timelineSchedule = $task->recurringSchedule ?? $task->recurringTask;
                        $dailyTaskStatusTransitions = [
                            'daily_task_pending',
                            'daily_task_started',
                            'daily_task_on_hold',
                            'daily_task_resumed',
                            'daily_task_completed',
                            'daily_task_cancelled',
                            'daily_task_missed',
                        ];
                        // The timeline should show only meaningful Daily Task lifecycle transitions.
                        // Creation, assignment, progress edits and generic update records stay in the Daily Tasks panel.
                        $dailyTaskTimelineEventTypes = [
                            'daily_task_started',
                            'daily_task_on_hold',
                            'daily_task_resumed',
                            'daily_task_completed',
                        ];
                        $dailyTaskTimelineActivities = $dailyTasks
                            ->flatMap(function (\App\Models\DailyTask $dailyTask) use ($dailyTaskStatusTransitions, $dailyTaskTimelineEventTypes) {
                                $activityStatus = null;

                                return $dailyTask->activity
                                    ->filter(fn($history): bool => in_array(
                                        strtolower((string) $history->event_type),
                                        $dailyTaskTimelineEventTypes,
                                        true,
                                    ))
                                    ->sort(fn($first, $second): int => ($first->created_at?->timestamp ?? 0) <=> ($second->created_at?->timestamp ?? 0)
                                        ?: $first->id <=> $second->id)
                                    ->values()
                                    ->map(function ($history) use ($dailyTask, $dailyTaskStatusTransitions, &$activityStatus): array {
                                        $eventType = strtolower((string) $history->event_type);
                                        if (
                                            in_array($eventType, $dailyTaskStatusTransitions, true)
                                            || ($eventType === 'daily_task_created'
                                                && in_array($history->new_status, \App\Models\DailyTask::STATUSES, true))
                                        ) {
                                            $activityStatus = $history->new_status;
                                        } elseif (
                                            $activityStatus === null
                                            && in_array($history->old_status, \App\Models\DailyTask::STATUSES, true)
                                        ) {
                                            $activityStatus = $history->old_status;
                                        }
                                        $eventStatus = $activityStatus ?? $dailyTask->status;
                                        $currentStatusLabel = \App\Models\DailyTask::STATUS_LABELS[$dailyTask->status]
                                            ?? ucfirst($dailyTask->status);
                                        $details = [];
                                        if (in_array($eventType, $dailyTaskStatusTransitions, true)) {
                                            $details[] = (\App\Models\DailyTask::STATUS_LABELS[$history->old_status] ?? $history->old_status ?? '—')
                                                .' → '.(\App\Models\DailyTask::STATUS_LABELS[$history->new_status] ?? $history->new_status);
                                        }
                                        if ($eventType === 'daily_task_progress_updated') {
                                            $details[] = 'Progress: '.$history->old_status.'% → '.$history->new_status.'%';
                                        }
                                        if (filled($history->remarks)) {
                                            $details[] = 'Update: '.\Illuminate\Support\Str::limit($history->remarks, 180);
                                        }
                                        if (
                                            $eventType === 'daily_task_update_submitted'
                                            && $history->created_at !== null
                                            && $history->activity_date?->toDateString()
                                                !== $history->created_at->timezone(config('app.timezone'))->toDateString()
                                        ) {
                                            $details[] = 'Submitted on: '.$history->created_at
                                                ->timezone(config('app.timezone'))
                                                ->format('M j, Y');
                                        }

                                        return [
                                            'date' => $history->created_at?->timezone(config('app.timezone'))->toDateString()
                                                ?? $history->activity_date?->toDateString(),
                                            'time' => $history->created_at?->timezone(config('app.timezone'))->format('g:i A'),
                                            'timestamp' => $history->created_at?->timestamp,
                                            'type' => $eventType,
                                            'isDailyTask' => true,
                                            'dailyTaskId' => $dailyTask->id,
                                            'dailyTaskTitle' => $dailyTask->title,
                                            'employeeId' => $dailyTask->assignee?->id,
                                            'workDate' => $history->activity_date?->toDateString()
                                                ?? $dailyTask->work_date?->toDateString(),
                                            'progress' => (int) $dailyTask->progress_percentage,
                                            'employee' => $dailyTask->assignee?->name ?? 'Former employee',
                                            'department' => $dailyTask->assignee?->department?->name ?? 'Unknown department',
                                            'currentStatus' => $currentStatusLabel,
                                            'eventStatus' => $eventStatus,
                                            'statusTransition' => in_array($eventType, $dailyTaskStatusTransitions, true),
                                            'actor' => $history->changedBy?->name ?? 'System',
                                            'from' => in_array($eventType, $dailyTaskStatusTransitions, true)
                                                ? (\App\Models\DailyTask::STATUS_LABELS[$history->old_status] ?? $history->old_status)
                                                : null,
                                            'to' => in_array($eventType, $dailyTaskStatusTransitions, true)
                                                ? (\App\Models\DailyTask::STATUS_LABELS[$history->new_status] ?? $history->new_status)
                                                : null,
                                            'detail' => implode(' · ', $details),
                                        ];
                                    });
                            })
                            ->filter(fn(array $event): bool => $event['date'] !== null)
                            ->values();
                        $accountabilityTimelineActivities = $accountabilityEvents
                            ->map(fn(\App\Models\DailyAccountabilityEvent $event): array => [
                                'date' => $event->report_date?->toDateString(),
                                'time' => $event->created_at?->timezone(config('app.timezone'))->format('g:i A'),
                                'timestamp' => $event->created_at?->timestamp,
                                'type' => 'daily_accountability_'.$event->event_type,
                                'actor' => $event->employee_name,
                                'employee' => $event->employee_name,
                                'department' => $event->employee?->department?->name ?? 'Unknown department',
                                'dailyTaskId' => $event->daily_task_id,
                                'dailyTaskTitle' => $event->daily_task_title,
                                'employeeId' => $event->employee_id,
                                'isDailyTask' => true,
                                'isAccountability' => true,
                                'accountabilityTone' => 'missed',
                                'detail' => $event->description,
                            ])
                            ->filter(fn(array $event): bool => $event['date'] !== null)
                            ->values();
                        $timelineDates = collect([$timelineRangeStart, $timelineRangeEnd])
                            ->merge($mainTaskHistory->pluck('created_at'))
                            ->merge($task->statusHistory->whereNotNull('subtask_id')->pluck('created_at'))
                            ->merge($mainTaskHistory->where('event_type', 'work_update')->pluck('activity_date'))
                            ->merge($dailyTaskTimelineActivities->pluck('date'))
                            ->merge($accountabilityTimelineActivities->pluck('date'))
                            ->merge($task->assignments->pluck('assigned_at'))
                            ->merge($task->assignments->pluck('unassigned_at'))
                            ->merge($deadlineExtensionRequests->pluck('created_at'))
                            ->merge($deadlineExtensionRequests->pluck('updated_at'))
                            ->merge($timelineMilestones->pluck('date'))
                            ->merge($timelineSchedule ? [$timelineSchedule->start_date, $timelineSchedule->end_date] : [])
                            ->merge($timelineSchedule?->occurrences->pluck('occurrence_date') ?? [])
                            ->filter()
                            ->map(fn($date) => is_string($date)
                                ? \Illuminate\Support\Carbon::parse($date, config('app.timezone'))->startOfDay()
                                : $date->copy()->timezone(config('app.timezone'))->startOfDay());
                        $timelineRangeStart = $timelineDates
                            ->sortBy(fn($date) => $date->getTimestamp())
                            ->first();
                        $timelineRangeEnd = $timelineDates
                            ->sortByDesc(fn($date) => $date->getTimestamp())
                            ->first();
                        $statusTimelineActivities = $mainTaskHistory
                            ->filter(function ($history) use ($task): bool {
                                $eventType = strtolower((string) $history->event_type);

                                // The initial task status is creation metadata, not a daily work update.
                                if (
                                    $eventType === 'status'
                                    && $history->old_status === null
                                    && str_starts_with((string) $history->remarks, 'Initial task status')
                                ) {
                                    return false;
                                }

                                if (
                                    $eventType === 'status'
                                    && $history->old_status === 'unassigned'
                                    && $history->new_status === 'assigned'
                                    && $task->assignments->contains(
                                        fn($assignment): bool => $assignment->assigned_at?->timestamp === $history->created_at?->timestamp,
                                    )
                                ) {
                                    return false;
                                }

                                if ($eventType === 'task_updated') {
                                    $audit = json_decode((string) $history->remarks, true);
                                    $changes = is_array($audit) && is_array($audit['changes'] ?? null)
                                        ? $audit['changes']
                                        : [];
                                    if (count($changes) === 1 && array_key_exists('assigned_to', $changes)) {
                                        return false;
                                    }
                                }

                                return true;
                            })
                            ->map(function ($history): array {
                                $detail = $history->remarks;
                                if ($history->event_type === 'task_updated') {
                                    $audit = json_decode((string) $detail, true);
                                    if (is_array($audit) && is_array($audit['changes'] ?? null)) {
                                        unset($audit['changes']['assigned_to']);
                                        $detail = json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    }
                                }

                                return [
                                'date' => $history->created_at?->timezone(config('app.timezone'))->toDateString()
                                    ?? $history->activity_date?->toDateString(),
                                'time' => $history->created_at?->timezone(config('app.timezone'))->format('g:i A'),
                                'timestamp' => $history->created_at?->timestamp,
                                'type' => $history->event_type,
                                'actor' => $history->changedBy?->name ?? 'System',
                                'from' => $history->old_status,
                                'to' => $history->new_status,
                                'detail' => $history->event_type === 'work_update'
                                    && $history->activity_date?->toDateString() !== $history->created_at?->timezone(config('app.timezone'))->toDateString()
                                    ? trim((string) $detail).' · Work date: '.$history->activity_date?->format('M j, Y')
                                    : $detail,
                                ];
                            })
                            ->filter(fn(array $event): bool => $event['date'] !== null);
                        if ($task->created_at !== null) {
                            $statusTimelineActivities->prepend([
                                'date' => $task->created_at->timezone(config('app.timezone'))->toDateString(),
                                'time' => $task->created_at->timezone(config('app.timezone'))->format('g:i A'),
                                'timestamp' => $task->created_at->timestamp,
                                'type' => 'created',
                                'actor' => $task->creator?->name ?? 'System',
                                'from' => null,
                                'to' => null,
                                'detail' => 'Main task created.',
                            ]);
                        }

                        $orderedAssignments = $task->assignments
                            ->filter(fn($assignment) => $assignment->assigned_at !== null)
                            ->sortBy(fn($assignment) => $assignment->assigned_at->getTimestamp())
                            ->values();
                        $firstAssignmentTimestamp = $orderedAssignments->first()?->assigned_at?->getTimestamp();
                        $assignmentTimelineActivities = $orderedAssignments
                            ->map(fn($assignment, $index) => [
                                'date' => $assignment->assigned_at->timezone(config('app.timezone'))->toDateString(),
                                'time' => $assignment->assigned_at->timezone(config('app.timezone'))->format('g:i A'),
                                'timestamp' => $assignment->assigned_at->timestamp,
                                'type' => $assignment->assigned_at->getTimestamp() === $firstAssignmentTimestamp ? 'assignment' : 'reassignment',
                                'actor' => $assignment->assignedBy?->name ?? 'System',
                                'from' => $orderedAssignments->get($index - 1)?->user?->name,
                                'to' => $assignment->user?->name ?? 'Unknown user',
                                'detail' => $assignment->reason,
                            ]);

                        $deadlineExtensionActivities = $deadlineExtensionRequests
                            ->flatMap(function ($extensionRequest) use ($timelineDate, $statusTimelineActivities): array {
                                $requestedDate = $timelineDate($extensionRequest->created_at);
                                $reviewedDate = $timelineDate($extensionRequest->updated_at);
                                $requestedDeadline = $extensionRequest->requested_deadline?->format('M d, Y') ?? 'Unknown date';
                                $currentDeadline = $extensionRequest->current_deadline?->format('M d, Y') ?? 'No deadline';
                                $reason = trim((string) $extensionRequest->reason);
                                $events = [];

                                if ($requestedDate) {
                                    $events[] = [
                                        'date' => $requestedDate,
                                        'time' => $extensionRequest->created_at?->timezone(config('app.timezone'))->format('g:i A'),
                                        'timestamp' => $extensionRequest->created_at?->timestamp,
                                        'type' => 'deadline_extension_requested',
                                        'actor' => $extensionRequest->requester?->name ?? 'Unknown user',
                                        'from' => $currentDeadline,
                                        'to' => $requestedDeadline,
                                        'detail' => 'Requested deadline extension' . ($reason !== '' ? ': ' . $reason : ''),
                                    ];
                                }

                                if (
                                    in_array($extensionRequest->status, ['approved', 'rejected'], true)
                                    && $reviewedDate
                                    && $extensionRequest->updated_at?->gt($extensionRequest->created_at)
                                ) {
                                    $events[] = [
                                        'date' => $reviewedDate,
                                        'time' => $extensionRequest->updated_at?->timezone(config('app.timezone'))->format('g:i A'),
                                        'timestamp' => $extensionRequest->updated_at?->timestamp,
                                        'type' => $extensionRequest->status === 'approved'
                                            ? 'deadline_extension_approved'
                                            : 'deadline_extension_rejected',
                                        'actor' => $extensionRequest->reviewer?->name ?? 'System',
                                        'from' => $currentDeadline,
                                        'to' => $requestedDeadline,
                                        'detail' => ucfirst($extensionRequest->status) . ' deadline extension'
                                            . (filled($extensionRequest->review_note) ? ': ' . $extensionRequest->review_note : ''),
                                    ];
                                }

                                return $events;
                            });

                        // Prefer the request model's richer deadline values when a matching
                        // lifecycle event also exists in task_status_histories.
                        $statusTimelineActivities = $statusTimelineActivities->reject(
                            fn(array $existing): bool => $deadlineExtensionActivities->contains(
                                fn(array $requestEvent): bool => $existing['type'] === $requestEvent['type']
                                    && $existing['timestamp'] === $requestEvent['timestamp'],
                            ),
                        );

                        $timelineActivities = $statusTimelineActivities
                            ->concat($assignmentTimelineActivities)
                            ->concat($deadlineExtensionActivities)
                            ->sort(fn(array $first, array $second): int => ($first['date'] ?? '') <=> ($second['date'] ?? '')
                                ?: (int) ($first['timestamp'] ?? 0) <=> (int) ($second['timestamp'] ?? 0))
                            ->values();
                        $subtaskTimelineActivities = $task->statusHistory
                            ->filter(fn($history): bool => $history->subtask_id !== null)
                            ->map(function ($history) use ($task): array {
                                $subtask = $task->subtasks->firstWhere('id', $history->subtask_id);

                                return [
                                    'date' => $history->created_at?->timezone(config('app.timezone'))->toDateString(),
                                    'time' => $history->created_at?->timezone(config('app.timezone'))->format('g:i A'),
                                    'timestamp' => $history->created_at?->timestamp,
                                    'type' => $history->event_type,
                                    'actor' => $history->changedBy?->name ?? 'System',
                                    'from' => $history->old_status,
                                    'to' => $history->new_status,
                                    'subtaskTitle' => $subtask?->title ?? 'Subtask',
                                    'detail' => $history->remarks,
                                ];
                            })
                            ->filter(fn(array $event): bool => $event['date'] !== null)
                            ->values();
                        $dailyTaskUpdateDates = $dailyTasks
                            ->flatMap(fn(\App\Models\DailyTask $dailyTask) => $dailyTask->activity
                                ->filter(fn($history): bool => strtolower((string) $history->event_type) === 'daily_task_update_submitted')
                                ->map(fn($history): array => [
                                    'date' => $history->activity_date?->toDateString()
                                        ?? $history->created_at?->timezone(config('app.timezone'))->toDateString(),
                                    'employeeId' => $dailyTask->assignee?->id,
                                ]))
                            ->filter(fn(array $item): bool => filled($item['date']) && $item['employeeId'] !== null)
                            ->values();

                        $timelineModel = [
                            'range' => [
                                'start' => $timelineRangeStart->toDateString(),
                                'end' => $timelineRangeEnd->toDateString(),
                                'today' => today()->toDateString(),
                            ],
                            'task' => [
                                'id' => $task->id,
                                'title' => $task->title,
                                'priority' => ucfirst($task->priority),
                                'start' => $timelineStart->toDateString(),
                                'due' => $timelineDue->toDateString(),
                                'assignee' => $assigneeNames ?: 'Unassigned',
                                'assigneeIds' => $currentAssignees->pluck('id')->values(),
                                'status' => $task->status,
                                'priority' => strtolower((string) $task->priority),
                                'created' => $task->created_at?->timezone(config('app.timezone'))->toDateString(),
                            ],
                            'dailyTaskUpdateDates' => $dailyTaskUpdateDates,
                            'dailyUpdates' => $dailyTaskTimelineActivities
                                ->concat($accountabilityTimelineActivities)
                                ->sort(fn(array $first, array $second): int => ($first['date'] ?? '') <=> ($second['date'] ?? '')
                                    ?: (int) ($first['timestamp'] ?? 0) <=> (int) ($second['timestamp'] ?? 0))
                                ->values(),
                            'activities' => $timelineActivities,
                            'subtaskActivities' => $subtaskTimelineActivities,
                            'hasSubtasks' => $task->subtasks->isNotEmpty(),
                            'milestones' => $timelineMilestones,
                            'recurrence' => $timelineSchedule ? [
                                'frequency' => $timelineSchedule->repeat_frequency,
                                'status' => $timelineSchedule->status,
                                'start' => $timelineSchedule->start_date?->toDateString(),
                                'end' => $timelineSchedule->end_date?->toDateString(),
                                'occurrences' => $timelineSchedule->occurrences->map(fn($occurrence) => [
                                    'date' => $occurrence->occurrence_date?->toDateString(),
                                    'status' => $occurrence->status,
                                    'title' => $occurrence->task_code ?? $occurrence->title,
                                ])->filter(fn(array $occurrence): bool => $occurrence['date'] !== null)->values(),
                            ] : null,
                        ];
                    @endphp
                    <section class="task-timeline" data-task-timeline data-timeline-model='@json($timelineModel)'
                        aria-label="Task update timeline">
                        <style>
                            .task-timeline {
                                --tl-cell: 64px;
                                overflow: hidden;
                                border: 1px solid #e8ecf5;
                                border-radius: 24px;
                                background: #fff;
                                box-shadow: 0 12px 28px rgba(28, 48, 85, .08);
                                color: #334155;
                                font: 12px/1.35 Inter, ui-sans-serif, system-ui, sans-serif;
                            }

                            .task-timeline *,
                            .task-timeline *::before,
                            .task-timeline *::after {
                                box-sizing: border-box;
                            }

                            .task-timeline button,
                            .task-timeline select {
                                font: inherit;
                            }

                            .tl-toolbar {
                                display: flex;
                                align-items: center;
                                justify-content: space-between;
                                gap: 10px;
                                flex-wrap: wrap;
                                padding: 14px 16px;
                                border-bottom: 1px solid #e8ecf5;
                                background: #fff;
                            }

                            .tl-toolbar__group,
                            .tl-segment {
                                display: flex;
                                align-items: center;
                                gap: 6px;
                            }

                            .tl-button {
                                min-height: 30px;
                                padding: 0 11px;
                                border: 1px solid #e1e7f1;
                                border-radius: 999px;
                                background: #fff;
                                color: #475569;
                                font-size: 11px;
                                font-weight: 700;
                                cursor: pointer;
                                transition: .16s ease;
                            }

                            .tl-button:hover,
                            .tl-button:focus-visible {
                                border-color: #93b4f7;
                                color: #1d4ed8;
                                outline: 3px solid rgba(37, 99, 235, .15);
                                outline-offset: 1px;
                            }

                            .tl-button--primary {
                                border-color: #2563eb;
                                background: #2563eb;
                                color: #fff;
                                box-shadow: 0 4px 10px rgba(37, 99, 235, .22);
                            }

                            .tl-button--primary:hover {
                                background: #1d4ed8;
                                color: #fff;
                            }

                            .tl-segment {
                                padding: 3px;
                                border-radius: 999px;
                                background: #f3f5fb;
                            }

                            .tl-segment button {
                                min-height: 25px;
                                padding: 0 9px;
                                border: 0;
                                border-radius: 999px;
                                background: transparent;
                                color: #7a869a;
                                font-size: 11px;
                                font-weight: 700;
                                cursor: pointer;
                            }

                            .tl-segment button.is-active {
                                background: #fff;
                                color: #2563eb;
                                box-shadow: 0 1px 4px rgba(50, 69, 108, .16);
                            }

                            .tl-simulator {
                                display: flex;
                                align-items: center;
                                gap: 5px;
                                margin-left: auto;
                                color: #7a869a;
                                font-size: 10px;
                                white-space: nowrap;
                            }

                            .tl-simulator select {
                                max-width: 110px;
                                padding: 4px 22px 4px 7px;
                                border: 1px solid #e1e7f1;
                                border-radius: 7px;
                                background: #fff;
                                color: #475569;
                                font-size: 10px;
                            }

                            .tl-scroller {
                                overflow: auto;
                                max-height: 620px;
                                scrollbar-gutter: stable;
                            }

                            .tl-grid {
                                display: grid;
                                grid-template-columns: 220px max-content;
                                min-width: 796px;
                            }

                            .tl-left {
                                position: sticky;
                                left: 0;
                                z-index: 5;
                                border-right: 1px solid #e8ecf5;
                                background: #fbfcff;
                            }

                            .tl-item-head {
                                display: flex;
                                align-items: center;
                                min-height: 66px;
                                padding: 0 16px;
                                border-bottom: 1px solid #e8ecf5;
                                color: #60708a;
                                font-size: 10px;
                                font-weight: 800;
                                letter-spacing: .08em;
                            }

                            .tl-calendar {
                                position: relative;
                                min-width: calc(var(--tl-days) * var(--tl-cell));
                            }

                            .tl-months,
                            .tl-dates,
                            .tl-row {
                                display: grid;
                                grid-template-columns: repeat(var(--tl-days), var(--tl-cell));
                            }

                            .tl-months {
                                height: 28px;
                                border-bottom: 1px solid #edf0f6;
                            }

                            .tl-month {
                                display: flex;
                                align-items: center;
                                padding-left: 10px;
                                border-right: 1px solid #edf0f6;
                                color: #475569;
                                font-size: 10px;
                                font-weight: 800;
                            }

                            .tl-dates {
                                height: 38px;
                                border-bottom: 1px solid #e8ecf5;
                            }

                            .tl-date {
                                position: relative;
                                display: grid;
                                place-items: center;
                                border-right: 1px solid #f0f2f7;
                                color: #7a869a;
                                font-size: 10px;
                                font-weight: 700;
                            }

                            .tl-date small {
                                display: block;
                                color: #9aa5b5;
                                font-size: 9px;
                                font-weight: 800;
                            }

                            .tl-date time {
                                display: grid;
                                place-items: center;
                                width: 22px;
                                height: 22px;
                                border-radius: 999px;
                            }

                            .tl-date.is-today time {
                                background: #2563eb;
                                color: #fff;
                            }

                            .tl-weekend {
                                background: #f7f9fd;
                            }

                            .tl-today-column {
                                position: absolute;
                                top: 0;
                                bottom: 0;
                                width: var(--tl-cell);
                                z-index: 1;
                                background: rgba(37, 99, 235, .1);
                                pointer-events: none;
                            }

                            .tl-today-column::after {
                                position: absolute;
                                top: 0;
                                bottom: 0;
                                left: calc(50% - 1px);
                                z-index: 2;
                                width: 2px;
                                background: #2563eb;
                                content: '';
                                opacity: .72;
                            }

                            .tl-label-section,
                            .tl-label-row {
                                display: flex;
                                align-items: center;
                                box-sizing: border-box;
                                min-height: 48px;
                                padding: 0 16px;
                                border-bottom: 1px solid #edf0f6;
                                gap: 9px;
                            }

                            .tl-label-section {
                                min-height: 36px;
                                background: #f3f7fd;
                                color: #40516c;
                                font-size: 10px;
                                font-weight: 800;
                                letter-spacing: .02em;
                                cursor: pointer;
                            }

                            .tl-label-section button {
                                display: inline-grid;
                                width: 20px;
                                height: 20px;
                                border-radius: 5px;
                                place-items: center;
                                padding: 0;
                                border: 0;
                                background: transparent;
                                color: #5575ad;
                                cursor: pointer;
                            }

                            .tl-label-section button[aria-expanded='false'] i {
                                transform: rotate(-90deg);
                            }

                            .tl-count {
                                margin-left: auto;
                                padding: 3px 7px;
                                border-radius: 999px;
                                background: #eaf1ff;
                                color: #4164a4;
                                font-size: 9px;
                                font-weight: 700;
                                white-space: nowrap;
                            }

                            .tl-label-row i {
                                display: inline-grid;
                                width: 20px;
                                height: 20px;
                                flex: 0 0 20px;
                                border-radius: 6px;
                                place-items: center;
                                background: #edf4ff;
                                color: #5b7dd8;
                                font-size: 10px;
                                text-align: center;
                            }

                            .tl-label-row span {
                                min-width: 0;
                                flex: 1;
                                overflow: hidden;
                                text-overflow: ellipsis;
                                white-space: nowrap;
                                font-weight: 650;
                            }

                            .tl-subtask-label-copy {
                                display: grid;
                                gap: 3px;
                            }

                            .tl-subtask-label-copy strong {
                                overflow: hidden;
                                text-overflow: ellipsis;
                                white-space: nowrap;
                            }

                            .tl-subtask-label-copy small {
                                color: #7c8ba1;
                                font-size: 9px;
                                font-weight: 600;
                            }

                            .tl-subtask-label-copy small i {
                                display: inline;
                                width: auto;
                                height: auto;
                                margin-right: 2px;
                                background: transparent;
                                color: inherit;
                                font-size: inherit;
                            }

                            .tl-row {
                                position: relative;
                                box-sizing: border-box;
                                min-height: 48px;
                                border-bottom: 1px solid #edf0f6;
                                background-image: repeating-linear-gradient(to right, transparent 0, transparent calc(var(--tl-cell) - 1px), #f0f2f7 calc(var(--tl-cell) - 1px), #f0f2f7 var(--tl-cell));
                            }

                            .tl-row[data-tl-row="task"] {
                                min-height: 56px;
                                background-color: #f1f6ff;
                            }

                            .tl-row[data-tl-row="subtasks"] {
                                min-height: 56px;
                                background-color: #f8fbff;
                            }

                            .tl-left > .tl-label-row[data-tl-row="task"] {
                                min-height: 56px;
                            }

                            .tl-left > .tl-label-row[data-tl-row="subtasks"] {
                                min-height: 56px;
                            }

                            .tl-row[data-tl-row="updates"] {
                                background-color: #f8fbff;
                            }

                            .tl-row--daily-missed {
                                background-color: #fff7f8 !important;
                            }

                            .tl-row[data-tl-row="milestones"] {
                                background-color: #f8fbff;
                            }

                            .tl-row[data-tl-row="recurring"] {
                                background-color: #f5f3ff;
                            }

                            .tl-section-row {
                                box-sizing: border-box;
                                min-height: 36px;
                                border-bottom: 1px solid #edf0f6;
                                background: #f8fafe;
                            }

                            .tl-row[hidden],
                            .tl-label-row[hidden] {
                                display: none;
                            }

                            .tl-bar {
                                position: absolute;
                                z-index: 2;
                                top: 13px;
                                left: calc(var(--tl-start) * var(--tl-cell) + 5px);
                                width: calc(var(--tl-length) * var(--tl-cell) - 10px);
                                min-width: 22px;
                                height: 23px;
                                border-radius: 999px;
                                box-shadow: 0 3px 7px rgba(37, 99, 235, .2);
                            }

                            .tl-workflow-marker {
                                position: absolute;
                                top: 50%;
                                left: calc((var(--tl-offset) + .5) * var(--tl-cell) + var(--tl-marker-shift, 0px));
                                z-index: 3;
                                display: grid;
                                width: 17px;
                                height: 17px;
                                place-items: center;
                                transform: translate(-50%, -50%);
                                border: 2px solid #fff;
                                border-radius: 50%;
                                color: #334155;
                                font-size: 8px;
                                line-height: 1;
                                box-shadow: 0 1px 4px rgba(15, 23, 42, .25);
                                cursor: help;
                            }

                            .tl-workflow-marker--neutral {
                                background: #cbd5e1;
                                color: #475569;
                            }

                            .tl-workflow-marker--assigned {
                                background: #bfdbfe;
                                color: #1d4ed8;
                            }

                            .tl-workflow-marker--hold {
                                background: #fed7aa;
                                color: #c2410c;
                            }

                            .tl-workflow-marker--resumed {
                                background: #bbf7d0;
                                color: #15803d;
                            }

                            .tl-workflow-marker--review {
                                background: #1d4ed8;
                                color: #fff;
                            }

                            .tl-workflow-marker--completed {
                                background: #22c55e;
                                color: #fff;
                            }

                            .tl-bar--risk {
                                background: linear-gradient(90deg, #fb7185, #e11d48);
                            }

                            .tl-marker {
                                display: grid;
                                width: 16px;
                                height: 16px;
                                place-items: center;
                                border: 2px solid #fff;
                                border-radius: 999px;
                                background: #fff;
                                color: #16a34a;
                                font-size: 9px;
                            }

                            .tl-marker--upcoming {
                                border-style: dashed;
                                color: #2563eb;
                            }

                            .tl-marker--missed {
                                color: #e11d48;
                            }

                            .tl-update {
                                position: absolute;
                                top: 11px;
                                left: calc(var(--tl-start) * var(--tl-cell) + 9px);
                                display: grid;
                                width: 30px;
                                height: 27px;
                                place-items: center;
                                border-radius: 8px;
                                background: #2563eb;
                                color: #fff;
                                box-shadow: 0 3px 7px rgba(37, 99, 235, .2);
                                font-size: 12px;
                            }

                            .tl-update--upcoming {
                                border: 2px dashed #74a4fb;
                                background: #dbeafe;
                                color: #2563eb;
                            }

                            .tl-update--open {
                                border: 2px dashed #d97706;
                                background: #fff7ed;
                                color: #d97706;
                            }

                            .tl-update--missed {
                                background: #e11d48;
                            }

                            .tl-update--paused {
                                border: 1px solid #cbd5e1;
                                background: #e2e8f0;
                                color: #475569;
                            }

                            .tl-update--deferred {
                                border: 1px solid #cbd5e1;
                                background: #f1f5f9;
                                color: #475569;
                            }

                            .tl-row-copy {
                                position: absolute;
                                top: 14px;
                                left: calc((var(--tl-start) + 1) * var(--tl-cell) + 5px);
                                width: 220px;
                                overflow: hidden;
                                text-overflow: ellipsis;
                                white-space: nowrap;
                                color: #7a869a;
                                font-size: 10px;
                            }

                            .tl-row-copy strong {
                                color: #334155;
                            }

                            .tl-row-copy--daily {
                                top: 50%;
                                left: calc(var(--tl-start) * var(--tl-cell) + 6px);
                                z-index: 2;
                                display: block;
                                width: min(360px, calc((var(--tl-days) - var(--tl-start)) * var(--tl-cell) - 12px));
                                white-space: normal;
                                overflow: visible;
                                transform: translateY(-50%);
                            }

                            .tl-daily-no-update {
                                position: absolute;
                                top: 50%;
                                left: calc(var(--tl-start) * var(--tl-cell) + 4px);
                                z-index: 4;
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                gap: 5px;
                                width: max-content;
                                max-width: min(220px, calc((var(--tl-days) - var(--tl-start)) * var(--tl-cell) - 8px));
                                min-height: 29px;
                                padding: 4px 8px;
                                border: 1px solid #fda4af;
                                border-radius: 7px;
                                background: #fff1f2;
                                color: #be123c;
                                font-size: 9px;
                                font-weight: 800;
                                line-height: 1.15;
                                white-space: nowrap;
                                box-shadow: 0 2px 7px rgba(225, 29, 72, .14);
                                transform: translateY(-50%);
                            }

                            .tl-daily-no-update i {
                                flex: 0 0 auto;
                                font-size: 10px;
                            }

                            .tl-daily-no-update strong {
                                color: inherit;
                                font-size: 9px;
                                font-weight: 800;
                            }

                            .tl-row--daily-warning {
                                background-color: #fff7f8 !important;
                            }

                            .tl-daily-waiting {
                                position: absolute;
                                top: 50%;
                                left: calc(var(--tl-start) * var(--tl-cell) + 4px);
                                z-index: 3;
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                gap: 3px;
                                width: calc(var(--tl-cell) - 8px);
                                min-width: 0;
                                min-height: 27px;
                                padding: 3px 4px;
                                overflow: hidden;
                                border: 1px dashed #d97706;
                                border-radius: 7px;
                                background: #fff7ed;
                                color: #b45309;
                                font-size: 8px;
                                font-weight: 750;
                                line-height: 1.1;
                                white-space: nowrap;
                                box-shadow: 0 2px 5px rgba(217, 119, 6, .12);
                                transform: translateY(-50%);
                            }

                            .tl-daily-waiting--below {
                                top: 39px;
                                transform: none;
                            }

                            .tl-daily-waiting--upcoming {
                                border-color: #cbd5e1;
                                background: #f1f5f9;
                                color: #475569;
                                box-shadow: 0 2px 5px rgba(15, 23, 42, .07);
                            }

                            .tl-daily-waiting i {
                                display: inline-grid;
                                width: 12px;
                                height: 12px;
                                flex: 0 0 12px;
                                place-items: center;
                                color: currentColor;
                                font-size: 8px;
                            }

                            .tl-daily-waiting strong {
                                overflow: hidden;
                                color: inherit;
                                font-size: 8px;
                                font-weight: 800;
                                text-overflow: ellipsis;
                            }

                            .tl-daily-events {
                                display: flex;
                                flex-direction: column;
                                align-items: stretch;
                                gap: 8px;
                            }

                            .tl-daily-event[hidden] {
                                display: none !important;
                            }

                            .tl-daily-label-row {
                                min-height: 56px;
                                background: #fff;
                            }

                            .tl-task-label-row {
                                min-height: 62px;
                                background: #f8fbff;
                            }

                            .tl-task-section-title {
                                min-width: 0;
                                margin-left: auto;
                                overflow: hidden;
                                color: #64748b;
                                font-size: 9px;
                                font-weight: 600;
                                text-overflow: ellipsis;
                                white-space: nowrap;
                            }

                            .tl-daily-expand {
                                display: inline-flex;
                                align-items: center;
                                gap: 4px;
                                margin-left: auto;
                                padding: 3px 6px;
                                border: 1px solid #dbe5f5;
                                border-radius: 999px;
                                background: #eef4ff;
                                color: #315a9c;
                                font-size: 10px;
                                font-weight: 700;
                                cursor: pointer;
                                white-space: nowrap;
                            }

                            .tl-daily-expand i {
                                font-size: 10px;
                                transition: transform .15s ease;
                            }

                            .tl-daily-expand[aria-expanded='true'] i {
                                transform: rotate(180deg);
                            }

                            .tl-daily-event {
                                position: relative;
                                z-index: 2;
                                display: inline-flex;
                                align-items: center;
                                width: max-content;
                                max-width: min(250px, calc(var(--tl-days) * var(--tl-cell) - 12px));
                                align-self: flex-start;
                                min-height: 32px;
                                padding: 6px 10px;
                                border: 1px solid #93c5fd;
                                border-radius: 8px;
                                background: #dbeafe;
                                color: #1e40af;
                                line-height: 1.25;
                                text-align: left;
                                box-shadow: 0 2px 6px rgba(37, 99, 235, .12);
                                cursor: help;
                            }

                            .tl-daily-event strong {
                                display: flex;
                                align-items: center;
                                gap: 6px;
                                min-width: 0;
                                overflow: hidden;
                                color: #1d4ed8;
                                font-size: 11px;
                                font-weight: 750;
                                line-height: 1.25;
                                text-overflow: ellipsis;
                                white-space: nowrap;
                            }

                            .tl-event-tooltip {
                                position: absolute;
                                bottom: calc(100% + 8px);
                                left: 50%;
                                z-index: 50;
                                display: grid;
                                width: min(300px, calc(100vw - 32px));
                                min-width: min(220px, calc(100vw - 32px));
                                gap: 7px;
                                padding: 11px 13px;
                                border: 1px solid #e2e8f0;
                                border-radius: 11px;
                                background: #fff;
                                color: #334155;
                                font-size: 11px;
                                font-weight: 500;
                                line-height: 1.45;
                                text-align: left;
                                white-space: normal;
                                overflow-wrap: anywhere;
                                box-shadow: 0 8px 24px rgba(15, 23, 42, .18);
                                pointer-events: none;
                                opacity: 0;
                                visibility: hidden;
                                transform: translate(-50%, 4px);
                                transition: opacity .12s ease, transform .12s ease, visibility .12s ease;
                            }

                            .tl-event-tooltip::after {
                                position: absolute;
                                top: 100%;
                                left: 50%;
                                width: 9px;
                                height: 9px;
                                border-right: 1px solid #e2e8f0;
                                border-bottom: 1px solid #e2e8f0;
                                background: #fff;
                                content: '';
                                transform: translate(-50%, -50%) rotate(45deg);
                            }

                            .tl-event-tooltip__heading {
                                color: #1e293b;
                                font-size: 12px;
                                font-weight: 750;
                                line-height: 1.35;
                            }

                            .tl-event-tooltip__context {
                                padding: 5px 7px;
                                border-radius: 6px;
                                background: #f8fafc;
                                color: #475569;
                                font-size: 11px;
                                font-weight: 700;
                                line-height: 1.4;
                            }

                            .tl-event-tooltip__daily-info {
                                display: grid;
                                gap: 4px;
                            }

                            .tl-event-tooltip__daily-info-row {
                                display: flex;
                                align-items: baseline;
                                justify-content: space-between;
                                gap: 12px;
                                padding: 5px 7px;
                                border-radius: 6px;
                                background: #f8fafc;
                                line-height: 1.35;
                            }

                            .tl-event-tooltip__daily-info-label {
                                flex: 0 0 auto;
                                color: #64748b;
                                font-size: 10px;
                                font-weight: 700;
                            }

                            .tl-event-tooltip__daily-info-value {
                                min-width: 0;
                                color: #334155;
                                font-size: 10px;
                                font-weight: 700;
                                text-align: right;
                                overflow-wrap: anywhere;
                            }

                            .tl-event-tooltip__people {
                                display: grid;
                                grid-template-columns: repeat(2, minmax(0, 1fr));
                                gap: 8px;
                                padding: 7px 0;
                                border-top: 1px solid #eef2f7;
                                border-bottom: 1px solid #eef2f7;
                            }

                            .tl-event-tooltip__person {
                                display: grid;
                                min-width: 0;
                                gap: 2px;
                            }

                            .tl-event-tooltip__person-label {
                                color: #64748b;
                                font-size: 9px;
                                font-weight: 700;
                            }

                            .tl-event-tooltip__person-value {
                                overflow-wrap: anywhere;
                                color: #1e293b;
                                font-size: 10px;
                                font-weight: 650;
                            }

                            .tl-event-tooltip__meta,
                            .tl-event-tooltip__actor {
                                display: flex;
                                align-items: flex-start;
                                gap: 7px;
                                color: #64748b;
                                font-size: 10px;
                                font-weight: 600;
                            }

                            .tl-event-tooltip__meta i,
                            .tl-event-tooltip__actor i {
                                flex: 0 0 13px;
                                margin-top: 1px;
                                color: #64748b;
                            }

                            .tl-event-tooltip__detail {
                                padding-top: 7px;
                                border-top: 1px solid #eef2f7;
                                color: #334155;
                                font-size: 11px;
                                font-weight: 500;
                                line-height: 1.5;
                            }

                            .tl-daily-event.is-tooltip-visible .tl-event-tooltip {
                                opacity: 1;
                                visibility: visible;
                                transform: translate(-50%, 0);
                            }

                            .tl-daily-event.is-tooltip-visible {
                                z-index: 30;
                            }

                            .tl-daily-event.is-tooltip-align-left .tl-event-tooltip {
                                left: 0;
                                transform: translateY(4px);
                            }

                            .tl-daily-event.is-tooltip-align-left.is-tooltip-visible .tl-event-tooltip {
                                transform: translateY(0);
                            }

                            .tl-daily-event.is-tooltip-align-left .tl-event-tooltip::after {
                                left: 18px;
                            }

                            .tl-daily-event.is-tooltip-align-right .tl-event-tooltip {
                                right: 0;
                                left: auto;
                                transform: translateY(4px);
                            }

                            .tl-daily-event.is-tooltip-align-right.is-tooltip-visible .tl-event-tooltip {
                                transform: translateY(0);
                            }

                            .tl-daily-event.is-tooltip-align-right .tl-event-tooltip::after {
                                right: 18px;
                                left: auto;
                            }

                            .tl-daily-event:focus-visible {
                                outline: 2px solid #2563eb;
                                outline-offset: 2px;
                            }

                            .tl-daily-event.is-daily-task-activity {
                                border-left-width: 4px;
                            }

                            .tl-task-event strong {
                                display: flex;
                                align-items: center;
                                gap: 6px;
                            }

                            .tl-task-event strong i {
                                display: inline-grid;
                                width: 18px;
                                height: 18px;
                                flex: 0 0 18px;
                                place-items: center;
                                border-radius: 50%;
                                background: rgba(37, 99, 235, .12);
                                font-size: 9px;
                            }

                            .tl-daily-event--deadline {
                                border-color: #c4b5fd;
                                background: #f5f3ff;
                                color: #5b21b6;
                            }

                            .tl-daily-event--deadline strong {
                                color: #6d28d9;
                            }

                            .tl-daily-event--in_progress,
                            .tl-daily-event--pending,
                            .tl-daily-event--planned,
                            .tl-daily-event--update-submitted {
                                border-color: #93c5fd;
                                background: #eff6ff;
                                color: #1e40af;
                            }

                            .tl-daily-event--on_hold {
                                border-color: #fdba74;
                                background: #fff7ed;
                                color: #9a3412;
                            }

                            .tl-daily-event--completed {
                                border-color: #86efac;
                                background: #f0fdf4;
                                color: #166534;
                            }

                            .tl-daily-event--cancelled,
                            .tl-daily-event--missed {
                                border-color: #fda4af;
                                background: #fff1f2;
                                color: #9f1239;
                            }

                            .tl-daily-event--late {
                                border-color: #fdba74;
                                background: #fff7ed;
                                color: #9a3412;
                            }

                            .tl-daily-event--exempted {
                                border-color: #cbd5e1;
                                background: #f8fafc;
                                color: #475569;
                            }

                            .tl-daily-event--reversed {
                                border-color: #86efac;
                                background: #f0fdf4;
                                color: #166534;
                            }

                            .tl-daily-event.is-workflow-start {
                                border-color: #93c5fd;
                                background: #eff6ff;
                            }

                            .tl-daily-event.is-workflow-hold {
                                border-color: #fdba74;
                                background: #fff7ed;
                                color: #9a3412;
                            }

                            .tl-daily-event.is-workflow-hold strong {
                                color: #c2410c;
                            }

                            .tl-daily-event.is-workflow-resume {
                                border-color: #86efac;
                                background: #f0fdf4;
                                color: #166534;
                            }

                            .tl-daily-event.is-workflow-resume strong {
                                color: #15803d;
                            }

                            .tl-daily-event.is-daily-task-update {
                                border: 1px solid #cbd5e1;
                                border-left: 3px solid #64748b;
                                background: #f8fafc;
                                color: #475569;
                                box-shadow: 0 1px 2px rgba(15, 23, 42, .05);
                            }

                            .tl-daily-event.is-daily-task-update strong {
                                display: inline-flex;
                                align-items: center;
                                gap: 7px;
                                color: #1e293b;
                                font-weight: 600;
                                letter-spacing: .01em;
                            }

                            .tl-daily-event.is-daily-task-update strong i {
                                display: inline-grid;
                                width: 19px;
                                height: 19px;
                                flex: 0 0 19px;
                                place-items: center;
                                border-radius: 6px;
                                background: #e2e8f0;
                                color: #475569;
                                font-size: 10px;
                            }

                            .tl-daily-event.is-alert {
                                border-color: #e96b73;
                                background: #ffa1a7;
                                color: #9f1239;
                                box-shadow: 0 2px 6px rgba(225, 29, 72, .10);
                            }

                            .tl-daily-event.is-alert strong {
                                color: #be123c;
                            }

                            .tl-daily-event.is-alert small {
                                color: #881337;
                            }

                            .tl-daily-warning {
                                color: #be123c !important;
                            }

                            .tl-dependency {
                                position: absolute;
                                top: 25px;
                                left: calc((var(--tl-start) + .7) * var(--tl-cell));
                                color: #a8b2c1;
                            }

                            .tl-dependency svg {
                                width: 55px;
                                height: 30px;
                                overflow: visible;
                            }

                            .tl-subtask {
                                position: absolute;
                                top: 7px;
                                left: calc(var(--tl-start) * var(--tl-cell) + 7px);
                                z-index: 3;
                                display: flex;
                                align-items: center;
                                width: 28px;
                                height: 34px;
                                min-width: 28px;
                                padding: 3px 8px;
                                overflow: hidden;
                                border-radius: 999px;
                                background: #dbeafe;
                                color: #1d4ed8;
                            }

                            .tl-subtask-connector {
                                height: 8px;
                                z-index: 4;
                            }

                            .tl-subtask-event-connector {
                                top: var(--tl-connector-top, 0px);
                                height: 10px;
                            }

                            .tl-milestone-badge.tl-subtask-event-badge {
                                top: var(--tl-badge-top, 8px);
                                left: calc(var(--tl-start) * var(--tl-cell) + 4px);
                                display: flex;
                                align-items: flex-start;
                                flex-direction: column;
                                gap: 2px;
                                width: max-content;
                                max-width: 360px;
                                min-height: 38px;
                                overflow: visible;
                                white-space: normal;
                            }

                            .tl-subtask-event-title {
                                display: block;
                                max-width: 340px;
                                overflow-wrap: anywhere;
                                font-size: 10px;
                                font-weight: 750;
                                line-height: 1.25;
                            }

                            .tl-subtask-event-change {
                                display: block;
                                max-width: 340px;
                                overflow-wrap: anywhere;
                                color: #334155;
                                font-size: 9px;
                                font-weight: 500;
                                line-height: 1.25;
                                white-space: normal;
                            }

                            .tl-subtask-event-change strong {
                                color: #1e3a8a;
                            }

                            .tl-subtask-event-time {
                                display: block;
                                color: #4164a4;
                                font-size: 8px;
                                font-weight: 600;
                                line-height: 1.2;
                            }

                            .tl-subtask-schedule {
                                position: absolute;
                                top: 10px;
                                left: calc(var(--tl-start) * var(--tl-cell) + 4px);
                                width: max(148px, calc(var(--tl-length) * var(--tl-cell) - 8px));
                                max-width: 230px;
                                min-width: 148px;
                                min-height: 42px;
                                display: flex;
                                flex-direction: column;
                                justify-content: center;
                                gap: 3px;
                                padding: 5px 10px;
                                overflow: visible;
                                border-radius: 9px;
                                background: #dbeafe;
                                color: #1e40af;
                                box-shadow: inset 0 0 0 1px #93c5fd, 0 2px 5px rgba(37, 99, 235, .10);
                                z-index: 3;
                            }

                            .tl-subtask-schedule strong,
                            .tl-subtask-schedule small {
                                display: block;
                                max-width: 100%;
                                overflow: visible;
                                text-overflow: clip;
                                white-space: nowrap;
                            }

                            .tl-subtask-schedule strong {
                                font-size: 10px;
                                font-weight: 800;
                            }

                            .tl-subtask-schedule small {
                                font-size: 9px;
                                font-weight: 650;
                            }

                            .tl-subtask-schedule.is-completed {
                                width: max(148px, calc(var(--tl-length) * var(--tl-cell) - 8px));
                                max-width: none;
                                background: #dcfce7;
                                color: #166534;
                                box-shadow: inset 0 0 0 1px #86efac;
                            }

                            .tl-subtask-date-marker {
                                position: absolute;
                                top: 58px;
                                z-index: 4;
                                display: inline-flex;
                                align-items: center;
                                min-height: 24px;
                                padding: 4px 9px;
                                border-radius: 6px;
                                font-size: 10px;
                                font-weight: 850;
                                line-height: 1.2;
                                white-space: nowrap;
                                box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
                            }

                            .tl-subtask-date-marker--start {
                                left: calc(var(--tl-start) * var(--tl-cell) + 2px);
                                background: #dbeafe;
                                color: #1d4ed8;
                            }

                            .tl-subtask-date-marker--deadline {
                                left: calc(var(--tl-start) * var(--tl-cell) + 2px);
                                min-width: 158px;
                                justify-content: center;
                                background: #ffe4e6;
                                color: #be123c;
                                border: 1px solid #fecdd3;
                                transform: translateX(-100%);
                            }

                            .tl-subtask-warning {
                                position: absolute;
                                top: 76px;
                                left: calc(var(--tl-start) * var(--tl-cell) + 3px);
                                z-index: 3;
                                display: inline-flex;
                                align-items: center;
                                gap: 5px;
                                max-width: 420px;
                                padding: 4px 7px;
                                border: 1px solid #fbbf24;
                                border-radius: 6px;
                                background: #fffbeb;
                                color: #92400e;
                                font-size: 9px;
                                font-weight: 800;
                                white-space: nowrap;
                            }

                            .tl-subtask-warning.is-overdue {
                                border-color: #fda4af;
                                background: #fff1f2;
                                color: #be123c;
                            }

                            .tl-subtask__copy {
                                display: grid;
                                min-width: 0;
                                gap: 1px;
                                line-height: 1.15;
                            }

                            .tl-subtask__copy strong,
                            .tl-subtask__copy small {
                                overflow: hidden;
                                text-overflow: ellipsis;
                                white-space: nowrap;
                            }

                            .tl-subtask__copy strong {
                                color: #1e3a8a;
                                font-size: 10px;
                            }

                            .tl-subtask__copy small {
                                color: #4164a4;
                                font-size: 9px;
                            }

                            .tl-row--deadline::before {
                                position: absolute;
                                top: 23px;
                                left: 0;
                                width: calc(var(--tl-start) * var(--tl-cell) + 24px);
                                border-top: 2px dotted #e11d48;
                                content: '';
                                z-index: 1;
                                pointer-events: none;
                            }

                            .tl-milestone {
                                position: absolute;
                                z-index: 2;
                                top: 16px;
                                left: calc(var(--tl-start) * var(--tl-cell) + 16px);
                                width: 15px;
                                height: 15px;
                                transform: rotate(45deg);
                                border-radius: 3px;
                                background: #2563eb;
                                box-shadow: 0 2px 6px rgba(37, 99, 235, .22);
                            }

                            .tl-milestone--danger {
                                background: #e11d48;
                            }

                            .tl-milestone-badge {
                                position: absolute;
                                z-index: 3;
                                top: 10px;
                                left: calc(var(--tl-start) * var(--tl-cell) + 4px);
                                display: inline-flex;
                                align-items: center;
                                width: max-content;
                                min-width: 170px;
                                max-width: min(340px, calc(var(--tl-days) * var(--tl-cell) - 12px));
                                min-height: 32px;
                                overflow: visible;
                                padding: 7px 11px;
                                border: 1px solid #bfdbfe;
                                border-radius: 8px;
                                background: #dbeafe;
                                color: #1d4ed8;
                                font-size: 11px;
                                font-weight: 800;
                                line-height: 1.25;
                                text-align: left;
                                white-space: normal;
                                overflow-wrap: anywhere;
                                box-shadow: 0 2px 6px rgba(37, 99, 235, .14);
                            }

                            .tl-milestone-badge--assigned,
                            .tl-milestone-badge--unassigned {
                                min-width: 210px;
                                max-width: min(360px, calc(var(--tl-days) * var(--tl-cell) - 12px));
                            }

                            .tl-milestone-badge--deadline {
                                min-width: 170px;
                                justify-content: center;
                                border-color: #fecdd3;
                                background: #ffe4e6;
                                color: #be123c;
                            }

                            .tl-milestone-connector {
                                position: absolute;
                                top: 0;
                                left: calc(var(--tl-start) * var(--tl-cell) + (var(--tl-cell) / 2));
                                z-index: 4;
                                height: 10px;
                                border-left: 1.5px solid #f59e0b;
                                pointer-events: none;
                            }

                            .tl-milestone-connector::after {
                                position: absolute;
                                bottom: 0;
                                left: -4px;
                                width: 6px;
                                height: 6px;
                                border-right: 1.5px solid #f59e0b;
                                border-bottom: 1.5px solid #f59e0b;
                                content: '';
                                transform: rotate(45deg);
                                background: transparent;
                            }

                            .tl-escalation {
                                position: absolute;
                                top: 10px;
                                left: calc(var(--tl-start) * var(--tl-cell) + 16px);
                                color: #e11d48;
                                font-size: 10px;
                                font-weight: 700;
                                white-space: nowrap;
                            }

                            .tl-recurring {
                                position: absolute;
                                top: 13px;
                                left: calc(var(--tl-start) * var(--tl-cell) + 11px);
                                display: grid;
                                width: 25px;
                                height: 25px;
                                place-items: center;
                                border-radius: 50%;
                                background: #e0ecff;
                                color: #2563eb;
                                font-size: 12px;
                            }

                            .tl-recurring--completed {
                                background: #dcfce7;
                                color: #16a34a;
                            }

                            .tl-recurring--missed {
                                background: #ffe4e6;
                                color: #e11d48;
                            }

                            .tl-legend {
                                display: flex;
                                flex-wrap: wrap;
                                gap: 13px;
                                padding: 12px 16px;
                                background: #fbfcff;
                                color: #7a869a;
                                font-size: 10px;
                            }

                            .tl-legend span {
                                display: inline-flex;
                                align-items: center;
                                gap: 5px;
                            }

                            .tl-legend i {
                                display: inline-block;
                                width: 9px;
                                height: 9px;
                                border-radius: 3px;
                                background: #2563eb;
                            }

                            .tl-legend .missed {
                                background: #e11d48;
                            }

                            .tl-legend .upcoming {
                                border: 1px dashed #94a3b8;
                                background: #e2e8f0;
                            }

                            .tl-legend .milestone {
                                transform: rotate(45deg);
                                border-radius: 1px;
                            }

                            .tl-legend .dependency {
                                width: 12px;
                                height: 1px;
                                border-radius: 0;
                                background: #a8b2c1;
                            }

                            .tl-legend .today {
                                background: #bfdbfe;
                            }

                            .tl-timeline-header {
                                display: flex;
                                justify-content: flex-end;
                                padding: 10px 12px 0;
                            }

                            .tl-expand-button {
                                display: inline-grid;
                                width: 30px;
                                height: 30px;
                                border: 1px solid #dbe5f5;
                                border-radius: 8px;
                                place-items: center;
                                background: #fff;
                                color: #475569;
                                cursor: pointer;
                            }

                            .tl-expand-button:hover,
                            .tl-expand-button:focus-visible {
                                border-color: #93b4f7;
                                color: #1d4ed8;
                                outline: 3px solid rgba(37, 99, 235, .15);
                                outline-offset: 1px;
                            }

                            @media (max-width: 640px) {
                                .tl-toolbar {
                                    padding: 12px;
                                }

                                .tl-toolbar__group:last-child {
                                    width: 100%;
                                }

                                .tl-simulator {
                                    margin-left: 0;
                                }

                                .tl-grid {
                                    grid-template-columns: 190px max-content;
                                }
                            }


                            /* Professional, non-red palette for task timeline event badges. */
                            .tl-task-event {
                                border-color: #cbd5e1;
                                background: #f8fafc;
                                color: #475569;
                                box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
                            }

                            .tl-task-event strong {
                                color: inherit;
                            }

                            .tl-task-event.tl-daily-event--created,
                            .tl-task-event.tl-daily-event--assigned {
                                    border-color: #c0daff;
                                    background: #dbeafe;
                                    color: #1d4ed8;
                                }

                            .tl-task-event.tl-daily-event--started {
                                   border-color: #3cbff3;
                                    background: #90dfff;
                                    color: #00435f;
                                }

                            .tl-task-event.tl-daily-event--on_hold {
                                border-color: #f5b621;
                                background: #ffba18;
                                color: #3f1800;
                            }

                            .tl-task-event.tl-daily-event--resumed {
                                border-color: #99e6d5;
                                background: #f0fdfa;
                                color: #0f766e;
                            }

                            .tl-task-event.tl-daily-event--submitted {
                                   border-color: #05cb96;
                                    background: #35f1bf;
                                    color: #00412f;
                                }

                            .tl-task-event.tl-daily-event--review {
                                border-color: #d8b4fe;
                                background: #faf5ff;
                                color: #7e22ce;
                            }

                            .tl-task-event.tl-daily-event--completed {
                                    border-color: #019b3d;
                                    background: #01c100;
                                    color: #000000;
                                }

                            .tl-task-event.tl-daily-event--cancelled,
                            .tl-task-event.tl-daily-event--deadline,
                            .tl-task-event.tl-daily-event--updated,
                            .tl-task-event.tl-daily-event--progress,
                            .tl-task-event.tl-daily-event--activity {
                                border-color: #cbd5e1;
                                background: #f8fafc;
                                color: #475569;
                            }

                        </style>
                        <div class="tl-timeline-header">
                            <button type="button" class="tl-expand-button" data-tl-expand
                                aria-label="Expand timeline" aria-expanded="false" title="Expand timeline">
                                <i class="bi bi-arrows-angle-expand" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="tl-scroller" data-tl-scroller tabindex="0"
                            aria-label="Horizontally scrollable task timeline">
                            <div class="tl-grid" data-tl-grid></div>
                        </div>
                        <div class="tl-legend" aria-label="Timeline legend"><span><i></i>Update submitted</span><span><i
                                    class="missed"></i>No update</span><span><i class="upcoming"></i>Upcoming</span><span><i
                                    class="milestone"></i>Milestone</span><span><i
                                    class="dependency"></i>Dependency</span><span><i class="today"></i>Today</span></div>
                    </section>
                </div>

                <div class="tf-activity-tab-panel" id="panel-milestones" role="tabpanel" aria-labelledby="tab-milestones-btn"
                    hidden>
                    <section class="tf-task-detail__card mb-3">
                        <header class="tf-task-detail__card-header">
                            <h2><i class="bi bi-flag" aria-hidden="true"></i> Milestones</h2>
                            <span class="tf-task-detail__count">{{ $milestones->count() }}</span>
                        </header>
                        <div class="card-body">
                            @can('createMilestone', $task)
                                <details class="mb-4">
                                    <summary class="btn btn-primary btn-sm">Add milestone</summary>
                                    <form method="POST" action="{{ route('milestones.store', $task) }}" class="row g-3 mt-2">
                                        @csrf
                                        <div class="col-12">
                                            <label class="form-label" for="milestone-title">Title</label>
                                            <input class="form-control" id="milestone-title" name="title" required maxlength="255">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="milestone-target-date">Target date</label>
                                            <input class="form-control" type="date" id="milestone-target-date" name="target_date" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="milestone-expected-outcome">Expected outcome</label>
                                            <input class="form-control" id="milestone-expected-outcome" name="expected_outcome" maxlength="1000">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="milestone-description">Description</label>
                                            <textarea class="form-control" id="milestone-description" name="description" rows="2"></textarea>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="milestone-assignees">Responsible employees</label>
                                            <select class="form-select" id="milestone-assignees" name="assignee_ids[]" multiple>
                                                @foreach ($employees as $employee)
                                                    <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm" type="submit">Create milestone</button>
                                        </div>
                                    </form>
                                </details>
                            @endcan

                            @forelse ($milestones as $milestone)
                                <article class="border rounded p-3 mb-3">
                                    <header class="d-flex flex-wrap justify-content-between gap-2">
                                        <div>
                                            <h3 class="h5 mb-1">{{ $milestone->title }}</h3>
                                            <p class="mb-1">{{ $milestone->description ?: 'No description provided.' }}</p>
                                            @if ($milestone->expected_outcome)
                                                <p class="small mb-1"><strong>Expected outcome:</strong> {{ $milestone->expected_outcome }}</p>
                                            @endif
                                            <small class="text-secondary">
                                                Target: {{ $milestone->target_date?->format('M j, Y') ?? 'Not set' }}
                                                · {{ $milestone->assignees->pluck('name')->join(', ') ?: 'No assignees' }}
                                            </small>
                                        </div>
                                        <span class="badge text-bg-secondary">
                                            {{ \App\Models\Milestone::STATUS_LABELS[$milestone->status] ?? ucfirst($milestone->status) }}
                                            · {{ $milestone->progress_percentage }}%
                                        </span>
                                    </header>

                                    @can('updateMilestone', [$task, $milestone])
                                        <form method="POST" action="{{ route('milestones.update', [$task, $milestone]) }}" class="row g-2 mt-2">
                                            @csrf
                                            @method('PATCH')
                                            <div class="col-md-4">
                                                <label class="form-label small" for="milestone-status-{{ $milestone->id }}">Status</label>
                                                <select class="form-select form-select-sm" id="milestone-status-{{ $milestone->id }}" name="status">
                                                    @foreach (array_unique(array_merge([$milestone->status], \App\Models\Milestone::STATUS_TRANSITIONS[$milestone->status] ?? [])) as $statusValue)
                                                        <option value="{{ $statusValue }}" @selected($milestone->status === $statusValue)>
                                                            {{ \App\Models\Milestone::STATUS_LABELS[$statusValue] ?? ucfirst($statusValue) }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small" for="milestone-progress-{{ $milestone->id }}">Progress (%)</label>
                                                <input class="form-control form-control-sm" type="number" min="0" max="100"
                                                    id="milestone-progress-{{ $milestone->id }}" name="progress_percentage"
                                                    value="{{ $milestone->progress_percentage }}">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small" for="milestone-date-{{ $milestone->id }}">Target date</label>
                                                <input class="form-control form-control-sm" type="date" id="milestone-date-{{ $milestone->id }}"
                                                    name="target_date" value="{{ $milestone->target_date?->format('Y-m-d') }}">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small" for="milestone-assignees-{{ $milestone->id }}">Responsible employees</label>
                                                <select class="form-select form-select-sm" id="milestone-assignees-{{ $milestone->id }}"
                                                    name="assignee_ids[]" multiple>
                                                    @foreach ($employees as $employee)
                                                        <option value="{{ $employee->id }}"
                                                            @selected($milestone->assignees->contains('id', $employee->id))>{{ $employee->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <button class="btn btn-outline-primary btn-sm" type="submit">Save milestone</button>
                                            </div>
                                        </form>
                                    @endcan

                                    <details class="mt-3">
                                        <summary>Milestone activity ({{ $milestone->activity->count() }})</summary>
                                        @forelse ($milestone->activity->sortBy('created_at') as $milestoneActivity)
                                            <div class="border-start border-2 ps-3 py-1 ms-1">
                                                <strong>{{ str($milestoneActivity->event_type)->replace('_', ' ')->title() }}</strong>
                                                <div>{{ $milestoneActivity->remarks ?: $milestoneActivity->new_status }}</div>
                                                <small class="text-secondary">
                                                    {{ $milestoneActivity->changedBy?->name ?? 'System' }} ·
                                                    {{ $milestoneActivity->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                                                </small>
                                            </div>
                                        @empty
                                            <p class="text-secondary mt-2 mb-0">No milestone activity has been recorded.</p>
                                        @endforelse
                                    </details>
                                </article>
                            @empty
                                <p class="text-secondary mb-0">No milestones have been created for this task.</p>
                            @endforelse
                        </div>
                    </section>
                </div>

                <div class="tf-activity-tab-panel" id="panel-daily-tasks" role="tabpanel" aria-labelledby="tab-daily-tasks-btn"
                    hidden>
                    <div class="daily-task-panel-actions">
                        @if (auth()->user()->hasAssignedRole('Employee') && auth()->user()->can('createDailyTask', $task))
                            <div class="position-relative">
                                <button class="btn btn-primary btn-sm" type="button" id="daily-task-create-toggle"
                                    aria-expanded="{{ old('quick_entry') ? 'true' : 'false' }}"
                                    aria-controls="daily-task-create-dialog">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Add Daily Task
                                </button>
                                <div class="daily-task-create-dialog" id="daily-task-create-dialog" role="dialog"
                                    aria-labelledby="daily-task-create-title"
                                    @if (!old('quick_entry')) hidden @else data-show-daily-task-create @endif>
                                    <form method="POST" action="{{ route('daily-tasks.store', $task) }}">
                                        @csrf
                                        <input type="hidden" name="quick_entry" value="1">
                                        <input type="hidden" name="priority" value="medium">
                                        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                            <div>
                                                <h3 class="h6 mb-1" id="daily-task-create-title">Add Daily Task</h3>
                                                <p class="small text-body-secondary mb-0">For {{ auth()->user()->name }}</p>
                                            </div>
                                            <button class="btn-close" type="button" data-close-daily-task-create
                                                aria-label="Close"></button>
                                        </div>
                                        @if ($errors->any() && old('quick_entry'))
                                            <div class="alert alert-danger py-2" role="alert">
                                                <ul class="mb-0 ps-3">
                                                    @foreach ($errors->all() as $error)
                                                        <li>{{ $error }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                        <div class="mb-3">
                                            <label class="form-label" for="quickDailyTaskDate">Date</label>
                                            <input class="form-control" type="date" id="quickDailyTaskDate"
                                                name="work_date" value="{{ old('work_date', today()->toDateString()) }}" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="quickDailyTaskTitle">Task</label>
                                            <input class="form-control" type="text" id="quickDailyTaskTitle"
                                                name="title" value="{{ old('title') }}" maxlength="500" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="quickDailyTaskEmployee">Employee Name</label>
                                            <input class="form-control" type="text" id="quickDailyTaskEmployee"
                                                value="{{ auth()->user()->name }}" readonly>
                                        </div>
                                        <div class="d-flex justify-content-end gap-2">
                                            <button class="btn btn-outline-secondary btn-sm" type="button"
                                                data-close-daily-task-create>Cancel</button>
                                            <button class="btn btn-primary btn-sm" type="submit">Add Task</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @endif
                    </div>
                    <section class="tf-task-detail__card mb-3">
                        <header class="tf-task-detail__card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <h2><i class="bi bi-calendar2-week" aria-hidden="true"></i> Daily Updates</h2>
                            <span class="tf-task-detail__count">{{ $dailyTasks->count() }}</span>
                        </header>
                        @if (session('success'))
                            <div class="alert alert-success mx-3 mt-3 mb-0" role="status">{{ session('success') }}</div>
                        @endif
                        @if (session('overlap_warning'))
                            <div class="alert alert-warning mx-3 mt-3 mb-0" role="alert">
                                This Daily Task overlaps another scheduled task for the employee.
                            </div>
                        @endif
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="daily-updates-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Date</th>
                                        <th scope="col">Employee Name</th>
                                        <th scope="col">Department</th>
                                        <th scope="col">Task</th>
                                        <th scope="col">Daily Task Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($dailyTasks->sortByDesc('work_date') as $dailyTask)
                                        <tr id="daily-task-{{ $dailyTask->id }}">
                                            <td>{{ $dailyTask->work_date?->format('M j, Y') ?? 'Date unavailable' }}</td>
                                            <td>{{ $dailyTask->assignee?->name ?? 'Former employee' }}</td>
                                            <td>{{ $dailyTask->assignee?->department?->name ?? '—' }}</td>
                                            <td>
                                                <span class="d-block">
                                                    {{ $dailyTask->title }}
                                                </span>
                                                @if (filled($dailyTask->description))
                                                    <small class="d-block text-body-secondary">
                                                        {{ \Illuminate\Support\Str::limit($dailyTask->description, 100) }}
                                                    </small>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="tf-daily-update-actions d-flex flex-wrap align-items-center gap-2">
                                                    @php
                                                        $dailyTaskStatusTone = match ($dailyTask->status) {
                                                            'completed' => 'text-bg-success',
                                                            'in_progress' => 'text-bg-primary',
                                                            'on_hold' => 'text-bg-warning',
                                                            'cancelled', 'missed' => 'text-bg-danger',
                                                            default => 'text-bg-secondary',
                                                        };
                                                    @endphp
                                                    <span class="badge {{ $dailyTaskStatusTone }}">
                                                        {{ \App\Models\DailyTask::STATUS_LABELS[$dailyTask->status] ?? ucfirst($dailyTask->status) }}
                                                    </span>
                                                    @can('updateDailyTask', [$task, $dailyTask])
                                                        @php
                                                            $statusActions = match ($dailyTask->status) {
                                                                'planned', 'pending', 'missed' => ['start' => 'Start', 'cancel' => 'Cancel'],
                                                                'in_progress' => ['hold' => 'On Hold', 'complete' => 'Complete', 'cancel' => 'Cancel'],
                                                                'on_hold' => ['resume' => 'Unhold', 'complete' => 'Complete', 'cancel' => 'Cancel'],
                                                                default => [],
                                                            };
                                                        @endphp
                                                        @foreach ($statusActions as $statusAction => $statusActionLabel)
                                                            @php
                                                                $statusActionTone = match ($statusAction) {
                                                                    'hold' => 'warning',
                                                                    'complete' => 'success',
                                                                    'cancel' => 'danger',
                                                                    default => 'primary',
                                                                };
                                                                $statusActionIcon = match ($statusAction) {
                                                                    'start' => 'bi-play-fill',
                                                                    'resume' => 'bi-play-circle',
                                                                    'hold' => 'bi-pause-fill',
                                                                    'complete' => 'bi-check-lg',
                                                                    'cancel' => 'bi-x-lg',
                                                                    default => 'bi-arrow-repeat',
                                                                };
                                                            @endphp
                                                            <form class="tf-daily-update-action-form" method="POST"
                                                                action="{{ route('daily-tasks.action', [$task, $dailyTask, $statusAction]) }}">
                                                                @csrf
                                                                <button class="tf-btn tf-btn--sm tf-daily-update-action tf-daily-update-action--{{ $statusActionTone }}"
                                                                    type="submit">
                                                                    <i class="bi {{ $statusActionIcon }}" aria-hidden="true"></i>
                                                                    {{ $statusActionLabel }}
                                                                </button>
                                                            </form>
                                                        @endforeach
                                                        @if (in_array($dailyTask->status, ['in_progress', 'on_hold'], true))
                                                            <details class="tf-daily-update-composer w-100"
                                                                @if ($errors->has('remarks') && (int) old('daily_task_id') === $dailyTask->id) open @endif>
                                                                <summary class="tf-btn tf-btn--sm tf-daily-update-action tf-daily-update-action--primary">
                                                                    <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                                                                    Submit Daily Update
                                                                </summary>
                                                                <form id="daily-task-update-form-{{ $dailyTask->id }}"
                                                                    class="tf-daily-update-composer__form mt-2"
                                                                    method="POST"
                                                                    action="{{ route('daily-tasks.action', [$task, $dailyTask, 'update']) }}">
                                                                    @csrf
                                                                    <input type="hidden" name="daily_task_id" value="{{ $dailyTask->id }}">
                                                                    <label class="form-label mb-0"
                                                                        for="daily-task-update-remarks-{{ $dailyTask->id }}">Update description</label>
                                                                    <textarea class="form-control" id="daily-task-update-remarks-{{ $dailyTask->id }}"
                                                                        name="remarks" rows="3" maxlength="5000" required>{{ old('remarks') }}</textarea>
                                                                    <label class="form-label mb-0"
                                                                        for="daily-task-update-blockers-{{ $dailyTask->id }}">Blockers (optional)</label>
                                                                    <textarea class="form-control" id="daily-task-update-blockers-{{ $dailyTask->id }}"
                                                                        name="blockers" rows="2" maxlength="5000">{{ old('blockers') }}</textarea>
                                                                    @if ($errors->has('remarks') && (int) old('daily_task_id') === $dailyTask->id)
                                                                        <div class="small text-danger" role="alert">{{ $errors->first('remarks') }}</div>
                                                                    @endif
                                                                    <button class="tf-btn tf-btn--sm tf-daily-update-action tf-daily-update-action--primary"
                                                                        type="submit">
                                                                        <i class="bi bi-send" aria-hidden="true"></i>
                                                                        Save update
                                                                    </button>
                                                                </form>
                                                            </details>
                                                        @endif
                                                    @endcan
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td class="text-center text-secondary py-4" colspan="5">No daily updates have been recorded.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="tf-activity-tab-panel" id="panel-requests" role="tabpanel" aria-labelledby="tab-requests-btn"
                    hidden>
                    <section class="tf-task-detail__card tf-deadline-requests">
                        <header class="tf-deadline-requests__header">
                            <span class="tf-deadline-requests__icon" aria-hidden="true">
                                <i class="bi bi-calendar2-plus"></i>
                            </span>
                            <div class="tf-deadline-requests__heading">
                                <h2>Deadline extension requests</h2>
                                <p>Review requested changes to this task’s deadline.</p>
                            </div>
                            <span class="tf-deadline-requests__count">
                                {{ $deadlineExtensionRequests->count() }}
                                {{ $deadlineExtensionRequests->count() === 1 ? 'request' : 'requests' }}
                            </span>
                        </header>
                        @if ($deadlineExtensionRequests->isNotEmpty())
                            <div class="tf-deadline-requests__table-wrap">
                                <table class="tf-deadline-requests__table">
                                    <thead>
                                        <tr>
                                            <th>Task</th>
                                            <th>Current deadline</th>
                                            <th>Requested deadline</th>
                                            <th>Reason</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($deadlineExtensionRequests as $extensionRequest)
                                            <tr>
                                                <td class="tf-deadline-requests__task">
                                                    <strong>{{ $extensionRequest->task_title }}</strong>
                                                    <small>
                                                        <i class="bi bi-person" aria-hidden="true"></i>
                                                        {{ $extensionRequest->requester?->name ?? 'Former user' }}
                                                    </small>
                                                </td>
                                                <td class="tf-deadline-requests__date">
                                                    {{ $extensionRequest->current_deadline?->format('M d, Y') ?? 'No deadline' }}
                                                </td>
                                                <td class="tf-deadline-requests__date tf-deadline-requests__date--requested">
                                                    {{ $extensionRequest->requested_deadline->format('M d, Y') }}
                                                </td>
                                                <td class="tf-deadline-requests__reason">{{ $extensionRequest->reason }}</td>
                                                <td class="tf-deadline-requests__status-cell">
                                                    @php
                                                        $requestStatusClass = match ($extensionRequest->status) {
                                                            'approved' => 'tf-deadline-requests__status--approved',
                                                            'rejected' => 'tf-deadline-requests__status--rejected',
                                                            default => 'tf-deadline-requests__status--pending',
                                                        };
                                                    @endphp
                                                    <span class="tf-deadline-requests__status {{ $requestStatusClass }}">
                                                        <span aria-hidden="true"></span>
                                                        {{ ucfirst($extensionRequest->status) }}
                                                    </span>
                                                    @if ($extensionRequest->review_note)
                                                        <small class="tf-deadline-requests__review-note"
                                                            title="{{ $extensionRequest->review_note }}">
                                                            {{ $extensionRequest->review_note }}
                                                        </small>
                                                    @endif
                                                </td>
                                                <td class="tf-deadline-requests__actions">
                                                    @can('review', $extensionRequest)
                                                        <form method="POST"
                                                            action="{{ route('tasks.deadline-extension-requests.review', [$task, $extensionRequest]) }}"
                                                            class="tf-deadline-requests__review-form">
                                                            @csrf
                                                            <div class="tf-deadline-requests__review-actions">
                                                                <button type="submit" name="decision" value="approve"
                                                                    class="tf-deadline-requests__button tf-deadline-requests__button--approve">
                                                                    <i class="bi bi-check2" aria-hidden="true"></i> Approve
                                                                </button>
                                                                <button type="submit" name="decision" value="reject"
                                                                    class="tf-deadline-requests__button tf-deadline-requests__button--reject">
                                                                    <i class="bi bi-x-lg" aria-hidden="true"></i> Reject
                                                                </button>
                                                            </div>
                                                        </form>
                                                    @else
                                                        <span class="tf-deadline-requests__no-action">No action needed</span>
                                                    @endcan
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="tf-deadline-requests__empty">
                                <span aria-hidden="true"><i class="bi bi-calendar2-check"></i></span>
                                <strong>No extension requests yet</strong>
                                <p>Submitted deadline extension requests will appear here.</p>
                            </div>
                        @endif
                    </section>
                </div>
            </aside>
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-task-detail-tab]').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('[data-task-detail-tab]').forEach(item => {
                    const isActive = item === tab;
                    item.classList.toggle('is-active', isActive);
                    item.setAttribute('aria-selected', String(isActive));
                });
                document.querySelectorAll('[data-task-detail-panel]').forEach(panel => {
                    panel.hidden = panel.dataset.taskDetailPanel !== tab.dataset.taskDetailTab;
                });
            });
        });

        let activeWorkflowPanel = null;
        let activeWorkflowTrigger = null;
        let activeWorkflowPlaceholder = null;

        const positionWorkflowPanel = () => {
            if (!activeWorkflowPanel || !activeWorkflowTrigger) {
                return;
            }

            const triggerRect = activeWorkflowTrigger.getBoundingClientRect();
            const panelRect = activeWorkflowPanel.getBoundingClientRect();
            const viewportPadding = 12;
            const gap = 8;
            const left = Math.max(
                viewportPadding,
                Math.min(triggerRect.left, window.innerWidth - panelRect.width - viewportPadding),
            );
            const belowTop = triggerRect.bottom + gap;
            const aboveTop = triggerRect.top - panelRect.height - gap;
            const top = belowTop + panelRect.height <= window.innerHeight - viewportPadding
                ? belowTop
                : aboveTop >= viewportPadding
                    ? aboveTop
                    : Math.max(viewportPadding, window.innerHeight - panelRect.height - viewportPadding);

            activeWorkflowPanel.style.left = `${left}px`;
            activeWorkflowPanel.style.top = `${top}px`;
        };

        const closeWorkflowPanel = (panel, restoreFocus = false) => {
            if (!panel) {
                return;
            }

            panel.hidden = true;
            panel.classList.remove('tf-wf-panel--popover');
            panel.style.removeProperty('left');
            panel.style.removeProperty('top');

            if (activeWorkflowPlaceholder?.parentNode && panel === activeWorkflowPanel) {
                activeWorkflowPlaceholder.parentNode.replaceChild(panel, activeWorkflowPlaceholder);
            }

            document.querySelectorAll('[data-wf-toggle]').forEach(toggle => {
                if (toggle.dataset.wfToggle === panel.id) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });

            if (restoreFocus) {
                activeWorkflowTrigger?.focus();
            }

            if (panel === activeWorkflowPanel) {
                activeWorkflowPanel = null;
                activeWorkflowTrigger = null;
                activeWorkflowPlaceholder = null;
            }
        };

        document.querySelectorAll('[data-wf-toggle]').forEach(btn => {
            btn.setAttribute('aria-controls', btn.dataset.wfToggle);
            btn.setAttribute('aria-expanded', 'false');

            btn.addEventListener('click', () => {
                const targetPanel = document.getElementById(btn.dataset.wfToggle);
                if (!targetPanel) {
                    return;
                }

                if (targetPanel === activeWorkflowPanel && !targetPanel.hidden) {
                    closeWorkflowPanel(targetPanel);
                    return;
                }

                if (activeWorkflowPanel) {
                    closeWorkflowPanel(activeWorkflowPanel);
                }

                activeWorkflowPanel = targetPanel;
                activeWorkflowTrigger = btn;
                activeWorkflowPlaceholder = document.createComment('workflow-panel');
                targetPanel.parentNode.insertBefore(activeWorkflowPlaceholder, targetPanel);
                targetPanel.hidden = false;
                targetPanel.classList.add('tf-wf-panel--popover');
                targetPanel.setAttribute('role', 'dialog');
                targetPanel.setAttribute('aria-modal', 'false');
                targetPanel.setAttribute('aria-label', btn.textContent.trim());
                document.body.append(targetPanel);
                btn.setAttribute('aria-expanded', 'true');
                positionWorkflowPanel();
                targetPanel.querySelector('textarea, input:not([type=hidden])')?.focus();
            });
        });

        document.querySelectorAll('[data-wf-close]').forEach(btn => {
            btn.addEventListener('click', () => {
                closeWorkflowPanel(document.getElementById(btn.dataset.wfClose), true);
            });
        });

        document.addEventListener('pointerdown', event => {
            if (!activeWorkflowPanel
                || activeWorkflowPanel.contains(event.target)
                || activeWorkflowTrigger?.contains(event.target)) {
                return;
            }

            closeWorkflowPanel(activeWorkflowPanel);
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && activeWorkflowPanel) {
                closeWorkflowPanel(activeWorkflowPanel, true);
            }
        });

        window.addEventListener('resize', positionWorkflowPanel);
        window.addEventListener('scroll', positionWorkflowPanel, true);
    </script>

    <div class="tf-subtask-actions-menu" id="subtaskActionsMenu" role="menu" aria-label="Subtask actions" hidden>
        <p class="tf-subtask-actions-menu__alert" id="subtaskActionsMenuAlert" role="alert" hidden></p>
        <button type="button" class="tf-subtask-actions-menu__item" role="menuitem" data-subtask-action="edit">
            <i class="bi bi-pencil" aria-hidden="true"></i>
            Edit subtask
        </button>
        <div class="tf-subtask-actions-menu__divider" role="separator"></div>
        <button type="button" class="tf-subtask-actions-menu__item" role="menuitem" data-subtask-status="completed">
            <i class="bi bi-check-circle text-success" aria-hidden="true"></i>
            Mark as complete
        </button>
        <button type="button" class="tf-subtask-actions-menu__item" role="menuitem" data-subtask-status="in_progress">
            <i class="bi bi-play-circle text-primary" aria-hidden="true"></i>
            In progress
        </button>
        <button type="button" class="tf-subtask-actions-menu__item" role="menuitem" data-subtask-status="on_hold">
            <i class="bi bi-pause-circle text-warning" aria-hidden="true"></i>
            On hold
        </button>
        <div class="tf-subtask-actions-menu__divider" role="separator"></div>
        <button type="button" class="tf-subtask-actions-menu__item tf-subtask-actions-menu__item--danger" role="menuitem"
            data-subtask-action="delete">
            <i class="bi bi-trash3" aria-hidden="true"></i>
            Delete subtask
        </button>
    </div>
    @include('tasks._subtask-modal')

    {{-- Subtask interactions --}}
    <script>
        (function () {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

            // ── Overview and Activity Tabs ──────────────────────────────────────
            document.querySelectorAll('[data-activity-tab]').forEach(tab => {
                tab.addEventListener('click', () => {
                    const target = tab.dataset.activityTab;
                    const taskDetailGrid = tab.closest('.tf-task-detail__grid');
                    taskDetailGrid?.setAttribute('data-activity-view', target);
                    const taskDetails = taskDetailGrid?.querySelector('.tf-task-detail__main');
                    if (taskDetails) {
                        taskDetails.hidden = target !== 'overview';
                    }

                    document.querySelectorAll('[data-activity-tab]').forEach(btn => {
                        const isActive = btn === tab;
                        btn.classList.toggle('is-active', isActive);
                        btn.setAttribute('aria-selected', String(isActive));
                    });
                    document.querySelectorAll('.tf-activity-tab-panel').forEach(panel => {
                        const isMatch = panel.id === `panel-${target}`;
                        panel.hidden = !isMatch;
                        panel.classList.toggle('is-active', isMatch);
                    });
                });
            });
            const dailyTaskHash = window.location.hash;
            const dailyTaskHashMatch = dailyTaskHash.match(/^#daily-task-(\d+)$/);
            if (dailyTaskHash === '#panel-daily-tasks' || dailyTaskHashMatch) {
                document.getElementById('tab-daily-tasks-btn')?.click();
            }
            if (dailyTaskHashMatch) {
                setTimeout(() => {
                    document.getElementById(`daily-task-${dailyTaskHashMatch[1]}`)?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                    });
                }, 300);
            }
            const dailyTaskCreateToggle = document.getElementById('daily-task-create-toggle');
            const dailyTaskCreateDialog = document.getElementById('daily-task-create-dialog');
            if (dailyTaskCreateDialog) {
                document.body.append(dailyTaskCreateDialog);
            }
            const positionDailyTaskCreateDialog = () => {
                if (!dailyTaskCreateToggle || !dailyTaskCreateDialog || dailyTaskCreateDialog.hidden) {
                    return;
                }

                const triggerRect = dailyTaskCreateToggle.getBoundingClientRect();
                const dialogRect = dailyTaskCreateDialog.getBoundingClientRect();
                const viewportPadding = 12;
                const left = Math.max(
                    viewportPadding,
                    Math.min(triggerRect.right - dialogRect.width, window.innerWidth - dialogRect.width - viewportPadding),
                );
                const belowTop = triggerRect.bottom + 8;
                const aboveTop = triggerRect.top - dialogRect.height - 8;
                const top = belowTop + dialogRect.height <= window.innerHeight - viewportPadding
                    ? belowTop
                    : Math.max(viewportPadding, aboveTop);

                dailyTaskCreateDialog.style.left = `${left}px`;
                dailyTaskCreateDialog.style.top = `${top}px`;
            };
            const closeDailyTaskCreateDialog = () => {
                if (!dailyTaskCreateDialog || !dailyTaskCreateToggle) {
                    return;
                }

                dailyTaskCreateDialog.hidden = true;
                dailyTaskCreateToggle.setAttribute('aria-expanded', 'false');
                dailyTaskCreateDialog.style.removeProperty('left');
                dailyTaskCreateDialog.style.removeProperty('top');
            };
            const openDailyTaskCreateDialog = () => {
                if (!dailyTaskCreateDialog || !dailyTaskCreateToggle) {
                    return;
                }

                dailyTaskCreateDialog.hidden = false;
                dailyTaskCreateToggle.setAttribute('aria-expanded', 'true');
                positionDailyTaskCreateDialog();
                dailyTaskCreateDialog.querySelector('#quickDailyTaskTitle')?.focus();
            };

            dailyTaskCreateToggle?.addEventListener('click', () => {
                if (dailyTaskCreateDialog?.hidden) {
                    openDailyTaskCreateDialog();
                } else {
                    closeDailyTaskCreateDialog();
                }
            });
            dailyTaskCreateDialog?.querySelectorAll('[data-close-daily-task-create]').forEach(button => {
                button.addEventListener('click', closeDailyTaskCreateDialog);
            });
            document.addEventListener('click', event => {
                if (
                    dailyTaskCreateDialog
                    && dailyTaskCreateToggle
                    && !dailyTaskCreateDialog.hidden
                    && !dailyTaskCreateDialog.contains(event.target)
                    && !dailyTaskCreateToggle.contains(event.target)
                ) {
                    closeDailyTaskCreateDialog();
                }
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && dailyTaskCreateDialog && !dailyTaskCreateDialog.hidden) {
                    closeDailyTaskCreateDialog();
                    dailyTaskCreateToggle?.focus();
                }
            });
            window.addEventListener('resize', positionDailyTaskCreateDialog);
            window.addEventListener('scroll', positionDailyTaskCreateDialog, true);
            if (
                dailyTaskCreateDialog?.hasAttribute('data-show-daily-task-create')
                || new URLSearchParams(window.location.search).get('open_daily_task') === '1'
            ) {
                document.getElementById('tab-daily-tasks-btn')?.click();
                openDailyTaskCreateDialog();
            }

            // ── Quick Add Subtask Panel ───────────────────────────────────────────
            const addSubtaskBtn = document.getElementById('subtask-add-btn');
            const quickAddPanel = document.getElementById('subtask-quick-add-panel');
            const quickAddForm = document.getElementById('subtask-quick-add-form');
            const quickAddAlert = document.getElementById('quick-add-alert');
            const quickAddSubmit = document.getElementById('quick-add-submit');
            const quickAddCancelButtons = [
                document.getElementById('quick-add-cancel-btn'),
                document.getElementById('quick-add-cancel-footer'),
            ];

            if (quickAddPanel) {
                document.body.append(quickAddPanel);
            }

            function positionQuickAddPanel() {
                if (!quickAddPanel || !addSubtaskBtn) { return; }
                const buttonRect = addSubtaskBtn.getBoundingClientRect();
                const panelRect = quickAddPanel.getBoundingClientRect();
                const padding = 12;
                const left = Math.max(
                    padding,
                    Math.min(buttonRect.left, window.innerWidth - panelRect.width - padding),
                );
                const top = buttonRect.bottom + panelRect.height + padding <= window.innerHeight
                    ? buttonRect.bottom + 8
                    : Math.max(padding, buttonRect.top - panelRect.height - 8);

                quickAddPanel.style.left = `${left}px`;
                quickAddPanel.style.top = `${top}px`;
            }

            function closeQuickAddPanel(restoreFocus = false, resetForm = false) {
                if (!quickAddPanel) { return; }
                quickAddPanel.hidden = true;
                addSubtaskBtn?.setAttribute('aria-expanded', 'false');
                quickAddAlert.hidden = true;
                quickAddAlert.textContent = '';
                if (resetForm) {
                    quickAddForm?.reset();
                }
                if (restoreFocus) {
                    addSubtaskBtn?.focus();
                }
            }

            addSubtaskBtn?.addEventListener('click', () => {
                if (!quickAddPanel) { return; }
                quickAddPanel.hidden = !quickAddPanel.hidden;
                const isOpen = !quickAddPanel.hidden;
                addSubtaskBtn.setAttribute('aria-expanded', String(isOpen));
                if (isOpen) {
                    quickAddAlert.hidden = true;
                    positionQuickAddPanel();
                    document.getElementById('quick-add-title')?.focus();
                }
            });

            quickAddCancelButtons.forEach(button => {
                button?.addEventListener('click', () => closeQuickAddPanel(true, true));
            });

            document.addEventListener('pointerdown', event => {
                if (!quickAddPanel || quickAddPanel.hidden) { return; }
                if (quickAddPanel.contains(event.target) || addSubtaskBtn?.contains(event.target)) { return; }
                closeQuickAddPanel();
            });

            quickAddPanel?.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    closeQuickAddPanel(true);
                }
            });

            window.addEventListener('resize', () => closeQuickAddPanel());
            window.addEventListener('scroll', () => closeQuickAddPanel(), true);

            quickAddForm?.addEventListener('submit', async function (event) {
                event.preventDefault();
                quickAddSubmit.disabled = true;
                quickAddAlert.hidden = true;

                try {
                    const response = await fetch(quickAddForm.action, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        },
                        body: new FormData(quickAddForm),
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok) {
                        const firstValidationError = Object.values(data.errors || {}).flat()[0];
                        throw new Error(firstValidationError || data.message || 'Failed to add subtask.');
                    }

                    window.location.reload();
                } catch (error) {
                    quickAddAlert.textContent = error.message || 'Failed to add subtask.';
                    quickAddAlert.hidden = false;
                } finally {
                    quickAddSubmit.disabled = false;
                }
            });

            // ── Helpers ──────────────────────────────────────────────────────────
            function updateSubtaskBadge() {
                const list = document.getElementById('subtasks-list');
                if (!list) { return; }
                const items = list.querySelectorAll('.tf-subtasks__item');
                const done = list.querySelectorAll('.tf-subtasks__item.is-done');
                const total = items.length;
                const doneCount = done.length;
                const pct = total > 0 ? Math.round(doneCount / total * 100) : 0;

                const subtitle = document.getElementById('subtasks-count-subtitle');
                const tabBadge = document.getElementById('tab-subtasks-count-badge');
                const fill = document.getElementById('subtasks-progress-fill');
                const pctText = document.getElementById('subtasks-progress-pct');

                if (subtitle) { subtitle.textContent = `${total} total (${doneCount} completed)`; }
                if (tabBadge) { tabBadge.textContent = total; }
                if (fill) { fill.style.width = `${pct}%`; }
                if (pctText) { pctText.textContent = `${pct}%`; }
            }

            const statusMenuEl = document.getElementById('subtaskActionsMenu');
            const statusMenuAlert = document.getElementById('subtaskActionsMenuAlert');
            let activeStatusRow = null;
            let activeStatusTrigger = null;

            if (statusMenuEl) {
                document.body.append(statusMenuEl);
            }

            function closeStatusMenu(restoreFocus = false) {
                if (!statusMenuEl) { return; }
                statusMenuEl.hidden = true;
                activeStatusTrigger?.setAttribute('aria-expanded', 'false');
                if (restoreFocus) {
                    activeStatusTrigger?.focus();
                }
                activeStatusRow = null;
                activeStatusTrigger = null;
            }

            function positionStatusMenu(trigger) {
                const triggerRect = trigger.getBoundingClientRect();
                const menuRect = statusMenuEl.getBoundingClientRect();
                const gap = 6;
                const viewportPadding = 8;
                const left = Math.max(
                    viewportPadding,
                    Math.min(triggerRect.right - menuRect.width, window.innerWidth - menuRect.width - viewportPadding),
                );
                const top = triggerRect.bottom + menuRect.height + viewportPadding <= window.innerHeight
                    ? triggerRect.bottom + gap
                    : Math.max(viewportPadding, triggerRect.top - menuRect.height - gap);

                statusMenuEl.style.left = `${left}px`;
                statusMenuEl.style.top = `${top}px`;
            }

            function openStatusMenu(row, trigger) {
                if (!statusMenuEl) { return; }
                closeStatusMenu();
                activeStatusRow = row;
                activeStatusTrigger = trigger;
                statusMenuAlert.textContent = '';
                statusMenuAlert.hidden = true;
                statusMenuEl.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                positionStatusMenu(trigger);
                statusMenuEl.querySelector('[role="menuitem"]')?.focus();
            }

            function updateSubtaskStatusRow(row, subtask) {
                const statusClasses = {
                    completed: 'is-completed',
                    in_progress: 'is-in-progress',
                    on_hold: 'is-on-hold',
                    in_review: 'is-in-review',
                };
                const statusClass = statusClasses[subtask.status] || 'is-pending';

                row.dataset.status = subtask.status;
                row.classList.toggle('is-done', subtask.is_completed);

                const statusPill = row.querySelector('.tf-st-status-pill');
                if (statusPill) {
                    statusPill.className = `tf-st-status-pill ${statusClass}`;
                    statusPill.textContent = subtask.status_label || 'Pending';
                }
            }

            document.getElementById('subtasks-list')?.addEventListener('click', function (event) {
                const trigger = event.target.closest('.tf-subtask-status-trigger');
                if (!trigger) { return; }
                openStatusMenu(trigger.closest('.tf-subtasks__item'), trigger);
            });

            statusMenuEl?.addEventListener('click', async function (event) {
                const editButton = event.target.closest('[data-subtask-action="edit"]');
                if (editButton && activeStatusRow) {
                    const row = activeStatusRow;
                    closeStatusMenu();
                    openSubtaskModal(row.dataset.subtaskUrl, row.dataset.updateUrl, row.dataset.deleteUrl);
                    return;
                }

                const statusButton = event.target.closest('[data-subtask-status]');
                if (!statusButton || !activeStatusRow) { return; }

                const statusButtons = [...statusMenuEl.querySelectorAll('[data-subtask-status]')];
                statusButtons.forEach(button => { button.disabled = true; });
                statusMenuAlert.hidden = true;

                try {
                    const response = await fetch(activeStatusRow.dataset.updateUrl, {
                        method: 'PATCH',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ status: statusButton.dataset.subtaskStatus }),
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok || !data.subtask) {
                        throw new Error(data.message || 'Unable to update the subtask status.');
                    }

                    updateSubtaskStatusRow(activeStatusRow, data.subtask);
                    updateSubtaskBadge();
                    closeStatusMenu();
                } catch (error) {
                    statusMenuAlert.textContent = error.message || 'Unable to update the subtask status.';
                    statusMenuAlert.hidden = false;
                } finally {
                    statusButtons.forEach(button => { button.disabled = false; });
                }
            });

            statusMenuEl?.addEventListener('click', async function (event) {
                const deleteButton = event.target.closest('[data-subtask-action="delete"]');
                if (!deleteButton || !activeStatusRow || !confirm('Delete this subtask?')) { return; }

                deleteButton.disabled = true;
                statusMenuAlert.hidden = true;
                try {
                    const response = await fetch(activeStatusRow.dataset.deleteUrl, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok) {
                        throw new Error(data.message || 'Unable to delete the subtask.');
                    }

                    activeStatusRow.remove();
                    updateSubtaskBadge();
                    closeStatusMenu();
                } catch (error) {
                    statusMenuAlert.textContent = error.message || 'Unable to delete the subtask.';
                    statusMenuAlert.hidden = false;
                } finally {
                    deleteButton.disabled = false;
                }
            });

            statusMenuEl?.addEventListener('keydown', function (event) {
                const items = [...statusMenuEl.querySelectorAll('[role="menuitem"]:not(:disabled)')];
                const currentIndex = items.indexOf(document.activeElement);
                let nextIndex = null;

                if (event.key === 'ArrowDown') {
                    nextIndex = (currentIndex + 1) % items.length;
                } else if (event.key === 'ArrowUp') {
                    nextIndex = (currentIndex - 1 + items.length) % items.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = items.length - 1;
                } else if (event.key === 'Escape') {
                    closeStatusMenu(true);
                    return;
                }

                if (nextIndex !== null && items.length > 0) {
                    event.preventDefault();
                    items[nextIndex].focus();
                }
            });

            document.addEventListener('pointerdown', function (event) {
                if (!statusMenuEl || statusMenuEl.hidden) { return; }
                if (statusMenuEl.contains(event.target) || activeStatusTrigger?.contains(event.target)) { return; }
                closeStatusMenu();
            });

            window.addEventListener('resize', () => closeStatusMenu());
            window.addEventListener('scroll', () => closeStatusMenu(), true);

            // ── Delete subtask ────────────────────────────────────────────────────
            document.getElementById('subtasks-list')?.addEventListener('click', function (e) {
                const btn = e.target.closest('.tf-subtasks__delete');
                if (!btn) { return; }
                if (!confirm('Delete this subtask?')) { return; }
                const url = btn.dataset.deleteUrl;
                fetch(url, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok) {
                            const row = btn.closest('.tf-subtasks__item');
                            row.remove();
                            updateSubtaskBadge();
                        }
                    })
                    .catch(() => { });
            });

            // ── Inline edit (double-click) ────────────────────────────────────────
            document.getElementById('subtasks-list')?.addEventListener('dblclick', function (e) {
                const titleEl = e.target.closest('.tf-subtasks__title');
                if (!titleEl || titleEl.contentEditable === 'true') { return; }
                titleEl.contentEditable = 'true';
                titleEl.focus();
                const range = document.createRange();
                range.selectNodeContents(titleEl);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(range);
            });

            document.getElementById('subtasks-list')?.addEventListener('keydown', function (e) {
                const titleEl = e.target.closest('.tf-subtasks__title');
                if (!titleEl || titleEl.contentEditable !== 'true') { return; }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    titleEl.blur();
                }
                if (e.key === 'Escape') {
                    titleEl.textContent = titleEl.dataset.original;
                    titleEl.contentEditable = 'false';
                }
            });

            document.getElementById('subtasks-list')?.addEventListener('blur', function (e) {
                const titleEl = e.target.closest?.('.tf-subtasks__title');
                if (!titleEl || titleEl.contentEditable !== 'true') { return; }
                titleEl.contentEditable = 'false';
                const newTitle = titleEl.textContent.trim();
                if (!newTitle || newTitle === titleEl.dataset.original) {
                    titleEl.textContent = titleEl.dataset.original;
                    return;
                }
                const url = titleEl.dataset.updateUrl;
                fetch(url, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ title: newTitle }),
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok) {
                            titleEl.dataset.original = newTitle;
                        } else {
                            titleEl.textContent = titleEl.dataset.original;
                        }
                    })
                    .catch(() => { titleEl.textContent = titleEl.dataset.original; });
            }, true);

            // ── Drag-to-reorder ───────────────────────────────────────────────────
            const list = document.getElementById('subtasks-list');
            if (list) {
                let dragSrc = null;

                list.addEventListener('dragstart', function (e) {
                    dragSrc = e.target.closest('.tf-subtasks__item');
                    if (!dragSrc) { return; }
                    e.dataTransfer.effectAllowed = 'move';
                    setTimeout(() => dragSrc?.classList.add('is-dragging'), 0);
                });

                list.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    const over = e.target.closest('.tf-subtasks__item');
                    if (!over || over === dragSrc) { return; }
                    const rect = over.getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;
                    if (e.clientY < midY) {
                        list.insertBefore(dragSrc, over);
                    } else {
                        list.insertBefore(dragSrc, over.nextSibling);
                    }
                });

                list.addEventListener('dragend', function () {
                    dragSrc?.classList.remove('is-dragging');
                    dragSrc = null;

                    const reorderUrl = list.dataset.reorderUrl;
                    const ids = [...list.querySelectorAll('.tf-subtasks__item')].map(li => parseInt(li.dataset.subtaskId, 10));
                    if (reorderUrl) {
                        fetch(reorderUrl, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ order: ids }),
                        }).catch(() => { });
                    }
                });
            }

            // ── Subtask Details Modal ──────────────────────────────────────────────
            const detailModalEl = document.getElementById('subtaskDetailsModal');
            const detailForm = document.getElementById('subtaskDetailsForm');
            const modalAlert = document.getElementById('subtaskModalAlert');
            const deleteModalBtn = document.getElementById('subtaskModalDeleteBtn');
            let currentDetailModal = null;
            let activeSubtaskUpdateUrl = '';
            let activeSubtaskDeleteUrl = '';
            let activeSubtaskId = null;

            function getModalInstance() {
                if (!detailModalEl) { return null; }
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    return bootstrap.Modal.getOrCreateInstance(detailModalEl);
                }
                return null;
            }

            function openSubtaskModal(subtaskUrl, updateUrl, deleteUrl) {
                if (!detailModalEl) { return; }
                activeSubtaskUpdateUrl = updateUrl || '';
                activeSubtaskDeleteUrl = deleteUrl || '';
                modalAlert?.classList.add('d-none');
                if (modalAlert) { modalAlert.textContent = ''; }

                fetch(subtaskUrl, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.ok || !data.subtask) { return; }
                        const st = data.subtask;
                        activeSubtaskId = st.id;

                        const idInput = document.getElementById('subtaskModalId');
                        const titleInput = document.getElementById('subtaskModalTitle');
                        const descInput = document.getElementById('subtaskModalDescription');
                        const creatorEl = document.getElementById('subtaskModalCreatorName');
                        const createdDateEl = document.getElementById('subtaskModalCreatedAt');
                        const assigneeSelect = document.getElementById('subtaskModalAssignee');
                        const statusSelect = document.getElementById('subtaskModalStatus');
                        const prioritySelect = document.getElementById('subtaskModalPriority');
                        const startDateInput = document.getElementById('subtaskModalStartDate');
                        const dueDateInput = document.getElementById('subtaskModalDueDate');
                        const stampEl = document.getElementById('subtaskModalCompletedStamp');
                        const completedByEl = document.getElementById('subtaskModalCompletedBy');
                        const completedAtEl = document.getElementById('subtaskModalCompletedAt');

                        if (idInput) { idInput.value = st.id; }
                        if (titleInput) { titleInput.value = st.title; }
                        if (descInput) { descInput.value = st.description || ''; }

                        // Creator attribution (separate from assignee)
                        if (creatorEl) {
                            const cRole = st.created_by.designation ? ` (${st.created_by.designation})` : '';
                            creatorEl.textContent = (st.created_by.name || 'Unknown') + cRole;
                        }
                        if (createdDateEl) {
                            createdDateEl.textContent = st.created_at_formatted ? `Created on ${st.created_at_formatted}` : '';
                        }

                        if (assigneeSelect) {
                            assigneeSelect.value = st.assigned_to.id || '';
                        }
                        if (statusSelect) {
                            statusSelect.value = st.status || (st.is_completed ? 'completed' : 'todo');
                        }
                        if (prioritySelect) {
                            prioritySelect.value = st.priority || 'medium';
                        }
                        if (startDateInput) {
                            startDateInput.value = st.start_date || '';
                        }
                        if (dueDateInput) {
                            dueDateInput.value = st.due_date || '';
                        }

                        // Completed stamp
                        if (st.is_completed && stampEl) {
                            stampEl.classList.remove('d-none');
                            if (completedByEl) { completedByEl.textContent = st.completed_by.name || 'User'; }
                            if (completedAtEl) { completedAtEl.textContent = st.completed_at_formatted || '—'; }
                        } else if (stampEl) {
                            stampEl.classList.add('d-none');
                        }

                        const bsModal = getModalInstance();
                        bsModal?.show();
                    })
                    .catch(() => { });
            }

            // Open modal on click
            document.getElementById('subtasks-list')?.addEventListener('click', function (e) {
                const btn = e.target.closest('.tf-subtasks__open-modal');
                if (btn) {
                    e.preventDefault();
                    const subtaskUrl = btn.dataset.subtaskUrl;
                    const updateUrl = btn.dataset.updateUrl;
                    const row = btn.closest('.tf-subtasks__item');
                    const deleteUrl = row?.dataset.deleteUrl;
                    openSubtaskModal(subtaskUrl, updateUrl, deleteUrl);
                }
            });

            // Save from Details Modal
            detailForm?.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!activeSubtaskUpdateUrl) { return; }

                const title = document.getElementById('subtaskModalTitle')?.value.trim();
                const description = document.getElementById('subtaskModalDescription')?.value.trim();
                const assigned_to = document.getElementById('subtaskModalAssignee')?.value || null;
                const status = document.getElementById('subtaskModalStatus')?.value;
                const priority = document.getElementById('subtaskModalPriority')?.value;
                const start_date = document.getElementById('subtaskModalStartDate')?.value || null;
                const due_date = document.getElementById('subtaskModalDueDate')?.value || null;

                modalAlert?.classList.add('d-none');

                fetch(activeSubtaskUpdateUrl, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        title,
                        description,
                        assigned_to,
                        status,
                        priority,
                        start_date,
                        due_date,
                    }),
                })
                    .then(async r => {
                        const res = await r.json();
                        if (!r.ok) {
                            throw new Error(res.errors?.due_date?.[0] || res.errors?.start_date?.[0] || res.message || 'Update failed');
                        }
                        return res;
                    })
                    .then(data => {
                        if (data.ok && data.subtask) {
                            const st = data.subtask;
                            const li = document.getElementById(`subtask-${st.id}`);
                            if (li) {
                                li.classList.toggle('is-done', st.is_completed);
                                const titleEl = li.querySelector('.tf-subtasks__title');
                                if (titleEl) {
                                    titleEl.textContent = st.title;
                                    titleEl.dataset.original = st.title;
                                }
                                const assigneeEl = li.querySelector('.tf-subtasks__meta--assignee');
                                if (assigneeEl && st.assigned_to?.name) {
                                    assigneeEl.innerHTML = `<i class="bi bi-person"></i> ${st.assigned_to.name}`;
                                    assigneeEl.title = `Assigned to ${st.assigned_to.name}`;
                                }
                            }
                            updateSubtaskBadge();
                            getModalInstance()?.hide();
                            window.tfShowOperationComplete?.({
                                kind: 'task',
                                title: 'Subtask updated',
                                message: 'Subtask details were updated successfully.',
                            });
                            window.setTimeout(() => window.location.reload(), 2200);
                        }
                    })
                    .catch(err => {
                        if (modalAlert) {
                            modalAlert.textContent = err.message;
                            modalAlert.classList.remove('d-none');
                        }
                    });
            });

            // Delete from Details Modal
            deleteModalBtn?.addEventListener('click', function () {
                if (!activeSubtaskDeleteUrl && activeSubtaskId) {
                    const li = document.getElementById(`subtask-${activeSubtaskId}`);
                    activeSubtaskDeleteUrl = li?.dataset.deleteUrl;
                }
                if (!activeSubtaskDeleteUrl) { return; }
                if (!confirm('Are you sure you want to delete this subtask?')) { return; }

                fetch(activeSubtaskDeleteUrl, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.ok && activeSubtaskId) {
                            document.getElementById(`subtask-${activeSubtaskId}`)?.remove();
                            updateSubtaskBadge();
                            getModalInstance()?.hide();
                        }
                    })
                    .catch(() => { });
            });

            // ── Deep Linking / Notification Focus ─────────────────────────────────
            // When landing on tasks.show?subtask=123 or #subtask-123
            const urlParams = new URLSearchParams(window.location.search);
            const focusSubtaskId = urlParams.get('subtask') || window.location.hash.replace('#subtask-', '');
            if (focusSubtaskId && /^\d+$/.test(focusSubtaskId)) {
                setTimeout(() => {
                    const targetRow = document.getElementById(`subtask-${focusSubtaskId}`);
                    if (targetRow) {
                        targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        targetRow.classList.add('is-highlighted');
                        setTimeout(() => targetRow.classList.remove('is-highlighted'), 3000);
                        const openBtn = targetRow.querySelector('.tf-subtask-status-trigger');
                        if (openBtn) {
                            openSubtaskModal(
                                openBtn.dataset.subtaskUrl,
                                openBtn.dataset.updateUrl,
                                targetRow.dataset.deleteUrl
                            );
                        }
                    }
                }, 300);
            }
        })();
    </script>

    <script>
        (() => {
            const timeline = document.querySelector('[data-task-timeline]');
            if (!timeline) {
                return;
            }

            const model = JSON.parse(timeline.dataset.timelineModel);
            const grid = timeline.querySelector('[data-tl-grid]');
            const scroller = timeline.querySelector('[data-tl-scroller]');
            const expandButton = timeline.querySelector('[data-tl-expand]');
            const taskDetailGrid = timeline.closest('.tf-task-detail__grid');
            const dayMs = 86400000;
            const parseDay = value => new Date(`${value}T00:00:00`);
            const iso = day => `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
            const daysBetween = (first, last) => Math.round((parseDay(last) - parseDay(first)) / dayMs);
            let visibleRange = { start: model.range.start, end: model.range.end };
            const dayIndex = date => Math.max(0, daysBetween(visibleRange.start, date));
            const escapeHtml = value => String(value ?? '').replace(/[&<>\"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;', "'": '&#039;' }[char]));
            const initials = value => String(value || '?').split(/\s+/).map(part => part[0]).join('').slice(0, 2).toUpperCase();
            const dailyUpdates = Array.isArray(model.dailyUpdates) ? model.dailyUpdates : [];
            const taskActivities = Array.isArray(model.activities) ? model.activities : [];
            const subtaskActivities = Array.isArray(model.subtaskActivities) ? model.subtaskActivities : [];
            const showSubtasks = Boolean(model.hasSubtasks) || subtaskActivities.length > 0;
            const recurrence = model.recurrence && Array.isArray(model.recurrence.occurrences)
                ? model.recurrence
                : null;
            const expandedDailyUpdates = new Set();
            const expandedTaskActivities = new Set();
            const expandedSubtaskActivities = new Set();
            let baseCellWidth = 64;
            let resizeFrame = 0;
            const scheduleRender = () => {
                if (resizeFrame) return;
                resizeFrame = window.requestAnimationFrame(() => {
                    resizeFrame = 0;
                    render();
                });
            };

            expandButton?.addEventListener('click', () => {
                const isExpanded = taskDetailGrid?.classList.toggle('is-timeline-expanded') ?? false;
                expandButton.setAttribute('aria-expanded', String(isExpanded));
                expandButton.setAttribute('aria-label', isExpanded ? 'Reduce timeline' : 'Expand timeline');
                expandButton.setAttribute('title', isExpanded ? 'Reduce timeline' : 'Expand timeline');
                expandButton.querySelector('i')?.classList.toggle('bi-arrows-angle-contract', isExpanded);
                expandButton.querySelector('i')?.classList.toggle('bi-arrows-angle-expand', !isExpanded);
                scheduleRender();
            });

            const fitTimelineToScroller = dayCount => {
                const labelColumnWidth = window.matchMedia('(max-width: 640px)').matches ? 190 : 220;
                const availableCalendarWidth = Math.max(0, scroller.clientWidth - labelColumnWidth);
                const cellWidth = Math.max(baseCellWidth, availableCalendarWidth / dayCount);

                timeline.style.setProperty('--tl-cell', `${cellWidth}px`);
            };

            const buildDays = () => {
                const count = daysBetween(visibleRange.start, visibleRange.end) + 1;
                return Array.from({ length: count }, (_, index) => {
                    const day = new Date(parseDay(visibleRange.start).getTime() + index * dayMs);
                    return { day, date: iso(day), index, weekend: [0, 6].includes(day.getDay()) };
                });
            };

            const render = () => {
                const scrollLeft = scroller.scrollLeft;
                const baseStart = model.range.today <= model.task.due
                    ? model.range.today
                    : model.task.due;
                const baseEnd = model.range.today <= model.task.due
                    ? model.task.due
                    : model.range.today;

                // Include historical creation/assignment dates so their milestone
                // badges remain visible even when they occurred before today.
                const milestoneDates = model.milestones
                    .map(item => item.date)
                    .filter(Boolean);

                const historyDates = [model.range.start, model.range.end].filter(Boolean);
                visibleRange = {
                    start: [baseStart, ...milestoneDates, ...subtaskActivities.map(event => event.date), ...historyDates].sort()[0],
                    end: [baseEnd, ...milestoneDates, ...subtaskActivities.map(event => event.date), ...historyDates].sort().at(-1),
                };
                const days = buildDays();
                const dayCount = days.length;
                fitTimelineToScroller(dayCount);
                const dailyActivitiesByDate = new Map();
                dailyUpdates.forEach(event => {
                    if (!event.date) return;
                    if (!dailyActivitiesByDate.has(event.date)) dailyActivitiesByDate.set(event.date, []);
                    dailyActivitiesByDate.get(event.date).push(event);
                });
                // Add one daily-update row for every calendar date from today through
                // the task deadline. Existing activity dates are retained as well.
                // Completed/cancelled tasks do not generate new waiting placeholders.
                const dailyTaskEventDates = new Set(dailyActivitiesByDate.keys());
                const assigneeIds = (model.task.assigneeIds || []).map(Number);
                const dailyTaskUpdateDates = new Set((model.dailyTaskUpdateDates || [])
                    .filter(item => assigneeIds.includes(Number(item.employeeId)))
                    .map(item => item.date));
                const isTerminalTask = ['completed', 'cancelled'].includes(
                    String(model.task.status || '').toLowerCase()
                );
                const waitingStart = model.task.start > model.range.today
                    ? model.task.start
                    : model.range.today;
                const waitingEnd = model.task.due;

                if (!isTerminalTask && waitingStart && waitingEnd && waitingStart <= waitingEnd) {
                    const cursor = parseDay(waitingStart);
                    const lastDay = parseDay(waitingEnd);

                    while (cursor <= lastDay) {
                        dailyTaskEventDates.add(iso(cursor));
                        cursor.setDate(cursor.getDate() + 1);
                    }
                }

                const updateRows = [...dailyTaskEventDates].sort().map(date => {
                    const events = (dailyActivitiesByDate.get(date) || [])
                        .slice()
                        .sort((a, b) => Number(a.timestamp || 0) - Number(b.timestamp || 0));
                    const types = new Set(events.map(event => String(event.type || '').toLowerCase()));
                    const hasDailyTaskActivity = events.some(event =>
                        event.isDailyTask === true && event.isAccountability !== true
                    ) || dailyTaskUpdateDates.has(date);
                    const hasMissedEvent = ['daily_accountability_missed', 'daily_task_missed']
                        .some(type => types.has(type));
                    const state = hasMissedEvent
                        ? 'missed'
                        : types.has('daily_task_completed')
                            ? 'submitted'
                            : date > model.range.today ? 'upcoming' : 'open';
                    const noUpdateToday = !isTerminalTask
                        && date === model.range.today
                        && assigneeIds.length > 0
                        && !hasDailyTaskActivity
                        && !hasMissedEvent;

                    return {
                        date,
                        events,
                        state,
                        noUpdateToday,
                        waiting: !isTerminalTask
                            && date > model.range.today
                            && !hasDailyTaskActivity
                            && ['upcoming'].includes(state),
                    };
                });
                const missedUpdateRows = updateRows.filter(row => row.state === 'missed');
                const taskActivitiesByDate = new Map();
                taskActivities.forEach(event => {
                    if (!event.date) return;
                    if (!taskActivitiesByDate.has(event.date)) taskActivitiesByDate.set(event.date, []);
                    taskActivitiesByDate.get(event.date).push(event);
                });
                const taskActivityRows = [...taskActivitiesByDate].sort(([first], [second]) => first.localeCompare(second))
                    .map(([date, events]) => ({
                        date,
                        events: events.slice().sort((first, second) => Number(first.timestamp || 0) - Number(second.timestamp || 0)),
                    }));
                const subtaskActivitiesByDate = new Map();
                subtaskActivities.forEach(event => {
                    if (!event.date) return;
                    if (!subtaskActivitiesByDate.has(event.date)) subtaskActivitiesByDate.set(event.date, []);
                    subtaskActivitiesByDate.get(event.date).push(event);
                });
                const subtaskActivityRows = [...subtaskActivitiesByDate].sort(([first], [second]) => first.localeCompare(second))
                    .map(([date, events]) => ({
                        date,
                        events: events.slice().sort((first, second) => Number(first.timestamp || 0) - Number(second.timestamp || 0)),
                    }));
                const todayIndex = days.find(day => day.date === model.range.today)?.index;
                const monthSpans = [];
                days.forEach(({ day }, index) => {
                    const label = day.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
                    const current = monthSpans.at(-1);
                    if (current?.label === label) {
                        current.length += 1;
                    } else {
                        monthSpans.push({ label, start: index, length: 1 });
                    }
                });
                const eventIcon = type => ({
                    start: 'play-fill', hold: 'pause-fill', resume: 'arrow-repeat', submission: 'send-fill',
                    review: 'check-lg', completed: 'check-lg', cancellation: 'x-lg', assignment: 'person-plus-fill',
                    reassignment: 'people-fill', deadline: 'calendar-event-fill', progress: 'graph-up-arrow',
                    updated: 'pencil-fill', task_updated: 'pencil-square', status: 'arrow-left-right',
                    created: 'plus-circle-fill', deleted: 'trash-fill',
                    work_update: 'clipboard-check', daily_task_update: 'clipboard-check', accepted: 'check-circle-fill',
                    daily_task_created: 'plus-circle-fill', daily_task_assigned: 'person-plus-fill',
                    daily_task_pending: 'clock-fill', daily_task_started: 'play-fill',
                    daily_task_on_hold: 'pause-fill', daily_task_resumed: 'arrow-repeat',
                    daily_task_progress_updated: 'graph-up-arrow', daily_task_update_submitted: 'clipboard-check',
                    daily_task_reassigned: 'people-fill', daily_task_rescheduled: 'calendar-event',
                    daily_task_completed: 'check-circle-fill', daily_task_cancelled: 'x-circle-fill',
                    daily_task_missed: 'exclamation-circle-fill',
                    daily_accountability_missed: 'exclamation-triangle-fill'
                }[type] || 'circle-fill');
                const eventLabel = (type, event = null) => {
                    if (type === 'review') {
                        return String(event?.to || '').toLowerCase() === 'completed'
                            ? 'Task Approved'
                            : 'Changes Requested';
                    }

                    return ({
                    start: 'Work Started', hold: 'On Hold', resume: 'Unhold · Work Resumed',
                    submission: 'Task Submitted for Review', review: 'Task Approved',
                    completed: 'Task Completed', cancellation: 'Task Cancelled',
                    assignment: 'Task Assigned', reassignment: 'Re-assigned',
                    deadline: 'Deadline updated',
                    deadline_extension_requested: 'Deadline extension requested',
                    deadline_extension_approved: 'Deadline extension approved',
                    deadline_extension_rejected: 'Deadline extension rejected',
                    progress: 'Progress updated',
                    updated: 'Task updated', task_updated: 'Task Edited',
                    status: 'Status updated', work_update: 'Work Update',
                    accepted: 'Task accepted', created: 'Task Created',
                    daily_task_update: 'Daily Task Update',
                    daily_task_created: 'Daily Task Created',
                    daily_task_assigned: 'Daily Task Assigned',
                    daily_task_pending: 'Daily Task Ready',
                    daily_task_started: 'Daily Task · Work Started',
                    daily_task_on_hold: 'Daily Task · On Hold',
                    daily_task_resumed: 'Daily Task · Work Resumed',
                    daily_task_progress_updated: 'Daily Task · Progress Updated',
                    daily_task_update_submitted: 'Daily Update Submitted',
                    daily_task_reassigned: 'Daily Task Re-assigned',
                    daily_task_rescheduled: 'Daily Task Rescheduled',
                    daily_task_completed: 'Daily Task · Completed',
                    daily_task_cancelled: 'Daily Task · Cancelled',
                    daily_task_missed: 'Daily Task · Missed',
                    daily_accountability_missed: 'Daily Update Missing'
                    })[type] || String(type).replaceAll('_', ' ');
                };
                const eventTooltip = (heading, timestamp, detail, actor, context = '', people = null, dailyInfo = null) => {
                    const dailyInfoRows = dailyInfo
                        ? [
                            ['Progress', `${dailyInfo.progress ?? 0}%`],
                            ['Work date', dailyInfo.workDate || '—'],
                            ['Current status', dailyInfo.currentStatus || '—'],
                        ].map(([label, value]) => `<span class="tl-event-tooltip__daily-info-row"><span class="tl-event-tooltip__daily-info-label">${label}</span><span class="tl-event-tooltip__daily-info-value">${escapeHtml(value)}</span></span>`).join('')
                        : '';

                    return `<span class="tl-event-tooltip" aria-hidden="true"><span class="tl-event-tooltip__heading">${escapeHtml(heading)}</span>${context ? `<span class="tl-event-tooltip__context">${people ? 'Daily Task: ' : ''}${escapeHtml(context)}</span>` : ''}${people ? `<span class="tl-event-tooltip__people"><span class="tl-event-tooltip__person"><span class="tl-event-tooltip__person-label">Employee</span><span class="tl-event-tooltip__person-value">${escapeHtml(people.employee || 'Unknown employee')}</span></span><span class="tl-event-tooltip__person"><span class="tl-event-tooltip__person-label">Department</span><span class="tl-event-tooltip__person-value">${escapeHtml(people.department || 'Unknown department')}</span></span></span>` : ''}${dailyInfoRows ? `<span class="tl-event-tooltip__daily-info">${dailyInfoRows}</span>` : ''}<span class="tl-event-tooltip__meta"><i class="bi bi-calendar3" aria-hidden="true"></i><span>${escapeHtml(timestamp)}</span></span>${detail ? `<span class="tl-event-tooltip__detail">${escapeHtml(detail)}</span>` : ''}<span class="tl-event-tooltip__actor"><i class="bi bi-person" aria-hidden="true"></i><span>${escapeHtml(actor || 'System')}</span></span></span>`;
                };

                const taskFieldLabels = {
                    title: 'Title', description: 'Description', priority: 'Priority',
                    start_date: 'Start date', due_date: 'Deadline', estimated_hours: 'Estimated hours',
                    company_id: 'Company', department_id: 'Department', task_type: 'Task type',
                    status: 'Status', assigned_to: 'Assignee'
                };
                const taskFieldLabel = field => taskFieldLabels[String(field).toLowerCase()]
                    || String(field).replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
                const taskEventDetails = event => {
                    const type = String(event.type || '').toLowerCase();
                    if (type === 'assignment' || type === 'reassignment') {
                        return event.to
                            ? `${event.from ? `${event.from} → ` : ''}${type === 'reassignment' ? 'Re-assigned to ' : 'Assigned to '}${event.to}`
                            : 'Assignment updated';
                    }
                    if (type === 'task_updated' || type === 'updated') {
                        try {
                            const payload = typeof event.detail === 'string' ? JSON.parse(event.detail) : event.detail;
                            const changes = payload?.changes && typeof payload.changes === 'object' ? payload.changes : {};
                            const entries = Object.entries(changes).map(([field, change]) => {
                                const from = change && typeof change === 'object' ? change.from : '';
                                const to = change && typeof change === 'object' ? change.to : change;
                                return `${taskFieldLabel(field)}: ${from === null || from === '' ? '—' : from} → ${to === null || to === '' ? '—' : to}`;
                            });
                            return entries.length ? entries.join(' · ') : 'Task details updated';
                        } catch (_) {
                            return String(event.detail || 'Task details updated');
                        }
                    }
                    if (type === 'start') return event.detail || 'Work started';
                    if (type === 'hold') return event.detail || 'Task placed On Hold';
                    if (type === 'resume') return event.detail || 'Task resumed';
                    if (type === 'work_update') return event.detail || 'Work update recorded';
                    if (type === 'daily_task_update') return event.detail || 'Daily Task update recorded';
                    if (event.isDailyTask) {
                        return event.detail || `Daily Task: ${event.dailyTaskTitle || 'Untitled task'}`;
                    }
                    if (type.startsWith('deadline_extension_')) {
                        const transition = event.from || event.to
                            ? `${event.from || 'Current deadline'} → ${event.to || 'Requested deadline'}`
                            : 'Deadline extension activity';
                        return `${transition}${event.detail ? ` · ${event.detail}` : ''}`;
                    }
                    if (type === 'submission') return event.detail || 'Work submitted for review';
                    if (type === 'review') return event.detail || (String(event.to) === 'completed' ? 'Work approved · Completed' : 'Review action recorded');
                    if (type === 'cancellation' || type === 'cancelled') return event.detail || 'Task cancelled';
                    if (type === 'status') return `${event.from || 'Status'} → ${event.to || 'Updated'}`;
                    return event.detail || eventLabel(type, event);
                };
                const formatEventDate = date => parseDay(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
                const prettySubtaskValue = value => String(value ?? '')
                    .replaceAll('_', ' ')
                    .replace(/\b\w/g, character => character.toUpperCase());
                const subtaskFieldLabels = {
                    title: 'Title',
                    description: 'Description',
                    status: 'Status',
                    task_status_id: 'Status',
                    priority: 'Priority',
                    assigned_to: 'Assignee',
                    assignee_id: 'Assignee',
                    owner_id: 'Assignee',
                    assignee: 'Assignee',
                    start_date: 'Start date',
                    due_date: 'Deadline',
                    deadline: 'Deadline',
                };
                const displaySubtaskField = field => subtaskFieldLabels[String(field).toLowerCase()] || prettySubtaskValue(field);
                const parseSubtaskChanges = detail => {
                    if (!detail) return [];
                    let payload = detail;
                    if (typeof payload === 'string') {
                        const raw = payload.trim();
                        if (!raw) return [];
                        try {
                            payload = JSON.parse(raw);
                        } catch (_) {
                            return [{ label: '', text: raw }];
                        }
                    }
                    if (!payload || typeof payload !== 'object') return [];
                    const source = payload.changes && typeof payload.changes === 'object'
                        ? payload.changes
                        : payload.fields && typeof payload.fields === 'object'
                            ? payload.fields
                            : payload;
                    return Object.entries(source)
                        .filter(([field]) => !['action', 'event', 'type', 'actor', 'subtask_id', 'message'].includes(String(field).toLowerCase()))
                        .map(([field, value]) => {
                            const label = displaySubtaskField(field.replace(/^(old_|new_)/, ''));
                            if (Array.isArray(value) && value.length === 2) {
                                return { label, text: `${value[0] ?? '—'} → ${value[1] ?? '—'}` };
                            }
                            if (value && typeof value === 'object') {
                                const before = value.from ?? value.old ?? value.old_value ?? value.before;
                                const after = value.to ?? value.new ?? value.new_value ?? value.after;
                                if (before !== undefined || after !== undefined) {
                                    return { label, text: `${before ?? '—'} → ${after ?? '—'}` };
                                }
                                if ('value' in value) return { label, text: String(value.value ?? '—') };
                                return { label, text: JSON.stringify(value) };
                            }
                            return { label, text: String(value ?? '—') };
                        })
                        .filter(change => change.text !== '');
                };
                const subtaskEventPresentation = event => {
                    const type = String(event.type || '').toLowerCase();
                    const actor = event.actor || 'System';
                    const changes = parseSubtaskChanges(event.detail);
                    const completionTypes = [
                        'completed', 'complete', 'done', 'completed_subtask',
                        'subtask_completed', 'mark_completed', 'marked_completed',
                        'completion', 'subtask_complete', 'subtask_done',
                    ];
                    const normalizedTargetStatus = String(event.to ?? '').toLowerCase().replaceAll(' ', '_');
                    const statusChange = changes.find(change => String(change.label).toLowerCase() === 'status');
                    const statusChangeText = String(statusChange?.text ?? '').toLowerCase();
                    const isCompletionEvent = completionTypes.includes(type)
                        || ['completed', 'complete', 'done'].includes(normalizedTargetStatus)
                        || /(?:→|->)\s*(?:completed|complete|done)\b/.test(statusChangeText);
                    const isStatusEvent = type.includes('status') || [
                        'hold', 'on_hold', 'resume', 'reopened', ...completionTypes,
                    ].includes(type);

                    if ((type === 'created' || type === 'create')) {
                        return { title: `Subtask created by ${actor}`, changes };
                    }
                    if (isCompletionEvent) {
                        const completionChanges = changes.length
                            ? changes
                            : [{
                                label: 'Status', text: event.from
                                    ? `${prettySubtaskValue(event.from)} → Completed`
                                    : 'Marked as Completed'
                            }];
                        return { title: `Subtask marked as completed by ${actor}`, changes: completionChanges };
                    }
                    if (isStatusEvent && (event.from || event.to)) {
                        return {
                            title: `Status changed by ${actor}`,
                            changes: [{ label: 'Status', text: `${prettySubtaskValue(event.from) || '—'} → ${prettySubtaskValue(event.to) || '—'}` }],
                        };
                    }
                    if (isStatusEvent) {
                        const statusLabel = type === 'hold' || type === 'on_hold'
                            ? 'On Hold'
                            : ['resume'].includes(type) ? 'In Progress'
                                : type === 'reopened' ? 'Reopened' : null;
                        return {
                            title: `Status changed by ${actor}`,
                            changes: changes.length ? changes : (statusLabel ? [{ label: 'Status', text: statusLabel }] : []),
                        };
                    }
                    if (type === 'deleted' || type === 'delete') {
                        return { title: `Subtask deleted by ${actor}`, changes };
                    }
                    if (type === 'assigned' || type === 'assignment') {
                        return { title: `Assignee changed by ${actor}`, changes };
                    }
                    if (changes.length === 1) {
                        const field = changes[0].label || 'Details';
                        const verb = field === 'Status' ? 'changed' : 'updated';
                        return { title: `${field} ${verb} by ${actor}`, changes };
                    }
                    if (changes.length > 1) {
                        return { title: `Multiple fields updated by ${actor}`, changes };
                    }
                    if (type === 'updated' || type === 'edited' || type === 'update') {
                        return { title: `Subtask updated by ${actor}`, changes };
                    }
                    return { title: `Subtask ${prettySubtaskValue(type || 'activity')} by ${actor}`, changes };
                };
                const taskActivityTone = event => {
                    const type = String(event.type || '').toLowerCase();
                    const targetStatus = String(event.to || '').toLowerCase();

                    // Give each task lifecycle event a distinct, accessible tone.
                    // Avoid red for routine task history; reserve critical colors for alerts elsewhere.
                    if (type === 'created') return 'created';
                    if (type === 'start' || type === 'started') return 'started';
                    if (type === 'hold') return 'on_hold';
                    if (type === 'resume' || type === 'resumed') return 'resumed';
                    if (type === 'submission' || type === 'submitted') return 'submitted';
                    if (type === 'review') return targetStatus === 'completed' ? 'completed' : 'review';
                    if (type === 'completed') return 'completed';
                    if (type === 'cancellation' || type === 'cancelled') return 'cancelled';
                    if (type.startsWith('deadline')) return 'deadline';
                    if (type === 'assignment' || type === 'reassignment') return 'assigned';
                    if (type === 'progress') return 'progress';
                    if (type === 'task_updated' || type === 'updated') return 'updated';
                    return 'activity';
                };
                const leftSections = [
                    ['milestones', 'Milestones', model.milestones.length, null],
                    ['task', 'Task', taskActivities.length, null],
                    ['subtasks', 'Subtasks', subtaskActivities.length, null],
                    ['updates', 'Daily Task Update', updateRows.length, null],
                    ['recurring', 'Recurring details', recurrence?.occurrences.length || 0, null],
                ];
                const sectionLabel = ([key, title, count, content]) => `<div class="tl-label-section" data-tl-section="${key}"><button type="button" aria-expanded="true" aria-label="Toggle ${title}"><i class="bi bi-chevron-down"></i></button><strong>${title}</strong>${key === 'task' ? `<span class="tl-task-section-title" title="${escapeHtml(model.task.title)}">${escapeHtml(model.task.title)}</span>` : ''}</div>${content ? `<div class="tl-label-row" data-tl-row="${key}">${content}</div>` : ''}`;
                const taskActivityLabels = taskActivityRows.map(({ date, events }, index) => {
                    const eventCount = events.length;
                    // Keep the left label row exactly in step with its right-side calendar row.
                    const rowHeight = eventCount > 0 ? 62 : 56;
                    return `<div class="tl-label-row tl-task-label-row" data-tl-row="task" data-task-activity-index="${index}" style="min-height:${rowHeight}px"><i class="bi bi-activity"></i><span>${parseDay(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</span>${eventCount > 1 ? `<button type="button" class="tl-daily-expand" data-task-expand="${index}" aria-expanded="false" aria-label="Show ${eventCount} task activities for ${parseDay(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}"><span>${eventCount} events</span><i class="bi bi-chevron-down"></i></button>` : (eventCount === 1 ? `<small class="tl-count">1 event</small>` : '')}</div>`;
                }).join('');
                const updateLabels = updateRows.map(({ date, state, events }, index) => {
                    const eventCount = events.length;
                    // Keep the collapsed daily-task row compact; the expand control
                    // increases both timeline columns when the remaining events show.
                    const rowHeight = eventCount > 0 ? 62 : 56;
                    const expandControl = eventCount > 1
                        ? `<button type="button" class="tl-daily-expand" data-daily-expand="${index}" aria-expanded="false" aria-label="Show ${eventCount} daily task activities for ${parseDay(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}"><span>${eventCount} events</span><i class="bi bi-chevron-down"></i></button>`
                        : (eventCount === 1 ? `<small class="tl-count">1 event</small>` : '');
                    return `<div class="tl-label-row tl-daily-label-row" data-tl-row="updates" data-daily-index="${index}" style="min-height:${rowHeight}px"><i class="bi ${state === 'submitted' ? 'bi-calendar-check' : state === 'missed' ? 'bi-flag-fill' : state === 'exempted' ? 'bi-shield-check' : 'bi-calendar3'}"></i><span>${parseDay(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</span>${expandControl}</div>`;
                }).join('');
                const visibleMilestones = model.milestones.filter(item =>
                    item.date >= visibleRange.start &&
                    item.date <= visibleRange.end &&
                    (item.type === 'deadline' || Boolean(item.badge))
                );
                const milestoneLabels = visibleMilestones.map(item => `<div class="tl-label-row" data-tl-row="milestones"><i class="bi bi-${item.type === 'deadline' ? 'diamond-fill' : 'bookmark-fill'}"></i><span title="${escapeHtml(item.label)}">${escapeHtml(item.label)}</span></div>`).join('');
                const escalationLabel = missedUpdateRows.length >= 2
                    ? `<div class="tl-label-row" data-tl-row="milestones"><i class="bi bi-exclamation-triangle-fill"></i><span>Daily update escalation</span></div>`
                    : '';
                const subtaskActivityLabels = subtaskActivityRows.map(({ date, events }, index) => {
                    const eventCount = events.length;
                    // Subtask activity rows use the same 62px/56px sizing as the calendar side.
                    const rowHeight = eventCount > 0 ? 62 : 56;
                    return `<div class="tl-label-row tl-task-label-row" data-tl-row="subtasks" data-subtask-activity-index="${index}" style="min-height:${rowHeight}px"><i class="bi bi-list-check"></i><span>${parseDay(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</span>${eventCount > 1 ? `<button type="button" class="tl-daily-expand" data-subtask-expand="${index}" aria-expanded="false" aria-label="Show ${eventCount} subtask activities for ${parseDay(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}"><span>${eventCount} events</span><i class="bi bi-chevron-down"></i></button>` : (eventCount === 1 ? `<small class="tl-count">1 event</small>` : '')}</div>`;
                }).join('');
                const recurrenceLabels = recurrence ? recurrence.occurrences.map(item => `<div class="tl-label-row" data-tl-row="recurring"><i class="bi bi-arrow-repeat"></i><span>${escapeHtml(item.title || 'Recurring occurrence')}</span></div>`).join('') : '';
                const subtaskSection = showSubtasks
                    ? `${sectionLabel(leftSections[2])}${subtaskActivityLabels}`
                    : '';
                const left = `<div class="tl-left"><div class="tl-item-head">ITEM</div>${sectionLabel(leftSections[0])}${milestoneLabels}${escalationLabel}${sectionLabel(leftSections[1])}${taskActivityLabels}${subtaskSection}${sectionLabel(leftSections[3])}${updateLabels}${recurrence ? sectionLabel(leftSections[4]) + recurrenceLabels : ''}</div>`;
                const dateHeader = days.map(({ day, date, weekend }) => `<div class="tl-date ${weekend ? 'tl-weekend' : ''} ${date === model.range.today ? 'is-today' : ''}"><time datetime="${date}"><small>${day.toLocaleDateString(undefined, { weekday: 'narrow' })}</small>${day.getDate()}</time></div>`).join('');
                const monthHeader = monthSpans.map(span => `<div class="tl-month" style="grid-column: ${span.start + 1} / span ${span.length}">${span.label}</div>`).join('');
                const taskTimelineRows = taskActivityRows.map(({ date, events }, index) => {
                    const eventLines = events.map((event, eventIndex) => {
                        const type = String(event.type || '').toLowerCase().replaceAll(' ', '_');
                        const label = eventLabel(type, event);
                        const detail = taskEventDetails(event);
                        const meta = event.actor || 'System';
                        const timestamp = `${parseDay(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}${event.time ? ` · ${event.time}` : ''}`;
                        const tooltip = eventTooltip(label, timestamp, detail, meta);
                        return `<span class="tl-daily-event tl-task-event tl-daily-event--${taskActivityTone(event)} ${eventIndex > 0 ? 'is-task-extra' : ''}" ${eventIndex > 0 ? 'hidden' : ''} tabindex="0" aria-label="${escapeHtml([label, timestamp, detail, `By ${meta}`].filter(Boolean).join('. '))}"><strong><i class="bi bi-${eventIcon(type)}" aria-hidden="true"></i>${escapeHtml(label)}</strong>${tooltip}</span>`;
                    }).join('');
                    const eventsMarkup = eventLines ? `<span class="tl-daily-events tl-task-events">${eventLines}</span>` : '';
                    const height = events.length > 0 ? 62 : 56;
                    return `<div class="tl-row" data-tl-row="task" data-task-activity-index="${index}" style="min-height:${height}px">${eventsMarkup ? `<span class="tl-row-copy tl-row-copy--daily" style="--tl-start:${dayIndex(date)}">${eventsMarkup}</span>` : ''}</div>`;
                }).join('');
                const subtaskEventLabel = event => {
                    const type = String(event.type || '').replace(/^subtask_/, '');
                    return ({
                        created: 'Subtask Created',
                        deleted: 'Subtask Deleted',
                        status: 'Status Updated',
                        progress: 'Progress Updated',
                        assignment: 'Assignee Updated',
                        deadline: 'Dates Updated',
                        conflict: 'Date Conflict',
                        updated: 'Subtask Updated',
                    })[type] || `Subtask ${prettySubtaskValue(type || 'Activity')}`;
                };
                const subtaskEventTone = event => {
                    const type = String(event.type || '').toLowerCase();
                    const status = String(event.to || '').toLowerCase();
                    if (type.includes('deleted') || type.includes('cancel') || status === 'cancelled') return 'cancelled';
                    if (status === 'completed' || type.includes('completed')) return 'completed';
                    if (status === 'on_hold' || type.includes('hold')) return 'on_hold';
                    return 'in_progress';
                };
                const subtaskTimelineRows = subtaskActivityRows.map(({ date, events }, index) => {
                    const eventLines = events.map((event, eventIndex) => {
                        const label = subtaskEventLabel(event);
                        const transition = event.from || event.to
                            ? `${prettySubtaskValue(event.from || '—')} → ${prettySubtaskValue(event.to || '—')}`
                            : '';
                        const detail = [transition, event.detail].filter(Boolean).join(' · ') || 'Activity recorded';
                        const meta = event.actor || 'System';
                        const timestamp = `${parseDay(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}${event.time ? ` · ${event.time}` : ''}`;
                        const badgeLabel = label.startsWith('Subtask ') ? label : `Subtask: ${label}`;
                        const tooltip = eventTooltip(label, timestamp, detail, meta, event.subtaskTitle);
                        return `<span class="tl-daily-event tl-task-event tl-daily-event--${subtaskEventTone(event)} ${eventIndex > 0 ? 'is-subtask-extra' : ''}" ${eventIndex > 0 ? 'hidden' : ''} tabindex="0" aria-label="${escapeHtml([event.subtaskTitle, label, timestamp, detail, `By ${meta}`].filter(Boolean).join('. '))}"><strong><i class="bi bi-${eventIcon(String(event.type || '').replace(/^subtask_/, ''))}" aria-hidden="true"></i>${escapeHtml(badgeLabel)}</strong>${tooltip}</span>`;
                    }).join('');
                    const eventsMarkup = eventLines ? `<span class="tl-daily-events tl-task-events">${eventLines}</span>` : '';
                    const height = events.length > 0 ? 62 : 56;
                    return `<div class="tl-row" data-tl-row="subtasks" data-subtask-activity-index="${index}" style="min-height:${height}px">${eventsMarkup ? `<span class="tl-row-copy tl-row-copy--daily" style="--tl-start:${dayIndex(date)}">${eventsMarkup}</span>` : ''}</div>`;
                }).join('');
                const updateTimelineRows = updateRows.map(({ date, state, events, waiting, noUpdateToday }, index) => {
                    const eventLines = events.map((event, eventIndex) => {
                        const type = String(event.type || '').toLowerCase().replaceAll(' ', '_');
                        const label = eventLabel(type, event);
                        const detail = taskEventDetails(event);
                        const meta = event.actor || 'System';
                        const isAlert = [
                            'cancellation',
                            'cancelled',
                            'daily_task_cancelled',
                            'daily_task_missed',
                            'daily_accountability_missed',
                        ].includes(type);
                        const isDailyTaskActivity = Boolean(event.isDailyTask);
                        const eventTone = event.isAccountability
                            ? event.accountabilityTone
                            : event.isDailyTask
                            ? (type === 'daily_task_update_submitted' ? 'update-submitted' : event.eventStatus)
                            : '';
                        const workflowTone = type === 'start'
                            ? 'is-workflow-start'
                            : ['hold', 'on_hold'].includes(type)
                                ? 'is-workflow-hold'
                                : type === 'resume' ? 'is-workflow-resume' : '';
                        const timestamp = `${parseDay(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}${event.time ? ` · ${event.time}` : ''}`;
                        const dailyInfo = isDailyTaskActivity && !event.isAccountability
                            ? {
                                progress: event.progress,
                                workDate: event.workDate
                                    ? parseDay(event.workDate).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
                                    : '—',
                                currentStatus: event.currentStatus,
                            }
                            : null;
                        const tooltip = eventTooltip(
                            label,
                            timestamp,
                            detail,
                            meta,
                            event.dailyTaskTitle,
                            isDailyTaskActivity ? { employee: event.employee, department: event.department } : null,
                            dailyInfo,
                        );
                        const isDailyTaskUpdate = isDailyTaskActivity && ['daily_task_update_submitted', 'daily_task_update'].includes(type);
                        const badgeIcon = isDailyTaskUpdate ? '<i class="bi bi-clipboard-check" aria-hidden="true"></i>' : '';
                        const isExtraEvent = eventIndex > 0;
                        return `<span class="tl-daily-event ${isDailyTaskActivity ? 'is-daily-task-activity' : ''} ${isDailyTaskUpdate ? 'is-daily-task-update' : ''} ${eventTone ? `tl-daily-event--${escapeHtml(eventTone)}` : ''} ${workflowTone} ${isAlert ? 'is-alert' : ''} ${isExtraEvent ? 'is-daily-extra' : ''}" ${isExtraEvent ? 'hidden' : ''} tabindex="0" aria-label="${escapeHtml([label, timestamp, detail, `By ${meta}`].filter(Boolean).join('. '))}"><strong>${badgeIcon}${escapeHtml(label)}</strong>${tooltip}</span>`;
                    }).join('');
                    const eventBlock = eventLines ? `<span class="tl-daily-events">${eventLines}</span>` : '';
                    const copy = eventBlock;
                    const dependency = index < updateRows.length - 1 ? '<span class="tl-dependency" aria-label="Dependency to the next daily update"></span>' : '';
                    const icon = state === 'submitted' || state === 'open' || state === 'upcoming'
                    ? ''
                    : state === 'missed' ? 'flag-fill' : state === 'exempted' ? 'shield-check' : 'clock';
                    const marker = icon ? `<span class="tl-update tl-update--${state}" style="--tl-start:${dayIndex(date)}" aria-label="${state} work update"><i class="bi bi-${icon}"></i></span>` : '';
                    const waitingBadge = waiting
                        ? `<span class="tl-daily-waiting tl-daily-waiting--upcoming"
                                style="--tl-start:${dayIndex(date)}"
                                title="Upcoming Daily Task activity · ${escapeHtml(formatEventDate(date))}"
                                aria-label="Upcoming Daily Task activity on ${escapeHtml(formatEventDate(date))}">
                                <i class="bi bi-calendar-event" aria-hidden="true"></i>
                                <strong>Upcoming</strong>
                           </span>`
                        : '';
                    const noUpdateBadge = noUpdateToday
                        ? `<span class="tl-daily-no-update"
                                style="--tl-start:${dayIndex(date)}"
                                title="No Daily Task status update recorded today for ${escapeHtml(model.task.assignee || 'the assigned employee')}"
                                aria-label="No Daily Task updated today for ${escapeHtml(model.task.assignee || 'the assigned employee')}">
                                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                <strong>No task updated today</strong>
                           </span>`
                        : '';
                    const copyMarkup = copy ? `<span class="tl-row-copy tl-row-copy--daily" style="--tl-start:${dayIndex(date)}">${copy}</span>` : '';
                    // Match the compact default state; the existing data-daily-expand
                    // handler recalculates the row height when the user expands it.
                    const rowHeight = events.length > 0 ? 62 : 56;
                    return `<div class="tl-row tl-row--daily-${state}${noUpdateToday ? ' tl-row--daily-warning' : ''}" data-tl-row="updates" data-daily-index="${index}" style="min-height:${rowHeight}px">${marker}${waitingBadge}${noUpdateBadge}${copyMarkup}${dependency}</div>`;
                }).join('');
                const milestoneRows = visibleMilestones.map(item => {
                        const dateLabel = formatEventDate(item.date);

                        // The deadline is shown as a continuous red dotted line ending
                        // at a diamond marker, matching the milestone timeline design.
                        // Creation and assignment remain readable blue event badges.
                        const milestoneDisplay = item.type === 'deadline'
                            ? `<span class="tl-milestone tl-milestone--danger"
                                        style="--tl-start:${dayIndex(item.date)}"
                                        title="Deadline · ${escapeHtml(dateLabel)}"
                                        aria-label="Deadline on ${escapeHtml(dateLabel)}"></span>`
                            : `<span class="tl-milestone-badge tl-milestone-badge--${escapeHtml(item.type)}"
                                        style="--tl-start:${dayIndex(item.date)}"
                                        title="${escapeHtml(item.badge)} · ${escapeHtml(dateLabel)}"
                                        aria-label="${escapeHtml(item.badge)} on ${escapeHtml(dateLabel)}">${escapeHtml(item.badge)}</span>`;

                        return `<div class="tl-row${item.type === 'deadline' ? ' tl-row--deadline' : ''}" data-tl-row="milestones" ${item.type === 'deadline' ? `style="--tl-start:${dayIndex(item.date)}"` : ''}>${milestoneDisplay}</div>`;
                    }).join('');
                const recurringRows = recurrence ? recurrence.occurrences.filter(item => item.date >= visibleRange.start && item.date <= visibleRange.end).map(item => `<div class="tl-row" data-tl-row="recurring"><span class="tl-recurring tl-recurring--${escapeHtml(item.status)}" style="--tl-start:${dayIndex(item.date)}" aria-label="${escapeHtml(item.status)} recurring occurrence"><i class="bi bi-arrow-repeat"></i></span><span class="tl-row-copy" style="--tl-start:${dayIndex(item.date)}"><strong>${escapeHtml(item.title || 'Recurring occurrence')}</strong> <span>· ${escapeHtml(item.status.replaceAll('_', ' '))}</span></span></div>`).join('') : '';
                const escalation = missedUpdateRows.length >= 2 ? `<div class="tl-row" data-tl-row="milestones"><span class="tl-escalation" style="--tl-start:${dayIndex(missedUpdateRows[1].date)}"><i class="bi bi-diamond-fill"></i> Escalated to DM Manager · 2 missed days in a row</span></div>` : '';
                const calendar = `<div class="tl-calendar" style="--tl-days:${dayCount}"><div class="tl-months">${monthHeader}</div><div class="tl-dates">${dateHeader}</div>${todayIndex === undefined ? '' : `<span class="tl-today-column" style="left: calc(${todayIndex} * var(--tl-cell))"></span>`}<div class="tl-section-row"></div>${milestoneRows}${escalation}<div class="tl-section-row"></div>${taskTimelineRows}${showSubtasks ? `<div class="tl-section-row"></div>${subtaskTimelineRows}` : ''}<div class="tl-section-row"></div>${updateTimelineRows}${recurrence ? `<div class="tl-section-row"></div>${recurringRows}` : ''}</div>`;
                grid.innerHTML = left + calendar;
                scroller.scrollLeft = scrollLeft;
                grid.querySelectorAll('.tl-daily-event').forEach(badge => {
                    const positionTooltip = () => {
                        const tooltip = badge.querySelector('.tl-event-tooltip');
                        if (!tooltip) return;
                        badge.classList.add('is-tooltip-visible');
                        requestAnimationFrame(() => {
                            const scrollerRect = scroller.getBoundingClientRect();
                            const timelineStart = grid.querySelector('.tl-left')?.getBoundingClientRect().width || 0;
                            const tooltipRect = tooltip.getBoundingClientRect();
                            const calendarLeft = scrollerRect.left + timelineStart + 6;

                            badge.classList.remove('is-tooltip-align-left', 'is-tooltip-align-right');
                            if (tooltipRect.left < calendarLeft) {
                                badge.classList.add('is-tooltip-align-left');
                            } else if (tooltipRect.right > scrollerRect.right - 6) {
                                badge.classList.add('is-tooltip-align-right');
                            }
                        });
                    };
                    const hideTooltip = () => {
                        if (badge.matches(':hover, :focus')) return;
                        badge.classList.remove('is-tooltip-visible', 'is-tooltip-align-left', 'is-tooltip-align-right');
                    };

                    badge.addEventListener('pointerenter', positionTooltip);
                    badge.addEventListener('focus', positionTooltip);
                    badge.addEventListener('pointerleave', hideTooltip);
                    badge.addEventListener('blur', hideTooltip);
                });
                grid.querySelectorAll('[data-tl-section] button').forEach(button => {
                    button.addEventListener('click', () => {
                        const key = button.closest('[data-tl-section]').dataset.tlSection;
                        const expanded = button.getAttribute('aria-expanded') !== 'false';
                        button.setAttribute('aria-expanded', String(!expanded));
                        grid.querySelectorAll(`[data-tl-row="${key}"]`).forEach(row => { row.hidden = expanded; });
                    });
                });
                grid.querySelectorAll('[data-daily-expand]').forEach(button => {
                    const index = Number(button.dataset.dailyExpand);
                    const setExpanded = nextExpanded => {
                        const labelRow = grid.querySelector(`.tl-daily-label-row[data-daily-index="${index}"]`);
                        const timelineRow = grid.querySelector(`.tl-row[data-tl-row="updates"][data-daily-index="${index}"]`);
                        const extras = timelineRow?.querySelectorAll('.is-daily-extra') || [];
                        extras.forEach(item => { item.hidden = !nextExpanded; });
                        if (nextExpanded) {
                            expandedDailyUpdates.add(index);
                        } else {
                            expandedDailyUpdates.delete(index);
                        }
                        button.setAttribute('aria-expanded', String(nextExpanded));
                        button.innerHTML = `<span>${nextExpanded ? 'Hide' : `${extras.length + 1} events`}</span><i class="bi bi-chevron-down"></i>`;
                        button.setAttribute('aria-label', `${nextExpanded ? 'Hide' : 'Show'} ${extras.length + 1} activities`);
                        const eventsContainer = timelineRow?.querySelector('.tl-daily-events');
                        const applyRowHeight = () => {
                            const measuredContentHeight = eventsContainer
                                ? Math.ceil(eventsContainer.getBoundingClientRect().height)
                                : 0;
                            const hasWaitingBadge = Boolean(timelineRow?.querySelector('.tl-daily-waiting--below'));
                            const collapsedHeight = hasWaitingBadge ? 82 : 62;
                            const newHeight = nextExpanded
                                ? Math.max(collapsedHeight, measuredContentHeight + 20)
                                : collapsedHeight;
                            if (labelRow) labelRow.style.minHeight = `${newHeight}px`;
                            if (timelineRow) timelineRow.style.minHeight = `${newHeight}px`;
                        };
                        applyRowHeight();
                    };
                    button.addEventListener('click', () => {
                        setExpanded(!expandedDailyUpdates.has(index));
                    });
                    if (expandedDailyUpdates.has(index)) setExpanded(true);
                });
                grid.querySelectorAll('[data-task-expand]').forEach(button => {
                    const index = Number(button.dataset.taskExpand);
                    const setExpanded = nextExpanded => {
                        const labelRow = grid.querySelector(`.tl-task-label-row[data-task-activity-index="${index}"]`);
                        const timelineRow = grid.querySelector(`.tl-row[data-tl-row="task"][data-task-activity-index="${index}"]`);
                        const extras = timelineRow?.querySelectorAll('.is-task-extra') || [];
                        extras.forEach(item => { item.hidden = !nextExpanded; });
                        if (nextExpanded) {
                            expandedTaskActivities.add(index);
                        } else {
                            expandedTaskActivities.delete(index);
                        }
                        button.setAttribute('aria-expanded', String(nextExpanded));
                        button.innerHTML = `<span>${nextExpanded ? 'Hide' : `${extras.length + 1} events`}</span><i class="bi bi-chevron-down"></i>`;
                        button.setAttribute('aria-label', `${nextExpanded ? 'Hide' : 'Show'} ${extras.length + 1} task activities`);
                        const eventsContainer = timelineRow?.querySelector('.tl-task-events');
                        const applyRowHeight = () => {
                            const measuredContentHeight = eventsContainer
                                ? Math.ceil(eventsContainer.getBoundingClientRect().height)
                                : 0;
                            const newHeight = nextExpanded ? Math.max(62, measuredContentHeight + 20) : 62;
                            if (labelRow) labelRow.style.minHeight = `${newHeight}px`;
                            if (timelineRow) timelineRow.style.minHeight = `${newHeight}px`;
                        };
                        applyRowHeight();
                    };
                    button.addEventListener('click', () => {
                        setExpanded(!expandedTaskActivities.has(index));
                    });
                    if (expandedTaskActivities.has(index)) setExpanded(true);
                });
                grid.querySelectorAll('[data-subtask-expand]').forEach(button => {
                    const index = Number(button.dataset.subtaskExpand);
                    const setExpanded = nextExpanded => {
                        const labelRow = grid.querySelector(`.tl-task-label-row[data-subtask-activity-index="${index}"]`);
                        const timelineRow = grid.querySelector(`.tl-row[data-tl-row="subtasks"][data-subtask-activity-index="${index}"]`);
                        const extras = timelineRow?.querySelectorAll('.is-subtask-extra') || [];
                        extras.forEach(item => { item.hidden = !nextExpanded; });
                        if (nextExpanded) {
                            expandedSubtaskActivities.add(index);
                        } else {
                            expandedSubtaskActivities.delete(index);
                        }
                        button.setAttribute('aria-expanded', String(nextExpanded));
                        button.innerHTML = `<span>${nextExpanded ? 'Hide' : `${extras.length + 1} events`}</span><i class="bi bi-chevron-down"></i>`;
                        button.setAttribute('aria-label', `${nextExpanded ? 'Hide' : 'Show'} ${extras.length + 1} subtask activities`);
                        const eventsContainer = timelineRow?.querySelector('.tl-task-events');
                        const applyRowHeight = () => {
                            const measuredContentHeight = eventsContainer
                                ? Math.ceil(eventsContainer.getBoundingClientRect().height)
                                : 0;
                            const newHeight = nextExpanded ? Math.max(62, measuredContentHeight + 20) : 62;
                            if (labelRow) labelRow.style.minHeight = `${newHeight}px`;
                            if (timelineRow) timelineRow.style.minHeight = `${newHeight}px`;
                        };
                        applyRowHeight();
                    };
                    button.addEventListener('click', () => {
                        setExpanded(!expandedSubtaskActivities.has(index));
                    });
                    if (expandedSubtaskActivities.has(index)) setExpanded(true);
                });
            };

            if ('ResizeObserver' in window) {
                let lastTimelineWidth = timeline.getBoundingClientRect().width;
                new ResizeObserver(entries => {
                    const timelineWidth = entries[0]?.contentRect.width;
                    if (timelineWidth !== undefined && Math.abs(timelineWidth - lastTimelineWidth) >= 1) {
                        lastTimelineWidth = timelineWidth;
                        scheduleRender();
                    }
                }).observe(timeline);
            } else {
                window.addEventListener('resize', scheduleRender);
            }
            render();
            window.requestAnimationFrame(() => {
                const index = dayIndex(model.range.today);
                scroller.scrollTo({ left: Math.max(0, (index - 2) * baseCellWidth), behavior: 'smooth' });
            });
        })();
    </script>

    @include('tasks._reassign-modal')
@endsection

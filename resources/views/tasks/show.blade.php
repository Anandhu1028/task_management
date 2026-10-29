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
                'text' => 'assigned the task to '.$assignedUser,
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

        foreach ($task->statusHistory as $history) {
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
                    ? 'changed status from '.$oldStatus.' to '.$newStatus
                    : 'marked the task '.$newStatus,
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
            ->filter(fn (array $event): bool => $event['at'] !== null)
            ->sortByDesc('at')
            ->take(10)
            ->values();

        $priorityTone = [
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
        ][$task->priority] ?? 'default';

        $hasReworkStage = $status === 'rework' || $task->statusHistory->contains('new_status', 'rework');
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
            'notified'             => $task->created_at,
            'unassigned'           => null,
            'assigned'             => null,
            'in_progress'          => null,
            'submitted_for_review' => null,
            'rework'               => null,
            'completed'            => null,
        ];
        foreach ($task->statusHistory->sortBy('created_at') as $h) {
            $ns = $h->new_status;
            // map on_hold to in_progress slot
            if ($ns === 'on_hold') { $ns = 'in_progress'; }
            if (array_key_exists($ns, $stageDateMap) && $stageDateMap[$ns] === null) {
                $stageDateMap[$ns] = $h->created_at;
            }
        }
    @endphp

    <div class="tf-task-detail">
        @php
            $hasActions = ! $canReviewSubmission && ($canAcceptTask || $canStartWork || $canAddWorkUpdate || $canResumeWork
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
                            <button type="submit" name="decision" value="approve" class="tf-wf-action-btn tf-wf-action-btn--primary">
                                <i class="bi bi-check-circle" aria-hidden="true"></i> Approve
                            </button>
                        </form>
                        <form method="POST" action="{{ route('tasks.review', $task) }}" class="tf-wf-action-form">
                            @csrf
                            <button type="submit" name="decision" value="rework" class="tf-wf-action-btn tf-wf-action-btn--primary">
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
                            <button type="button" class="tf-wf-action-btn tf-wf-action-btn--review" data-wf-toggle="top-submit-review">
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
                        @if (isset($statusOptions['on_hold']) && ! $canReviewSubmission)
                            <button type="button" class="tf-wf-action-btn tf-wf-action-btn--hold" data-wf-toggle="top-hold">
                                <i class="bi bi-pause-circle" aria-hidden="true"></i> On Hold
                            </button>
                        @endif
                        @if (isset($statusOptions['submitted_for_review']) && ! $resumeToReview && $task->status !== 'rework')
                            <button type="button" class="tf-wf-action-btn tf-wf-action-btn--review" data-wf-toggle="top-submit-review">
                                <i class="bi bi-check-circle" aria-hidden="true"></i> Work Done
                            </button>
                        @endif
                        @if (isset($statusOptions['cancelled']) && ! $canReviewSubmission)
                            <button type="button" class="tf-wf-action-btn tf-wf-action-btn--cancel" data-wf-toggle="top-cancel">
                                <i class="bi bi-x-circle" aria-hidden="true"></i> Cancel Task
                            </button>
                        @endif
                    </div>
                @endif
                @can('tasks.update')
                    @if (! $canReviewSubmission && ! in_array($task->status, ['notified', 'completed', 'cancelled'], true))
                        <button
                            type="button"
                            class="tf-wf-action-btn tf-wf-action-btn--primary"
                            data-reassign-trigger
                            data-task-id="{{ $task->id }}"
                            data-task-code="{{ $task->task_code }}"
                            data-task-title="{{ $task->title }}"
                            data-task-company-id="{{ $task->company_id }}"
                            data-task-company-name="{{ $task->company?->name }}"
                            data-task-department-id="{{ $task->department_id }}"
                            data-task-department-name="{{ $task->department?->name }}"
                            data-task-assignee-ids="{{ json_encode($currentAssignees->pluck('id')->all()) }}"
                            data-task-assignee-name="{{ $assigneeNames ?: 'Unassigned' }}"
                            data-reassign-action="{{ route('tasks.reassign', $task) }}"
                            @if (session('auto_open_reassign') && $currentAssignees->isEmpty()) data-reassign-auto-open @endif
                        >
                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                            Re-assign
                        </button>
                    @endif
                    @if (! $canReviewSubmission)
                        <a href="{{ route('tasks.index', ['edit' => $task->id]) }}" class="tf-wf-action-btn tf-wf-action-btn--primary">
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
                <p>{{ $task->company?->name ?? 'No company' }} <span aria-hidden="true">·</span> {{ $task->department?->name ?? 'No department' }}</p>
                <div class="tf-task-detail__summary-badges" aria-label="Task status details">
                    <div class="tf-task-detail__summary-badge tf-task-detail__summary-badge--workflow">
                        <span class="tf-task-detail__summary-badge-label"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Workflow</span>
                        <strong>{{ $statusLabels[$status] ?? $status }}</strong>
                    </div>
                    <div class="tf-task-detail__summary-badge tf-task-detail__summary-badge--start">
                        <span class="tf-task-detail__summary-badge-label"><i class="bi bi-calendar2-check" aria-hidden="true"></i> Planned start</span>
                        <strong>{{ $task->start_date?->format('M d, Y') ?? 'Not started' }}</strong>
                    </div>
                    <div class="tf-task-detail__summary-badge tf-task-detail__summary-badge--deadline">
                        <span class="tf-task-detail__summary-badge-label"><i class="bi bi-alarm" aria-hidden="true"></i> Deadline health</span>
                        <strong class="tf-task-status tf-task-status--{{ $deadlineCondition }}">{{ \App\Models\Task::DEADLINE_CONDITION_LABELS[$deadlineCondition] }}</strong>
                        <small>
                            @if ($daysUntilDeadline === null)
                                No deadline set
                            @elseif ($daysUntilDeadline < 0 && ! in_array($status, ['completed', 'cancelled'], true))
                                {{ abs($daysUntilDeadline) }} {{ \Illuminate\Support\Str::plural('day', abs($daysUntilDeadline)) }} overdue
                            @elseif ($daysUntilDeadline === 0)
                                Due today
                            @else
                                {{ $daysUntilDeadline }} {{ \Illuminate\Support\Str::plural('day', $daysUntilDeadline) }} left
                            @endif
                        </small>
                    </div>
                    <div class="tf-task-detail__summary-badge tf-task-detail__summary-badge--effort">
                        <span class="tf-task-detail__summary-badge-label"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Estimated effort</span>
                        <strong>{{ $task->estimated_hours ? number_format((float) $task->estimated_hours, 2).' hours' : 'Not set' }}</strong>
                    </div>
                </div>
            </div>
            <section class="tf-task-detail__workflow-progress" aria-labelledby="task-workflow-title-top">
                <header class="tf-task-detail__workflow-progress-header">
                    <h2 id="task-workflow-title-top"><i class="bi bi-diagram-3" aria-hidden="true"></i> Task Workflow</h2>
                    <span class="tf-task-detail__count">{{ $statusLabels[$task->status] ?? $task->status }}</span>
                </header>
                @if ($status === 'cancelled')
                    <p class="tf-task-workflow__terminal-state"><i class="bi bi-x-circle" aria-hidden="true"></i> This task was cancelled.</p>
                @else
                    <ol class="tf-task-workflow__steps" aria-label="Workflow progress">
                        @foreach ($workflowStages as $index => $stage)
                            @php
                                $isCompleteStage = $status === 'completed' || ($currentWorkflowStage !== null && $index < $currentWorkflowStage);
                                $isCurrentStage = $status !== 'completed' && $index === $currentWorkflowStage;
                                $stageLabel = $status === 'on_hold' && $isCurrentStage ? 'On Hold' : $stage['label'];
                                $stageDate = $stageDateMap[$stage['status']] ?? null;
                            @endphp
                            <li class="tf-task-workflow__step{{ $isCompleteStage ? ' is-complete' : '' }}{{ $isCurrentStage ? ' is-current' : '' }}">
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

        <div class="tf-task-detail__grid">
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
                                <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm" data-wf-close="top-accept">Cancel</button>
                            </div>
                        </form>
                    </div>
                @endif
                @if ($canStartWork && $task->status !== 'rework')
                    <div class="tf-wf-panel" id="top-start" hidden>
                        <form method="POST" action="{{ route('tasks.start', $task) }}" class="tf-wf-panel__form" enctype="multipart/form-data">
                            @csrf
                            <label for="topTaskStartNote">Start note <span>(required)</span></label>
                            <textarea class="form-control" id="topTaskStartNote" name="note" rows="2" maxlength="2000" required placeholder="Add a brief note about starting work"></textarea>
                            <label for="topTaskStartDate">Start date <span>(required)</span></label>
                            <input type="date" class="form-control" id="topTaskStartDate" name="start_date" value="{{ today()->format('Y-m-d') }}" required>
                            <label for="topTaskStartAttachments">Attachments <span>(optional)</span></label>
                            <input type="file" class="form-control" id="topTaskStartAttachments" name="attachments[]" multiple>
                            <div class="tf-wf-panel__footer">
                                <button type="submit" class="tf-btn tf-btn--primary tf-btn--sm">Mark In Progress</button>
                                <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm" data-wf-close="top-start">Cancel</button>
                            </div>
                        </form>
                    </div>
                @endif
                @if ($canAddWorkUpdate)
                    <div class="tf-wf-panel" id="top-update" hidden>
                        <form method="POST" action="{{ route('tasks.work-updates.store', $task) }}" class="tf-wf-panel__form tf-wf-panel__form--work-update" id="workUpdateForm">
                            @csrf
                            <label for="topTaskWorkUpdate">Work update</label>
                            <input type="text" class="form-control" id="topTaskWorkUpdate" name="note" maxlength="5000" required placeholder="Enter a short progress update">
                            <label for="topWorkUpdateDate">Date</label>
                            <input type="date" class="form-control" id="topWorkUpdateDate" name="update_date" value="{{ today()->format('Y-m-d') }}" required>
                            <div class="tf-wf-panel__footer">
                                <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm" data-wf-close="top-update">Cancel</button>
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
                            <textarea class="form-control" id="topTaskResumeNote" name="note" rows="2" maxlength="2000" placeholder="Add a note when resuming"></textarea>
                            <div class="tf-wf-panel__footer">
                                <button type="submit" class="tf-btn tf-btn--primary tf-btn--sm">Unhold</button>
                                <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm" data-wf-close="top-resume">Cancel</button>
                            </div>
                        </form>
                    </div>
                @endif
                @if (isset($statusOptions['on_hold']))
                    <div class="tf-wf-panel" id="top-hold" hidden>
                        <form method="POST" action="{{ route('tasks.status.update', $task) }}" class="tf-wf-panel__form" enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="status" value="on_hold">
                            <label for="topTaskHoldReason">Hold note <span>(required)</span></label>
                            <textarea class="form-control" id="topTaskHoldReason" name="reason" rows="2" maxlength="2000" required placeholder="Explain why work needs to pause"></textarea>
                            <label for="topTaskHoldDate">Date <span>(required)</span></label>
                            <input type="date" class="form-control" id="topTaskHoldDate" name="update_date" value="{{ today()->format('Y-m-d') }}" required>
                            <label for="topTaskHoldAttachments">Attachments <span>(optional)</span></label>
                            <input type="file" class="form-control" id="topTaskHoldAttachments" name="attachments[]" multiple>
                            <div class="tf-wf-panel__footer">
                                <button type="submit" class="tf-btn tf-btn--sm tf-task-workflow__action--hold">Confirm Hold</button>
                                <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm" data-wf-close="top-hold">Cancel</button>
                            </div>
                        </form>
                    </div>
                @endif
                @if (isset($statusOptions['submitted_for_review']) && ! $resumeToReview)
                    <div class="tf-wf-panel" id="top-submit-review" hidden>
                        <form method="POST" action="{{ route('tasks.status.update', $task) }}" class="tf-wf-panel__form" enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="status" value="submitted_for_review">
                            <label for="topTaskSubmissionNote">Work done note <span>(required)</span></label>
                            <textarea class="form-control" id="topTaskSubmissionNote" name="reason" rows="2" maxlength="2000" required placeholder="Summarize the completed work"></textarea>
                            <label for="topTaskSubmissionDate">Date <span>(required)</span></label>
                            <input type="date" class="form-control" id="topTaskSubmissionDate" name="update_date" value="{{ today()->format('Y-m-d') }}" required>
                            <label for="topTaskSubmissionFiles">Attachments <span>(optional)</span></label>
                            <input type="file" class="form-control" id="topTaskSubmissionFiles" name="attachments[]" multiple>
                            <div class="tf-wf-panel__footer">
                                <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm" data-wf-close="top-submit-review">Cancel</button>
                                <button type="submit" class="tf-btn tf-btn--primary tf-btn--sm">Submit for Review</button>
                            </div>
                        </form>
                    </div>
                @endif
                @if (isset($statusOptions['cancelled']) && ! $canReviewSubmission)
                    <div class="tf-wf-panel" id="top-cancel" hidden>
                        <form method="POST" action="{{ route('tasks.status.update', $task) }}" class="tf-wf-panel__form">
                            @csrf
                            <input type="hidden" name="status" value="cancelled">
                            <label for="topTaskCancelReason">Cancellation reason <span>(required)</span></label>
                            <textarea class="form-control" id="topTaskCancelReason" name="reason" rows="2" maxlength="2000" required placeholder="Explain why this task is being cancelled"></textarea>
                            <div class="tf-wf-panel__footer">
                                <button type="submit" class="tf-btn tf-btn--sm tf-task-workflow__action--cancel">Confirm Cancel</button>
                                <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm" data-wf-close="top-cancel">Keep Task</button>
                            </div>
                        </form>
                    </div>
                @endif
                @if ($canFlagRisk && ! $canReviewSubmission)
                    <div class="tf-wf-panel" id="top-risk" hidden>
                        <form method="POST" action="{{ route('tasks.risk.update', $task) }}" class="tf-wf-panel__form">
                            @csrf
                            @if ($task->risk_reason)
                                <p class="tf-task-workflow__risk-reason"><strong>Risk reason:</strong> {{ $task->risk_reason }}</p>
                                <input type="hidden" name="action" value="resolve">
                                <div class="tf-wf-panel__footer">
                                    <button type="submit" class="tf-btn tf-btn--sm tf-task-workflow__action--hold">Resolve Risk</button>
                                    <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm" data-wf-close="top-risk">Cancel</button>
                                </div>
                            @else
                                <input type="hidden" name="action" value="flag">
                                <label for="topTaskRiskReason">Risk description <span>(required)</span></label>
                                <textarea class="form-control" id="topTaskRiskReason" name="reason" rows="2" maxlength="2000" required placeholder="Describe the deadline or delivery risk"></textarea>
                                <div class="tf-wf-panel__footer">
                                    <button type="submit" class="tf-btn tf-btn--sm tf-task-workflow__action--hold">Flag as At Risk</button>
                                    <button type="button" class="tf-btn tf-btn--ghost tf-btn--sm" data-wf-close="top-risk">Cancel</button>
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
                    <button type="button" class="is-active" role="tab" aria-selected="true" aria-controls="task-details-overview" data-task-detail-tab="overview">
                        <i class="bi bi-grid" aria-hidden="true"></i> Overview
                    </button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="task-details-checklist" data-task-detail-tab="checklist">
                        <i class="bi bi-check2-square" aria-hidden="true"></i> Checklist
                    </button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="task-details-files" data-task-detail-tab="files">
                        <i class="bi bi-file-earmark" aria-hidden="true"></i> Files
                        <span class="tf-task-detail__file-tab-count">{{ $task->attachments->count() }}</span>
                    </button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="task-details-comments" data-task-detail-tab="comments">
                        <i class="bi bi-chat" aria-hidden="true"></i> Comments
                    </button>
                    <button type="button" role="tab" aria-selected="false" aria-controls="task-details-related" data-task-detail-tab="related">
                        <i class="bi bi-diagram-2" aria-hidden="true"></i> Related Tasks
                    </button>
                </nav>

                <div id="task-details-overview" role="tabpanel" data-task-detail-panel="overview">
                    {{-- In-tab workflow: hidden at desktop widths via CSS (only top-level visible) --}}

                    <section class="tf-task-detail__card" aria-labelledby="task-description-title">
                        <header class="tf-task-detail__card-header">
                            <h2 id="task-description-title"><i class="bi bi-file-text" aria-hidden="true"></i> Description &amp; Details</h2>
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
                                    <dd>{{ $task->estimated_hours ? number_format((float) $task->estimated_hours, 2).' hours' : '—' }}</dd>
                                </div>
                            </dl>
                            @if ($task->recurringTemplate)
                                @php
                                    $recurringTemplate = $task->recurringTemplate;
                                    $nextOccurrence = $recurringTemplate->nextOccurrenceDate();
                                @endphp
                                <section class="tf-task-detail__output-section" aria-labelledby="task-recurrence-title">
                                    <h3 class="tf-task-detail__section-label" id="task-recurrence-title">Recurring Schedule</h3>
                                    <dl class="tf-task-detail__metadata">
                                        <div>
                                            <dt>Record type</dt>
                                            <dd>Generated occurrence</dd>
                                        </div>
                                        <div>
                                            <dt>Schedule</dt>
                                            <dd>{{ ucfirst($recurringTemplate->repeat_frequency) }} · {{ \Illuminate\Support\Carbon::parse($recurringTemplate->start_time)->format('g:i A') }}–{{ \Illuminate\Support\Carbon::parse($recurringTemplate->end_time)->format('g:i A') }} ({{ $recurringTemplate->timezone }})</dd>
                                        </div>
                                        <div>
                                            <dt>Schedule dates</dt>
                                            <dd>{{ $recurringTemplate->start_date->format('M d, Y') }} – {{ $recurringTemplate->end_date->format('M d, Y') }}</dd>
                                        </div>
                                        <div>
                                            <dt>Source template</dt>
                                            <dd>{{ $recurringTemplate->title }}</dd>
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
                                        @can('update', $recurringTemplate)
                                            <div>
                                                <dt>Schedule control</dt>
                                                <dd>
                                                    <form method="POST" action="{{ route('recurring-tasks.status.update', $recurringTemplate) }}" onsubmit="return confirm('This changes future occurrence generation only. Existing tasks are not changed.');">
                                                        @csrf
                                                        @method('PATCH')
                                                        <select name="status" aria-label="Recurring schedule status" onchange="this.form.requestSubmit()">
                                                            @foreach (\App\Models\RecurringTaskTemplate::STATUSES as $scheduleStatus)
                                                                <option value="{{ $scheduleStatus }}" @selected($recurringTemplate->status === $scheduleStatus)>{{ ucfirst($scheduleStatus) }}</option>
                                                            @endforeach
                                                        </select>
                                                    </form>
                                                </dd>
                                            </div>
                                        @endcan
                                        <div>
                                            <dt>Template assignees</dt>
                                            <dd>{{ $recurringTemplate->assignees->pluck('name')->implode(', ') ?: 'Unassigned' }}</dd>
                                        </div>
                                    </dl>
                                </section>
                            @endif
                            <section class="tf-task-detail__output-section" aria-labelledby="task-output-title">
                                <h3 class="tf-task-detail__section-label" id="task-output-title">Expected Output / Deliverable Criteria</h3>
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
                                <h2 id="task-pocs-title"><i class="bi bi-people" aria-hidden="true"></i> Department Points of Contact</h2>
                                <span class="tf-task-detail__count">{{ $task->pocs->count() }} POC{{ $task->pocs->count() === 1 ? '' : 's' }}</span>
                            </header>
                            @if ($task->pocs->isNotEmpty())
                                <ul class="tf-task-detail__people-list">
                                    @foreach ($task->pocs as $poc)
                                        @php
                                            $pocInitials = collect(preg_split('/\s+/', trim($poc->name)))
                                                ->filter()
                                                ->take(2)
                                                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                                ->implode('');
                                        @endphp
                                        <li class="tf-task-detail__person">
                                            <span class="tf-task-detail__avatar tf-task-detail__avatar--poc">{{ $pocInitials ?: '?' }}</span>
                                            <span class="tf-task-detail__person-copy">
                                                <strong>{{ $poc->name }}</strong>
                                                <small>{{ $poc->designation ?: 'Department representative' }}@if ($poc->email) <span aria-hidden="true">·</span> {{ $poc->email }}@endif</small>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="tf-task-detail__card-body">
                                    <p class="tf-task-detail__empty-copy">No department points of contact linked to this task.</p>
                                </div>
                            @endif
                        </section>

                        <section class="tf-task-detail__card" aria-labelledby="task-assignment-title">
                            <header class="tf-task-detail__card-header">
                                <h2 id="task-assignment-title"><i class="bi bi-people" aria-hidden="true"></i> Assignment</h2>
                                <span class="tf-task-detail__count">{{ $currentAssignees->count() }} current · {{ $previousAssigneeCount }} previous</span>
                            </header>
                            @if ($task->assignments->isNotEmpty() || $currentAssignees->isNotEmpty())
                                <ul class="tf-task-detail__people-list tf-task-detail__people-list--assignments">
                                @foreach ($currentAssignees as $currentAssignee)
                                    @php
                                        $assignmentName = $currentAssignee->name;
                                        $assignmentInitials = collect(preg_split('/\s+/', trim($assignmentName)))
                                            ->filter()
                                            ->take(2)
                                            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
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
                                                    Assigned {{ \Illuminate\Support\Carbon::parse($currentAssignee->pivot->assigned_at)->format('M d, Y · g:i A') }}
                                                @endif
                                            </small>
                                            @if ($currentAssignee->pivot?->reason && $currentAssignee->pivot->reason !== 'Initial assignment')
                                                <small class="tf-task-detail__assignment-reason">Reason: {{ $currentAssignee->pivot->reason }}</small>
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
                                            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                            ->implode('');
                                    @endphp
                                    <li class="tf-task-detail__person tf-task-detail__person--assignment">
                                        <span class="tf-task-detail__avatar tf-task-detail__avatar--previous">{{ $assignmentInitials ?: '?' }}</span>
                                        <span class="tf-task-detail__person-copy">
                                            <span class="tf-task-detail__person-heading">
                                                <strong>{{ $assignmentName }}</strong>
                                                <span class="tf-task-detail__previous-badge">Previous</span>
                                            </span>
                                            <small>{{ $assignment->user?->designation ?: 'Team member' }} <span aria-hidden="true">·</span> Assigned {{ $assignment->assigned_at?->format('M d, Y · g:i A') ?? 'Date unavailable' }}</small>
                                            @if ($assignment->reason && $assignment->reason !== 'Initial assignment')
                                                <small class="tf-task-detail__assignment-reason">Reason: {{ $assignment->reason }}</small>
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

                <div id="task-details-checklist" class="tf-task-detail__empty-panel tf-task-detail__card" role="tabpanel" data-task-detail-panel="checklist" hidden>
                    <i class="bi bi-check2-square" aria-hidden="true"></i>
                    <strong>No checklist items</strong>
                    <p>Checklist items are not available for this task yet.</p>
                </div>
                <div id="task-details-files" role="tabpanel" data-task-detail-panel="files">
                    <section class="tf-task-detail__card" aria-labelledby="task-files-title">
                        <header class="tf-task-detail__card-header">
                            <h2 id="task-files-title"><i class="bi bi-paperclip" aria-hidden="true"></i> Task files</h2>
                            <span class="tf-task-detail__count">{{ $task->attachments->count() }} file{{ $task->attachments->count() === 1 ? '' : 's' }}</span>
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
                                            <a
                                                class="tf-task-detail__attachment-thumbnail"
                                                href="{{ $previewUrl }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                aria-label="View {{ $attachment->original_name }}"
                                            >
                                                <img src="{{ $previewUrl }}" alt="" loading="lazy">
                                            </a>
                                        @else
                                            <span class="tf-task-detail__attachment-icon" aria-hidden="true">
                                                <i class="bi {{ $attachment->mime_type === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark' }}"></i>
                                            </span>
                                        @endif
                                        <span class="tf-task-detail__attachment-copy">
                                            <strong>{{ $attachment->original_name }}</strong>
                                            <small>
                                                {{ $attachment->size_bytes >= 1048576 ? number_format($attachment->size_bytes / 1048576, 1).' MB' : number_format($attachment->size_bytes / 1024, 0).' KB' }}
                                                <span aria-hidden="true">·</span>
                                                Uploaded by {{ $attachment->uploadedBy?->name ?? 'Unknown user' }}
                                                <span aria-hidden="true">·</span>
                                                {{ $attachment->created_at?->format('M d, Y') ?? 'Date unavailable' }}
                                            </small>
                                        </span>
                                        <span class="tf-task-detail__attachment-actions" role="group" aria-label="Actions for {{ $attachment->original_name }}">
                                            @if ($isPreviewableAttachment)
                                                <a
                                                    class="tf-task-detail__attachment-action tf-task-detail__attachment-action--preview"
                                                    href="{{ $previewUrl }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    aria-label="Preview {{ $attachment->original_name }}"
                                                    title="Preview file"
                                                >
                                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                                </a>
                                            @endif
                                            <a
                                                class="tf-task-detail__attachment-action"
                                                href="{{ route('tasks.attachments.download', [$task, $attachment]) }}"
                                                aria-label="Download {{ $attachment->original_name }}"
                                                title="Download file"
                                            >
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
                <div id="task-details-comments" class="tf-task-detail__empty-panel tf-task-detail__card" role="tabpanel" data-task-detail-panel="comments" hidden>
                    <i class="bi bi-chat" aria-hidden="true"></i>
                    <strong>No comments yet</strong>
                    <p>There are no comments on this task.</p>
                </div>
                <div id="task-details-related" class="tf-task-detail__empty-panel tf-task-detail__card" role="tabpanel" data-task-detail-panel="related" hidden>
                    <i class="bi bi-diagram-2" aria-hidden="true"></i>
                    <strong>No related tasks</strong>
                    <p>There are no related tasks linked to this task.</p>
                </div>
            </div>

            <aside class="tf-task-detail__sidebar" aria-label="Task activity and subtasks">
                {{-- Separate Tab Card --}}
                <div class="tf-task-detail__card tf-activity-tabs-card">
                    <nav class="tf-activity-tabs-nav" role="tablist" aria-label="Task Activity and Subtasks">
                        <button
                            type="button"
                            class="tf-activity-tab-btn is-active"
                            id="tab-timeline-btn"
                            data-activity-tab="timeline"
                            role="tab"
                            aria-selected="true"
                            aria-controls="panel-timeline"
                        >
                            <i class="bi bi-clock-history" aria-hidden="true"></i> Timeline
                            <span class="tf-activity-tab-badge">{{ $activity->count() }}</span>
                        </button>
                        <button
                            type="button"
                            class="tf-activity-tab-btn"
                            id="tab-subtasks-btn"
                            data-activity-tab="subtasks"
                            role="tab"
                            aria-selected="false"
                            aria-controls="panel-subtasks"
                        >
                            <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i> Subtask
                            <span class="tf-activity-tab-badge" id="tab-subtasks-count-badge">{{ $task->subtasks->count() }}</span>
                        </button>
                        <button
                            type="button"
                            class="tf-activity-tab-btn"
                            id="tab-requests-btn"
                            data-activity-tab="requests"
                            role="tab"
                            aria-selected="false"
                            aria-controls="panel-requests"
                        >
                            <i class="bi bi-calendar2-plus" aria-hidden="true"></i> Requests
                            <span class="tf-activity-tab-badge">{{ $deadlineExtensionRequests->count() }}</span>
                        </button>
                    </nav>
                </div>

                    {{-- ── TAB 2: SUBTASKS VIEW ── --}}
                    <div class="tf-activity-tab-panel" id="panel-subtasks" role="tabpanel" aria-labelledby="tab-subtasks-btn" hidden>
                        @php
                            $subtaskTotal = $task->subtasks->count();
                            $subtaskDone  = $task->subtasks->where('is_completed', true)->count();
                            $subtaskPct   = $subtaskTotal > 0 ? (int) round($subtaskDone / $subtaskTotal * 100) : 0;

                            // Gantt Date Range Computation
                            $ganttMin = null;
                            $ganttMax = null;
                            foreach ($task->subtasks as $st) {
                                if ($st->start_date) {
                                    $ganttMin = $ganttMin ? min($ganttMin, $st->start_date->copy()) : $st->start_date->copy();
                                }
                                if ($st->due_date) {
                                    $ganttMax = $ganttMax ? max($ganttMax, $st->due_date->copy()) : $st->due_date->copy();
                                }
                            }
                            if (! $ganttMin) {
                                $ganttMin = $task->start_date ? $task->start_date->copy() : today()->subDays(3);
                            }
                            if (! $ganttMax) {
                                $ganttMax = $task->due_date ? $task->due_date->copy() : today()->addDays(7);
                            }
                            $ganttMin = min($ganttMin, today()->subDays(3));
                            $ganttMax = max($ganttMax, today()->addDays(6));

                            $ganttDays = [];
                            $currGanttDay = $ganttMin->copy();
                            while ($currGanttDay->lte($ganttMax) && count($ganttDays) < 31) {
                                $ganttDays[] = $currGanttDay->copy();
                                $currGanttDay->addDay();
                            }
                            $totalGanttDays = max(1, count($ganttDays));

                            $todayStr = today()->format('Y-m-d');
                            $todayIndex = null;
                            foreach ($ganttDays as $idx => $d) {
                                if ($d->format('Y-m-d') === $todayStr) {
                                    $todayIndex = $idx;
                                    break;
                                }
                            }
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
                                                <button type="button" class="tf-btn-add-subtask" id="subtask-add-btn" aria-expanded="false" aria-controls="subtask-quick-add-panel">
                                                    <i class="bi bi-plus-lg"></i> Add Subtask
                                                </button>
                                                <section class="tf-quick-add-panel" id="subtask-quick-add-panel" aria-labelledby="quick-add-heading" hidden>
                                            <div class="tf-quick-add-panel__header">
                                                <div>
                                                    <h4 id="quick-add-heading">New subtask</h4>
                                                    <p>Add a subtask and its details.</p>
                                                </div>
                                                <button type="button" class="tf-quick-add-panel__close" id="quick-add-cancel-btn" aria-label="Close">
                                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                            <div id="quick-add-alert" class="tf-quick-add-panel__alert" role="alert" hidden></div>
                                            <form method="POST" action="{{ route('subtasks.store', $task) }}" id="subtask-quick-add-form" class="tf-quick-add-form">
                                                @csrf
                                                <div class="tf-quick-add-fields">
                                                    <label class="tf-quick-add-field tf-quick-add-field--full">
                                                        <span>Title <em>*</em></span>
                                                        <input
                                                            type="text"
                                                            name="title"
                                                            id="quick-add-title"
                                                            class="form-control form-control-sm"
                                                            placeholder="Enter subtask title"
                                                            required
                                                            maxlength="500"
                                                            autocomplete="off"
                                                        >
                                                    </label>
                                                    <label class="tf-quick-add-field tf-quick-add-field--full">
                                                        <span>Description</span>
                                                        <textarea
                                                            name="description"
                                                            id="quick-add-desc"
                                                            class="form-control form-control-sm"
                                                            placeholder="Short description or details"
                                                            rows="2"
                                                            maxlength="1000"
                                                        ></textarea>
                                                    </label>
                                                    <label class="tf-quick-add-field tf-quick-add-field--full">
                                                        <span>Assignee</span>
                                                        <select name="assigned_to" class="form-select form-select-sm" aria-label="Assign to">
                                                            <option value="">Unassigned</option>
                                                            @foreach ($employees->where('company_id', $task->company_id)->where('department_id', $task->department_id) as $emp)
                                                                <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <label class="tf-quick-add-field">
                                                        <span>Start date</span>
                                                        <input type="date" name="start_date" class="form-control form-control-sm" aria-label="Start date">
                                                    </label>
                                                    <label class="tf-quick-add-field">
                                                        <span>Due date</span>
                                                        <input
                                                            type="date"
                                                            name="due_date"
                                                            class="form-control form-control-sm"
                                                            aria-label="Due date"
                                                            @if ($task->due_date) max="{{ $task->due_date->format('Y-m-d') }}" @endif
                                                        >
                                                    </label>
                                                    <label class="tf-quick-add-field">
                                                        <span>Priority</span>
                                                        <select name="priority" class="form-select form-select-sm" aria-label="Priority">
                                                            <option value="medium" selected>Medium</option>
                                                            <option value="high">High</option>
                                                            <option value="critical">Critical</option>
                                                            <option value="low">Low</option>
                                                        </select>
                                                    </label>
                                                </div>
                                                <div class="tf-quick-add-actions">
                                                    <button type="button" class="btn btn-sm btn-light" id="quick-add-cancel-footer">Cancel</button>
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
                                        <div class="tf-subtask-card__progress-bar-fill" id="subtasks-progress-fill" style="width: {{ $subtaskPct }}%"></div>
                                    </div>
                                    <span class="tf-subtask-card__progress-text" id="subtasks-progress-pct">{{ $subtaskPct }}%</span>
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
                                                    $stDue   = $subtask->due_date;
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
                                                <tr
                                                    class="tf-subtasks__item tf-subtask-row{{ $subtask->is_completed ? ' is-done' : '' }}"
                                                    id="subtask-{{ $subtask->id }}"
                                                    data-subtask-id="{{ $subtask->id }}"
                                                    data-status="{{ $stStatus }}"
                                                    data-subtask-url="{{ route('subtasks.show', [$task, $subtask]) }}"
                                                    data-update-url="{{ route('subtasks.update', [$task, $subtask]) }}"
                                                    data-delete-url="{{ route('subtasks.destroy', [$task, $subtask]) }}"
                                                    draggable="true"
                                                >
                                                    <td class="col-subtask">
                                                        <div class="tf-st-subtask-cell">
                                                            <div class="tf-st-title-desc">
                                                                <span
                                                                    class="tf-subtasks__title tf-st-title"
                                                                    contenteditable="false"
                                                                    data-update-url="{{ route('subtasks.update', [$task, $subtask]) }}"
                                                                    data-original="{{ e($subtask->title) }}"
                                                                    title="Double-click to inline edit"
                                                                >{{ $subtask->title }}</span>
                                                                <div class="tf-st-description" title="{{ $subtask->description ?: 'No details provided.' }}">
                                                                    {{ $subtask->description ?: 'No details provided.' }}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="col-assignee">
                                                        @if ($subtask->assignee)
                                                            <div class="tf-st-assignee" title="Assigned to {{ $subtask->assignee->name }}">
                                                                <span class="tf-st-avatar" style="background-color: {{ $avatarBg }};">{{ $assigneeInitial }}</span>
                                                                <span class="tf-st-assignee-name">{{ Str::limit($subtask->assignee->name, 16) }}</span>
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
                                                                <span class="tf-st-dates">From {{ $stStart->format('M d') }} to {{ $stDue->format('M d') }}</span>
                                                                <span class="tf-st-duration-badge">{{ $stDurationDays }} {{ Str::plural('day', $stDurationDays) }}</span>
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
                                                        <span class="tf-st-priority-badge tf-subtasks__meta tf-subtasks__priority--{{ $subtask->priority ?? 'medium' }}">
                                                            {{ ucfirst($subtask->priority ?? 'medium') }}
                                                        </span>
                                                    </td>
                                                    <td class="col-status">
                                                        <span class="tf-st-status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
                                                    </td>
                                                    <td class="col-actions">
                                                        <div class="tf-st-actions-wrap">
                                                            <button
                                                                type="button"
                                                                class="tf-st-action-btn tf-subtask-status-trigger"
                                                                data-subtask-url="{{ route('subtasks.show', [$task, $subtask]) }}"
                                                                data-update-url="{{ route('subtasks.update', [$task, $subtask]) }}"
                                                                aria-haspopup="menu"
                                                                aria-expanded="false"
                                                                aria-controls="subtaskActionsMenu"
                                                                aria-label="Update subtask status"
                                                                title="Update subtask status"
                                                            >
                                                                <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr id="subtasks-empty-row">
                                                    <td colspan="6" class="text-center py-4 text-muted">
                                                        <i class="bi bi-check2-square d-block fs-3 mb-2 text-primary opacity-50"></i>
                                                        No subtasks created yet. Click <strong>+ Add Subtask</strong> above to create one.
                                                    </td>
                                                </tr>
                                            @endforelse

                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {{-- Card 2: Subtask Timeline (Gantt View) --}}
                            <div class="tf-subtask-card tf-gantt-card mt-4">
                                <div class="tf-gantt-card__header">
                                    <span class="tf-gantt-card__icon" aria-hidden="true">
                                        <i class="bi bi-calendar2-range"></i>
                                    </span>
                                    <h3 class="tf-gantt-card__title">Subtask Timeline (Gantt View)</h3>
                                </div>

                                <div class="tf-gantt-scroll-container">
                                    <div class="tf-gantt-chart">
                                        {{-- Date Header Row --}}
                                        <div class="tf-gantt-row tf-gantt-row--header">
                                            <div class="tf-gantt-col-name tf-gantt-col-name--header">
                                                <span>Subtask</span>
                                            </div>
                                            <div class="tf-gantt-timeline-area">
                                                <div class="tf-gantt-date-grid">
                                                    @foreach ($ganttDays as $gDay)
                                                        <div class="tf-gantt-date-cell{{ $gDay->format('Y-m-d') === $todayStr ? ' is-today' : '' }}">
                                                            {{ $gDay->format('M j') }}
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Subtask Rows --}}
                                        <div class="tf-gantt-body">
                                            @foreach ($task->subtasks->sortBy('sort_order') as $subtask)
                                                @php
                                                    $stStart = $subtask->start_date ? $subtask->start_date->copy() : null;
                                                    $stDue   = $subtask->due_date ? $subtask->due_date->copy() : null;
                                                    if ($stStart && $stDue) {
                                                        $dur = max(1, $stStart->diffInDays($stDue) + 1);
                                                    } elseif ($subtask->estimated_hours) {
                                                        $dur = max(1, (int) round($subtask->estimated_hours / 8));
                                                    } else {
                                                        $dur = 2;
                                                    }

                                                    if ($stStart) {
                                                        $sIdx = max(0, $ganttMin->diffInDays($stStart, false));
                                                    } elseif ($stDue) {
                                                        $sIdx = max(0, $ganttMin->diffInDays($stDue->copy()->subDays($dur - 1), false));
                                                    } else {
                                                        $sIdx = $todayIndex !== null ? max(0, $todayIndex) : 1;
                                                    }
                                                    $spDays = min($totalGanttDays - $sIdx, max(1, $dur));

                                                    $bLeft = ($sIdx / $totalGanttDays) * 100;
                                                    $bWidth = ($spDays / $totalGanttDays) * 100;

                                                    $stStatus = $subtask->status ?? ($subtask->is_completed ? 'completed' : 'todo');
                                                    $barClass = match($stStatus) {
                                                        'completed' => 'tf-gantt-bar--completed',
                                                        'in_progress' => 'tf-gantt-bar--in-progress',
                                                        'on_hold' => 'tf-gantt-bar--not-started',
                                                        default => 'tf-gantt-bar--pending',
                                                    };
                                                @endphp
                                                <div class="tf-gantt-row" id="gantt-row-{{ $subtask->id }}">
                                                    <div class="tf-gantt-col-name" title="{{ $subtask->title }}">
                                                        <i class="bi bi-briefcase me-2 text-muted"></i>
                                                        <span class="tf-gantt-name-text">{{ $subtask->title }}</span>
                                                    </div>
                                                    <div class="tf-gantt-timeline-area">
                                                        <div class="tf-gantt-grid-lines">
                                                            @foreach ($ganttDays as $gDay)
                                                                <div class="tf-gantt-grid-line{{ $gDay->format('Y-m-d') === $todayStr ? ' is-today' : '' }}"></div>
                                                            @endforeach
                                                        </div>
                                                        <div
                                                            class="tf-gantt-bar {{ $barClass }}"
                                                            style="left: {{ $bLeft }}%; width: {{ $bWidth }}%;"
                                                            title="{{ $subtask->title }}: {{ $dur }} {{ Str::plural('day', $dur) }}"
                                                        >
                                                            <span class="tf-gantt-bar-label">{{ $dur }} {{ Str::plural('day', $dur) }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach

                                            {{-- Vertical Today Line --}}
                                            @if ($todayIndex !== null)
                                                @php
                                                    $todayColLeft = ($todayIndex / $totalGanttDays * 100) + (100 / $totalGanttDays / 2);
                                                @endphp
                                                <div class="tf-gantt-today-line" style="left: calc({{ $todayColLeft }}% + 220px);">
                                                    <div class="tf-gantt-today-tag">
                                                        <strong>Today</strong>
                                                        <small>{{ today()->format('M j') }}</small>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                {{-- Gantt Legend --}}
                                <div class="tf-gantt-legend">
                                    <div class="tf-gantt-legend-item">
                                        <span class="tf-gantt-legend-color tf-gantt-legend--completed"></span>
                                        <span>Completed</span>
                                    </div>
                                    <div class="tf-gantt-legend-item">
                                        <span class="tf-gantt-legend-color tf-gantt-legend--in-progress"></span>
                                        <span>In Progress</span>
                                    </div>
                                    <div class="tf-gantt-legend-item">
                                        <span class="tf-gantt-legend-color tf-gantt-legend--pending"></span>
                                        <span>Pending</span>
                                    </div>
                                    <div class="tf-gantt-legend-item">
                                        <span class="tf-gantt-legend-color tf-gantt-legend--not-started"></span>
                                        <span>Not Started</span>
                                    </div>
                                </div>
                            </div>
                    </div>

                    {{-- ── TAB 1: TIMELINE VIEW ── --}}
                    <div class="tf-activity-tab-panel is-active" id="panel-timeline" role="tabpanel" aria-labelledby="tab-timeline-btn">
                        <section class="tf-task-detail__card tf-task-detail__activity-card">
                            <header class="tf-task-detail__activity-header">
                            <div class="tf-task-detail__activity-heading">
                                <div>
                                    <span>Task activity</span>
                                    <small>Recent updates and history</small>
                                </div>
                                @if ($deadline)
                                    @php
                                        $isOverdue = $daysUntilDeadline !== null && $daysUntilDeadline < 0 && ! in_array($status, ['completed', 'cancelled'], true);
                                        $isDueToday = $daysUntilDeadline === 0 && ! in_array($status, ['completed', 'cancelled'], true);
                                        $isCompleted = $status === 'completed';
                                        $deadlineToneClass = $isCompleted ? 'is-completed' : ($isOverdue ? 'is-overdue' : ($isDueToday ? 'is-due-today' : ''));
                                    @endphp
                                    <article class="tf-task-detail__deadline-event {{ $deadlineToneClass }}">
                                        <span class="tf-task-detail__deadline-icon">
                                            <i class="bi {{ $isCompleted ? 'bi-check2' : ($isOverdue ? 'bi-exclamation-triangle' : ($isDueToday ? 'bi-alarm' : 'bi-calendar-event')) }}" aria-hidden="true"></i>
                                        </span>
                                        <span class="tf-task-detail__deadline-text">
                                            <strong>Due:</strong>
                                            <time datetime="{{ $deadline->toIso8601String() }}">{{ $deadline->format('M d, Y') }}</time>
                                        </span>
                                        <small class="{{ $deadlineToneClass }}">
                                            @if ($isCompleted)
                                                Completed
                                            @elseif ($isOverdue)
                                                {{ abs($daysUntilDeadline) }} {{ \Illuminate\Support\Str::plural('day', abs($daysUntilDeadline)) }} overdue
                                            @elseif ($isDueToday)
                                                Due today
                                            @else
                                                {{ $daysUntilDeadline }} {{ \Illuminate\Support\Str::plural('day', $daysUntilDeadline) }} left
                                            @endif
                                        </small>
                                    </article>
                                @endif
                            </div>
                        </header>

                        <div id="task-activity-timeline" role="tabpanel" data-task-activity-panel="timeline">
                            @if ($activity->isNotEmpty())
                                <ol class="tf-task-detail__timeline">
                                    @foreach ($activity as $event)
                                        @php
                                            $activityIcon = match ($event['tone']) {
                                                'hold' => 'bi-pause',
                                                'created' => 'bi-plus-lg',
                                                'assigned', 'reassigned' => 'bi-person-plus',
                                                'start' => 'bi-play-fill',
                                                'submission' => 'bi-send',
                                                'review' => 'bi-check-lg',
                                                'resume' => 'bi-arrow-repeat',
                                                'accepted' => 'bi-check2',
                                                'cancellation', 'cancelled' => 'bi-x-lg',
                                                'risk' => 'bi-exclamation-lg',
                                                'work_update' => 'bi-chat-left-text',
                                                'subtask' => 'bi-check2-square',
                                                'deadline_extension_requested', 'deadline_extension_approved', 'deadline_extension_rejected' => 'bi-calendar2-plus',
                                                default => 'bi-arrow-left-right',
                                            };
                                        @endphp
                                        <li class="tf-task-detail__timeline-item tf-task-detail__timeline-item--{{ $event['tone'] }}" data-task-activity-type="{{ $event['tone'] }}">
                                            <span class="tf-task-detail__timeline-marker" aria-hidden="true">
                                                <i class="bi {{ $activityIcon }}"></i>
                                            </span>
                                            <div class="tf-task-detail__timeline-content">
                                                <div class="tf-task-detail__timeline-heading">
                                                    <p>
                                                        <strong>{{ $event['actor'] }}</strong> {{ $event['text'] }}
                                                        @if ($event['badge'])
                                                            <span class="tf-task-detail__timeline-badge tf-task-detail__timeline-badge--{{ \Illuminate\Support\Str::slug($event['badge']) }}">{{ $event['badge'] }}</span>
                                                        @endif
                                                    </p>
                                                    <time datetime="{{ $event['at']->toIso8601String() }}">
                                                        <span>{{ $event['at']->format('M d, Y') }}</span>
                                                    </time>
                                                </div>
                                                @if ($event['detail'])
                                                    <span class="tf-task-detail__timeline-detail">{{ $event['detail'] }}</span>
                                                @endif
                                                @if ($event['attachments']->isNotEmpty())
                                                    <div class="tf-task-detail__timeline-attachments" aria-label="Activity attachments">
                                                        @foreach ($event['attachments'] as $attachment)
                                                            @php
                                                                $isImage = str_starts_with($attachment->mime_type ?? '', 'image/');
                                                                $attachmentUrl = $isImage
                                                                    ? route('tasks.attachments.preview', [$task, $attachment])
                                                                    : route('tasks.attachments.download', [$task, $attachment]);
                                                            @endphp
                                                            <a href="{{ $attachmentUrl }}" @if ($isImage) target="_blank" rel="noopener noreferrer" @endif>
                                                                <span class="tf-task-detail__timeline-attachment-icon" aria-hidden="true">
                                                                    @if ($isImage)
                                                                        <img src="{{ $attachmentUrl }}" alt="" loading="lazy">
                                                                    @else
                                                                        <i class="bi bi-file-earmark"></i>
                                                                    @endif
                                                                </span>
                                                                <span class="tf-task-detail__timeline-attachment-copy">
                                                                    <strong>{{ $attachment->original_name }}</strong>
                                                                    <small>{{ $isImage ? 'Image attachment' : 'File attachment' }}</small>
                                                                </span>
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            @else
                                <div class="tf-task-detail__card-body">
                                    <p class="tf-task-detail__empty-copy">No activity has been recorded for this task.</p>
                                </div>
                            @endif
                        </div>
                        </section>
                    </div>

                    <div class="tf-activity-tab-panel" id="panel-requests" role="tabpanel" aria-labelledby="tab-requests-btn" hidden>
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
                                    {{ $deadlineExtensionRequests->count() }} {{ $deadlineExtensionRequests->count() === 1 ? 'request' : 'requests' }}
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
                                                            <small class="tf-deadline-requests__review-note" title="{{ $extensionRequest->review_note }}">
                                                                {{ $extensionRequest->review_note }}
                                                            </small>
                                                        @endif
                                                    </td>
                                                    <td class="tf-deadline-requests__actions">
                                                        @can('review', $extensionRequest)
                                                            <form
                                                                method="POST"
                                                                action="{{ route('tasks.deadline-extension-requests.review', [$task, $extensionRequest]) }}"
                                                                class="tf-deadline-requests__review-form"
                                                            >
                                                                @csrf
                                                                <div class="tf-deadline-requests__review-actions">
                                                                    <button type="submit" name="decision" value="approve" class="tf-deadline-requests__button tf-deadline-requests__button--approve">
                                                                        <i class="bi bi-check2" aria-hidden="true"></i> Approve
                                                                    </button>
                                                                    <button type="submit" name="decision" value="reject" class="tf-deadline-requests__button tf-deadline-requests__button--reject">
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
        <button type="button" class="tf-subtask-actions-menu__item tf-subtask-actions-menu__item--danger" role="menuitem" data-subtask-action="delete">
            <i class="bi bi-trash3" aria-hidden="true"></i>
            Delete subtask
        </button>
    </div>
    @include('tasks._subtask-modal')

    {{-- Subtask interactions --}}
    <script>
    (function () {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

        // ── Activity Tabs (Subtask vs Timeline) ──────────────────────────────
        document.querySelectorAll('[data-activity-tab]').forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.activityTab;
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
            const list   = document.getElementById('subtasks-list');
            if (!list) { return; }
            const items  = list.querySelectorAll('.tf-subtasks__item');
            const done   = list.querySelectorAll('.tf-subtasks__item.is-done');
            const total  = items.length;
            const doneCount = done.length;
            const pct = total > 0 ? Math.round(doneCount / total * 100) : 0;

            const subtitle = document.getElementById('subtasks-count-subtitle');
            const tabBadge = document.getElementById('tab-subtasks-count-badge');
            const fill     = document.getElementById('subtasks-progress-fill');
            const pctText  = document.getElementById('subtasks-progress-pct');

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

            const ganttBar = document.getElementById(`gantt-row-${row.dataset.subtaskId}`)?.querySelector('.tf-gantt-bar');
            if (ganttBar) {
                const ganttStatusClass = subtask.is_completed
                    ? 'tf-gantt-bar--completed'
                    : (subtask.status === 'in_progress' ? 'tf-gantt-bar--in-progress' : 'tf-gantt-bar--pending');
                ganttBar.className = `tf-gantt-bar ${ganttStatusClass}`;
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

                const subtaskId = activeStatusRow.dataset.subtaskId;
                activeStatusRow.remove();
                document.getElementById(`gantt-row-${subtaskId}`)?.remove();
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
                    const subtaskId = row.dataset.subtaskId;
                    row.remove();
                    document.getElementById(`gantt-row-${subtaskId}`)?.remove();
                    updateSubtaskBadge();
                }
            })
            .catch(() => {});
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
                    const row = titleEl.closest('.tf-subtasks__item');
                    const subtaskId = row.dataset.subtaskId;
                    const ganttRow = document.getElementById(`gantt-row-${subtaskId}`);
                    const nameText = ganttRow?.querySelector('.tf-gantt-name-text');
                    if (nameText) { nameText.textContent = newTitle; }
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
                    }).catch(() => {});
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
            .catch(() => {});
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
            .catch(() => {});
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

    @include('tasks._reassign-modal')
@endsection

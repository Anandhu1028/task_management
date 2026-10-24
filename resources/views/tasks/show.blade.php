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

        <header class="tf-task-detail__header">
            <div class="tf-task-detail__heading">
               
                <h1>{{ $task->title }}</h1>
                <p>{{ $task->company?->name ?? 'No company' }} <span aria-hidden="true">·</span> {{ $task->department?->name ?? 'No department' }}</p>
            </div>

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

        <section class="tf-task-detail__summary" aria-label="Task summary">
            <div class="tf-task-detail__summary-item tf-task-detail__summary-item--start">
                <div class="tf-task-detail__summary-heading">
                    <span class="tf-task-detail__summary-icon" aria-hidden="true"><i class="bi bi-calendar2-check"></i></span>
                    <span>Planned start</span>
                </div>
                <strong>{{ $task->start_date?->format('M d, Y') ?? 'Not started' }}</strong>
                <small>{{ $task->status === 'in_progress' ? 'Work in progress' : 'Workflow: '.($statusLabels[$status] ?? $status) }}</small>
            </div>
            <div class="tf-task-detail__summary-item tf-task-detail__summary-item--deadline">
                <div class="tf-task-detail__summary-heading">
                    <span class="tf-task-detail__summary-icon" aria-hidden="true"><i class="bi bi-alarm"></i></span>
                    <span>Deadline health</span>
                </div>
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
            <div class="tf-task-detail__summary-item tf-task-detail__summary-item--effort">
                <div class="tf-task-detail__summary-heading">
                    <span class="tf-task-detail__summary-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
                    <span>Estimated effort</span>
                </div>
                <strong>{{ $task->estimated_hours ? number_format((float) $task->estimated_hours, 2).' hours' : 'Not set' }}</strong>
                <small>Task estimate</small>
            </div>
            <div class="tf-task-detail__summary-item tf-task-detail__summary-item--progress">
                <div class="tf-task-detail__summary-heading">
                    <span class="tf-task-detail__summary-icon" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span>
                    <span>Progress</span>
                </div>
                <div class="tf-task-detail__summary-progress-value">
                    <strong>{{ (int) $task->progress_percentage }}%</strong>
                    <small>Complete</small>
                </div>
                <div class="tf-task-detail__progress" role="progressbar" aria-label="Task progress" aria-valuenow="{{ (int) $task->progress_percentage }}" aria-valuemin="0" aria-valuemax="100">
                    <span style="width: {{ min(100, max(0, (int) $task->progress_percentage)) }}%"></span>
                </div>
            </div>
        </section>

        <div class="tf-task-detail__grid">
            <div class="tf-task-detail__workflow-actions">
                <section class="tf-task-detail__card tf-task-workflow" aria-labelledby="task-workflow-title-top">
                    <header class="tf-task-detail__card-header">
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

            {{-- Expandable Form Panels --}}
            @if ($hasActions || ($canReviewSubmission && isset($statusOptions['on_hold'])))
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
            @endif
        </div>

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

                    <section class="tf-task-detail__card" aria-labelledby="task-output-title">
                        <header class="tf-task-detail__card-header">
                            <h2 id="task-output-title"><i class="bi bi-bullseye" aria-hidden="true"></i> Expected Output / Deliverable Criteria</h2>
                        </header>
                        <div class="tf-task-detail__card-body">
                            @if ($task->expected_output)
                                <div class="tf-task-detail__output">{{ $task->expected_output }}</div>
                            @else
                                <p class="tf-task-detail__empty-copy">No specific expected output defined for this task.</p>
                            @endif
                        </div>
                    </section>

                    <section class="tf-task-detail__card" aria-labelledby="task-description-title">
                        <header class="tf-task-detail__card-header">
                            <h2 id="task-description-title"><i class="bi bi-file-text" aria-hidden="true"></i> Description &amp; Details</h2>
                        </header>
                        <div class="tf-task-detail__card-body">
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
                <section class="tf-task-detail__card tf-task-detail__activity-card tf-activity-wrapper-card">
                    {{-- Top Two Tabs: Subtask & Timeline --}}
                    <header class="tf-activity-tabs-header">
                        <nav class="tf-activity-tabs-nav" role="tablist" aria-label="Task Activity and Subtasks">
                            <button
                                type="button"
                                class="tf-activity-tab-btn is-active"
                                id="tab-subtasks-btn"
                                data-activity-tab="subtasks"
                                role="tab"
                                aria-selected="true"
                                aria-controls="panel-subtasks"
                            >
                                <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i> Subtask
                                <span class="tf-activity-tab-badge" id="tab-subtasks-count-badge">{{ $task->subtasks->count() }}</span>
                            </button>
                            <button
                                type="button"
                                class="tf-activity-tab-btn"
                                id="tab-timeline-btn"
                                data-activity-tab="timeline"
                                role="tab"
                                aria-selected="false"
                                aria-controls="panel-timeline"
                            >
                                <i class="bi bi-clock-history" aria-hidden="true"></i> Timeline
                                <span class="tf-activity-tab-badge">{{ $activity->count() }}</span>
                            </button>
                        </nav>
                    </header>

                    {{-- ── TAB 1: SUBTASKS VIEW (MATCHING USER SCREENSHOT) ── --}}
                    <div class="tf-activity-tab-panel is-active" id="panel-subtasks" role="tabpanel" aria-labelledby="tab-subtasks-btn">
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

                        <div class="tf-subtask-view-wrapper">
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
                                        <button type="button" class="tf-btn-add-subtask" id="subtask-add-btn">
                                            <i class="bi bi-plus-lg"></i> Add Subtask
                                        </button>
                                        <button type="button" class="tf-btn-expand-all" id="subtask-expand-btn">
                                            <i class="bi bi-arrows-angle-expand"></i> Expand All
                                        </button>
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
                                                <th class="col-num">#</th>
                                                <th class="col-subtask">Subtask</th>
                                                <th class="col-assignee">Assignee</th>
                                                <th class="col-timeframe">Timeframe</th>
                                                <th class="col-duration">Duration</th>
                                                <th class="col-status">Status</th>
                                                <th class="col-progress">Progress</th>
                                                <th class="col-actions">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="subtasks-list" data-reorder-url="{{ route('subtasks.reorder', $task) }}">
                                            @forelse ($task->subtasks->sortBy('sort_order') as $index => $subtask)
                                                @php
                                                    $stStart = $subtask->start_date;
                                                    $stDue   = $subtask->due_date;
                                                    $stDays  = ($stStart && $stDue)
                                                        ? max(1, $stStart->diffInDays($stDue) + 1)
                                                        : ($subtask->estimated_hours ? max(1, (int) round($subtask->estimated_hours / 8)) : 1);
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
                                                    $stProg = $subtask->is_completed ? 100 : (int) ($subtask->progress_percentage ?? 0);
                                                    $progColorClass = $stProg === 100 ? 'is-completed' : ($stProg > 0 ? 'is-in-progress' : 'is-zero');

                                                    $avatarColors = ['#3b82f6', '#6366f1', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#10b981'];
                                                    $assigneeId = $subtask->assignee?->id ?? 0;
                                                    $avatarBg = $avatarColors[$assigneeId % count($avatarColors)];
                                                    $assigneeInitial = $subtask->assignee ? mb_strtoupper(mb_substr($subtask->assignee->name, 0, 1)) : '?';
                                                @endphp
                                                <tr
                                                    class="tf-subtasks__item tf-subtask-row{{ $subtask->is_completed ? ' is-done' : '' }}"
                                                    id="subtask-{{ $subtask->id }}"
                                                    data-subtask-id="{{ $subtask->id }}"
                                                    data-subtask-url="{{ route('subtasks.show', [$task, $subtask]) }}"
                                                    data-update-url="{{ route('subtasks.update', [$task, $subtask]) }}"
                                                    draggable="true"
                                                >
                                                    <td class="col-num">
                                                        <span class="tf-row-idx">{{ $index + 1 }}</span>
                                                    </td>
                                                    <td class="col-subtask">
                                                        <div class="tf-st-subtask-cell">
                                                            <button
                                                                type="button"
                                                                class="tf-subtasks__toggle tf-st-toggle-btn {{ $subtask->is_completed ? 'is-completed' : ($stStatus === 'in_progress' ? 'is-in-progress' : 'is-pending') }}"
                                                                data-toggle-url="{{ route('subtasks.toggle', [$task, $subtask]) }}"
                                                                aria-label="{{ $subtask->is_completed ? 'Mark incomplete' : 'Mark complete' }}"
                                                                title="{{ $subtask->is_completed ? 'Mark incomplete' : 'Mark complete' }}"
                                                                aria-pressed="{{ $subtask->is_completed ? 'true' : 'false' }}"
                                                            >
                                                                <i class="bi {{ $subtask->is_completed ? 'bi-check-circle-fill' : ($stStatus === 'in_progress' ? 'bi-record-circle-fill' : 'bi-circle') }}"></i>
                                                            </button>
                                                            <div class="tf-st-title-desc">
                                                                <span
                                                                    class="tf-subtasks__title tf-st-title"
                                                                    contenteditable="false"
                                                                    data-update-url="{{ route('subtasks.update', [$task, $subtask]) }}"
                                                                    data-original="{{ e($subtask->title) }}"
                                                                    title="Double-click to inline edit"
                                                                >{{ $subtask->title }}</span>
                                                                <div class="tf-st-description">
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
                                                                <span class="tf-st-dates">{{ $stStart->format('M d') }} – {{ $stDue->format('M d') }}</span>
                                                                <small class="tf-st-subdays">({{ $stDays }} {{ Str::plural('day', $stDays) }})</small>
                                                            @elseif ($stDue)
                                                                <span class="tf-st-dates">Due {{ $stDue->format('M d') }}</span>
                                                                <small class="tf-st-subdays">({{ $stDays }} {{ Str::plural('day', $stDays) }})</small>
                                                            @else
                                                                <span class="text-muted">—</span>
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td class="col-duration">
                                                        <span class="tf-st-duration-badge">{{ $stDays }} {{ Str::plural('day', $stDays) }}</span>
                                                    </td>
                                                    <td class="col-status">
                                                        <span class="tf-st-status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
                                                    </td>
                                                    <td class="col-progress">
                                                        <div class="tf-st-progress-box">
                                                            <span class="tf-st-progress-pct">{{ $stProg }}%</span>
                                                            <div class="tf-st-mini-track">
                                                                <div class="tf-st-mini-fill {{ $progColorClass }}" style="width: {{ $stProg }}%"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="col-actions">
                                                        <div class="tf-st-actions-wrap">
                                                            <button
                                                                type="button"
                                                                class="tf-st-action-btn tf-subtasks__open-modal"
                                                                data-subtask-url="{{ route('subtasks.show', [$task, $subtask]) }}"
                                                                data-update-url="{{ route('subtasks.update', [$task, $subtask]) }}"
                                                                aria-label="Edit subtask"
                                                                title="Edit subtask"
                                                            >
                                                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                                            </button>
                                                            <div class="dropdown d-inline-block">
                                                                <button
                                                                    type="button"
                                                                    class="tf-st-action-btn dropdown-toggle-none"
                                                                    data-bs-toggle="dropdown"
                                                                    aria-expanded="false"
                                                                    aria-label="More options"
                                                                    title="More options"
                                                                >
                                                                    <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                                                </button>
                                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                                    <li>
                                                                        <button
                                                                            type="button"
                                                                            class="dropdown-item tf-subtasks__open-modal"
                                                                            data-subtask-url="{{ route('subtasks.show', [$task, $subtask]) }}"
                                                                            data-update-url="{{ route('subtasks.update', [$task, $subtask]) }}"
                                                                        >
                                                                            <i class="bi bi-pencil me-2"></i> Edit Details
                                                                        </button>
                                                                    </li>
                                                                    <li>
                                                                        <button
                                                                            type="button"
                                                                            class="dropdown-item tf-dropdown-toggle-subtask"
                                                                            data-toggle-url="{{ route('subtasks.toggle', [$task, $subtask]) }}"
                                                                        >
                                                                            <i class="bi {{ $subtask->is_completed ? 'bi-arrow-counterclockwise' : 'bi-check2-circle' }} me-2"></i>
                                                                            {{ $subtask->is_completed ? 'Mark Incomplete' : 'Mark Complete' }}
                                                                        </button>
                                                                    </li>
                                                                    <li><hr class="dropdown-divider"></li>
                                                                    <li>
                                                                        <button
                                                                            type="button"
                                                                            class="dropdown-item text-danger tf-subtasks__delete"
                                                                            data-delete-url="{{ route('subtasks.destroy', [$task, $subtask]) }}"
                                                                        >
                                                                            <i class="bi bi-trash3 me-2"></i> Delete Subtask
                                                                        </button>
                                                                    </li>
                                                                </ul>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr id="subtasks-empty-row">
                                                    <td colspan="8" class="text-center py-4 text-muted">
                                                        <i class="bi bi-check2-square d-block fs-3 mb-2 text-primary opacity-50"></i>
                                                        No subtasks created yet. Click <strong>+ Add Subtask</strong> above to create one.
                                                    </td>
                                                </tr>
                                            @endforelse

                                            {{-- Inline Quick Add Row --}}
                                            <tr id="subtask-quick-add-row" class="tf-subtask-quick-add-row" hidden>
                                                <td class="col-num"><i class="bi bi-plus text-primary fs-5"></i></td>
                                                <td colspan="7">
                                                    <form method="POST" action="{{ route('subtasks.store', $task) }}" id="subtask-quick-add-form" class="tf-quick-add-form">
                                                        @csrf
                                                        <div class="tf-quick-add-fields">
                                                            <input
                                                                type="text"
                                                                name="title"
                                                                id="quick-add-title"
                                                                class="form-control form-control-sm"
                                                                placeholder="Subtask title (e.g. Home Page Module)..."
                                                                required
                                                                maxlength="500"
                                                                autocomplete="off"
                                                            >
                                                            <input
                                                                type="text"
                                                                name="description"
                                                                id="quick-add-desc"
                                                                class="form-control form-control-sm"
                                                                placeholder="Short description/details..."
                                                                maxlength="1000"
                                                            >
                                                            <select name="assigned_to" class="form-select form-select-sm" aria-label="Assign to">
                                                                <option value="">Assignee (Optional)</option>
                                                                @foreach ($employees->where('company_id', $task->company_id)->where('department_id', $task->department_id) as $emp)
                                                                    <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                                                                @endforeach
                                                            </select>
                                                            <input
                                                                type="date"
                                                                name="start_date"
                                                                class="form-control form-control-sm"
                                                                placeholder="Start Date"
                                                                title="Start Date"
                                                            >
                                                            <input
                                                                type="date"
                                                                name="due_date"
                                                                class="form-control form-control-sm"
                                                                placeholder="Deadline"
                                                                title="Deadline"
                                                                @if ($task->due_date) max="{{ $task->due_date->format('Y-m-d') }}" @endif
                                                            >
                                                            <select name="priority" class="form-select form-select-sm" aria-label="Priority">
                                                                <option value="medium" selected>Medium</option>
                                                                <option value="high">High</option>
                                                                <option value="critical">Critical</option>
                                                                <option value="low">Low</option>
                                                            </select>
                                                            <select name="status" class="form-select form-select-sm" aria-label="Status">
                                                                <option value="todo" selected>Pending</option>
                                                                <option value="in_progress">In Progress</option>
                                                                <option value="completed">Completed</option>
                                                            </select>
                                                            <div class="tf-quick-add-actions">
                                                                <button type="submit" class="btn btn-sm btn-primary">
                                                                    <i class="bi bi-check-lg"></i> Add
                                                                </button>
                                                                <button type="button" class="btn btn-sm btn-light" id="quick-add-cancel-btn">
                                                                    Cancel
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </form>
                                                </td>
                                            </tr>
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
                    </div>

                    {{-- ── TAB 2: TIMELINE VIEW ── --}}
                    <div class="tf-activity-tab-panel" id="panel-timeline" role="tabpanel" aria-labelledby="tab-timeline-btn" hidden>
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
                    </div>
                </section>
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

        // ── Quick Add Subtask Row ─────────────────────────────────────────────
        const addSubtaskBtn = document.getElementById('subtask-add-btn');
        const quickAddRow   = document.getElementById('subtask-quick-add-row');
        const quickAddCancel = document.getElementById('quick-add-cancel-btn');
        const quickAddForm  = document.getElementById('subtask-quick-add-form');

        addSubtaskBtn?.addEventListener('click', () => {
            if (quickAddRow) {
                quickAddRow.removeAttribute('hidden');
                document.getElementById('quick-add-title')?.focus();
            }
        });

        quickAddCancel?.addEventListener('click', () => {
            quickAddRow?.setAttribute('hidden', '');
            quickAddForm?.reset();
        });

        quickAddForm?.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(quickAddForm);
            fetch(quickAddForm.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: formData,
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to add subtask');
                }
            })
            .catch(() => {
                window.location.reload();
            });
        });

        // ── Expand All Descriptions ───────────────────────────────────────────
        const expandBtn = document.getElementById('subtask-expand-btn');
        expandBtn?.addEventListener('click', () => {
            const table = document.getElementById('subtasks-table');
            const isExpanded = table?.classList.toggle('is-expanded');
            if (expandBtn) {
                expandBtn.innerHTML = `<i class="bi ${isExpanded ? 'bi-arrows-angle-contract' : 'bi-arrows-angle-expand'}"></i> ${isExpanded ? 'Collapse All' : 'Expand All'}`;
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

        // ── Toggle complete ───────────────────────────────────────────────────
        document.getElementById('subtasks-list')?.addEventListener('click', function (e) {
            const btn = e.target.closest('.tf-subtasks__toggle');
            if (!btn) { return; }
            const url = btn.dataset.toggleUrl;
            if (!url) { return; }

            fetch(url, {
                method: 'PATCH',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            })
            .then(r => r.json())
            .then(data => {
                const row = btn.closest('.tf-subtasks__item');
                row.classList.toggle('is-done', data.is_completed);

                const icon = btn.querySelector('i');
                if (data.is_completed) {
                    btn.className = 'tf-subtasks__toggle tf-st-toggle-btn is-completed';
                    icon.className = 'bi bi-check-circle-fill';
                } else {
                    btn.className = 'tf-subtasks__toggle tf-st-toggle-btn is-pending';
                    icon.className = 'bi bi-circle';
                }
                btn.setAttribute('aria-pressed', String(data.is_completed));
                btn.setAttribute('aria-label', data.is_completed ? 'Mark incomplete' : 'Mark complete');
                btn.title = data.is_completed ? 'Mark incomplete' : 'Mark complete';

                // Update status pill and progress in row
                const statusPill = row.querySelector('.tf-st-status-pill');
                if (statusPill) {
                    if (data.is_completed) {
                        statusPill.className = 'tf-st-status-pill is-completed';
                        statusPill.textContent = 'Completed';
                    } else {
                        statusPill.className = 'tf-st-status-pill is-pending';
                        statusPill.textContent = 'Pending';
                    }
                }
                const progPct = row.querySelector('.tf-st-progress-pct');
                const progFill = row.querySelector('.tf-st-mini-fill');
                if (progPct) { progPct.textContent = data.is_completed ? '100%' : '0%'; }
                if (progFill) {
                    progFill.style.width = data.is_completed ? '100%' : '0%';
                    progFill.className = `tf-st-mini-fill ${data.is_completed ? 'is-completed' : 'is-zero'}`;
                }

                // Update Gantt bar class
                const subtaskId = row.dataset.subtaskId;
                const ganttRow = document.getElementById(`gantt-row-${subtaskId}`);
                const ganttBar = ganttRow?.querySelector('.tf-gantt-bar');
                if (ganttBar) {
                    ganttBar.className = `tf-gantt-bar ${data.is_completed ? 'tf-gantt-bar--completed' : 'tf-gantt-bar--pending'}`;
                }

                updateSubtaskBadge();
            })
            .catch(() => {});
        });

        // Dropdown toggle complete button
        document.getElementById('subtasks-list')?.addEventListener('click', function (e) {
            const dropdownToggle = e.target.closest('.tf-dropdown-toggle-subtask');
            if (!dropdownToggle) { return; }
            const row = dropdownToggle.closest('.tf-subtasks__item');
            row?.querySelector('.tf-subtasks__toggle')?.click();
        });

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
                const deleteUrl = btn.closest('.tf-subtasks__item')?.querySelector('.tf-subtasks__delete')?.dataset.deleteUrl;
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
                        const toggleBtn = li.querySelector('.tf-subtasks__toggle');
                        if (toggleBtn) {
                            const icon = toggleBtn.querySelector('i');
                            if (icon) { icon.className = `bi ${st.is_completed ? 'bi-check-circle-fill' : 'bi-circle'}`; }
                            toggleBtn.setAttribute('aria-pressed', String(st.is_completed));
                        }
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
                activeSubtaskDeleteUrl = li?.querySelector('.tf-subtasks__delete')?.dataset.deleteUrl;
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
                    const openBtn = targetRow.querySelector('.tf-subtasks__open-modal');
                    if (openBtn) {
                        openSubtaskModal(
                            openBtn.dataset.subtaskUrl,
                            openBtn.dataset.updateUrl,
                            targetRow.querySelector('.tf-subtasks__delete')?.dataset.deleteUrl
                        );
                    }
                }
            }, 300);
        }
    })();
    </script>

    @include('tasks._reassign-modal')
    @include('tasks._subtask-modal')
@endsection

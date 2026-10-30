<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\SubtaskProgressService;
use App\Services\TaskQueryService;
use App\Services\TaskWorkflowService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Task list and task detail screens for the installable PWA.
 *
 * Every query goes through Task::visibleTo() / TaskPolicy, exactly like the
 * main application, so no task outside the user's scope is ever exposed.
 */
class PwaTaskController extends Controller
{
    /** @var array<string, string> filter key => label */
    public const FILTERS = [
        'all' => 'All',
        'overdue' => 'Overdue',
        'active' => 'Active',
        'done' => 'Done',
    ];

    public function index(Request $request, TaskQueryService $queries): View
    {
        $this->authorize('viewAny', Task::class);

        return view('pwa.tasks.index', [
            ...$this->listData($request, $queries, 15),
            'summary' => $queries->counts($request->user()),
        ]);
    }

    public function show(
        Request $request,
        Task $task,
        TaskQueryService $queries,
        TaskWorkflowService $workflow,
        SubtaskProgressService $subtaskProgress,
    ): View {
        $this->authorize('view', $task);
        $user = $request->user();

        $task->load([
            'company:id,name',
            'department:id,name',
            'creator:id,name',
            'assignee:id,name',
            'activeAssignees:id,name',
            'pocs:id,name',
            'attachments.uploadedBy:id,name',
            'statusHistory.changedBy:id,name',
            'subtasks.assignee:id,name',
        ]);

        $canReviewExtensions = $user->can('reviewDeadlineExtensions', $task);
        $extensionRequests = $task->deadlineExtensionRequests()
            ->with(['requester:id,name', 'reviewer:id,name'])
            ->when(! $canReviewExtensions, fn (Builder $query) => $query->where('requested_by', $user->id))
            ->get();

        $statusOptions = $workflow->availableTransitions($task, $user);

        return view('pwa.tasks.show', [
            ...$this->listData($request, $queries, 25),
            'summary' => $queries->counts($user),
            'task' => $task,
            'progress' => $subtaskProgress->summary($task)['work_completion'],
            'stages' => $this->workflowStages($task),
            'extensionRequests' => $extensionRequests,
            'actions' => [
                'start' => $user->can('startWork', $task),
                'resume' => $user->can('resume', $task),
                'hold' => isset($statusOptions['on_hold']),
            ],
        ]);
    }

    /**
     * Shared list query used by both the list screen and the split-view sidebar.
     *
     * @return array{tasks: LengthAwarePaginator, filter: string, search: string, filters: array<string, string>}
     */
    private function listData(Request $request, TaskQueryService $queries, int $perPage): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:all,overdue,active,done,awaiting,at_risk,in_progress'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $filter = $validated['filter'] ?? 'all';
        $search = trim((string) ($validated['search'] ?? ''));

        // Reuse the existing, authorization-aware query service.
        $criteria = array_filter(['search' => $search !== '' ? $search : null]);
        $criteria += match ($filter) {
            'overdue' => ['condition' => 'overdue'],
            'at_risk' => ['condition' => 'at_risk'],
            'awaiting' => ['status_group' => 'awaiting_assignment'],
            'in_progress' => ['status' => 'in_progress'],
            'done' => ['status' => 'completed'],
            default => [],
        };

        $query = $queries->filteredTasksQuery($criteria, $request->user())
            ->availableInRecurringWindow();

        if ($filter === 'active') {
            $query->whereNotIn('tasks.status', ['completed', 'cancelled']);
        }

        $tasks = $queries->applySort($query, 'deadline_priority')
            ->with(['activeAssignees:id,name', 'assignee:id,name'])
            ->paginate($perPage)
            ->withQueryString();

        return [
            'tasks' => $tasks,
            'filter' => $filter,
            'search' => $search,
            'filters' => self::FILTERS,
        ];
    }

    /**
     * Workflow stepper built from the real statuses (same stages as the full app).
     *
     * @return list<array{status: string, label: string, state: string, date: mixed}>
     */
    private function workflowStages(Task $task): array
    {
        $hasRework = $task->status === 'rework' || $task->statusHistory->contains('new_status', 'rework');

        $stages = [
            'notified' => 'Notified',
            'unassigned' => 'Unassigned',
            'assigned' => 'Assigned',
            'in_progress' => 'In Progress',
            'submitted_for_review' => 'Review',
            ...($hasRework ? ['rework' => 'Re-work'] : []),
            'completed' => 'Completed',
        ];

        $current = $task->status === 'on_hold' ? 'in_progress' : $task->status;
        $keys = array_keys($stages);
        $currentIndex = array_search($current, $keys, true);

        $reachedAt = ['notified' => $task->created_at];
        foreach ($task->statusHistory as $entry) {
            $status = $entry->new_status === 'on_hold' ? 'in_progress' : $entry->new_status;
            $reachedAt[$status] ??= $entry->created_at;
        }

        $out = [];
        foreach ($keys as $index => $status) {
            $state = match (true) {
                $currentIndex === false => 'upcoming',
                $index < $currentIndex => 'done',
                $index === $currentIndex => $status === 'completed' ? 'done' : 'current',
                default => 'upcoming',
            };

            $out[] = ['status' => $status, 'label' => $stages[$status], 'state' => $state, 'date' => $reachedAt[$status] ?? null];
        }

        return $out;
    }
}

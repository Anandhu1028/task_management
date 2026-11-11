<?php

namespace App\Services\Mcp\Tools;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

class GetMyTasksTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_my_tasks';
    }

    public function description(): string
    {
        return 'Retrieve tasks scoped to the authenticated user\'s role. '
            .'For Admin and CEO: returns all tasks company-wide (optionally filter by assignee_id, department_id, or company_id). '
            .'For Manager: returns all tasks within their company. '
            .'For Team Leader: returns all tasks within their department. '
            .'For Employee: returns only tasks assigned to themselves.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status: notified, unassigned, assigned, in_progress, on_hold, submitted_for_review, rework, completed, cancelled.',
                    'enum' => Task::STATUSES,
                ],
                'condition' => [
                    'type' => 'string',
                    'description' => 'Deadline condition: normal, at_risk, overdue.',
                    'enum' => Task::DEADLINE_CONDITIONS,
                ],
                'priority' => [
                    'type' => 'string',
                    'description' => 'Priority level: critical, high, medium, low.',
                    'enum' => Task::PRIORITIES,
                ],
                'project' => [
                    'type' => 'string',
                    'description' => 'Filter by project name (case-insensitive partial match).',
                ],
                'assignee_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by a specific assignee user ID (Admin/CEO/Manager/Team Leader only).',
                ],
                'department_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by department ID (Admin/CEO only).',
                ],
                'company_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by company ID (Admin/CEO only).',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Start date filter for due date (YYYY-MM-DD).',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'End date filter for due date (YYYY-MM-DD).',
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Page number for pagination (default: 1).',
                    'minimum' => 1,
                ],
                'per_page' => [
                    'type' => 'integer',
                    'description' => 'Number of tasks per page (default: 20, max: 50).',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $isAdminOrCeo = $user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO');
        $isManagerOrLeader = $user->hasAssignedRole('Manager') || $user->hasAssignedRole('Team Leader');
        $perPage = min(50, max(1, (int) ($arguments['per_page'] ?? 20)));
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $requestId = request()->attributes->get('mcp_request_id');

        Log::debug('MCP task retrieval started', [
            'stage' => 'task_retrieval_started',
            'role_scope' => $isAdminOrCeo ? 'company' : ($isManagerOrLeader ? 'role' : 'assigned'),
            'has_company_context' => $user->company_id !== null,
            'has_department_context' => $user->department_id !== null,
            'filter_names' => array_keys(array_intersect_key($arguments, array_flip([
                'status', 'condition', 'priority', 'project', 'assignee_id',
                'department_id', 'company_id', 'from_date', 'to_date',
            ]))),
            'page' => $page,
            'per_page' => $perPage,
            'request_id' => $requestId,
        ]);

        $query = Task::query()
            ->visibleTo($user)
            ->availableInRecurringWindow()
            ->with(['company:id,name', 'department:id,name', 'creator:id,name', 'assignee:id,name', 'activeAssignees:id,name'])
            ->withCount(['subtasks', 'subtasks as completed_subtasks_count' => fn ($q) => $q->where('is_completed', true)]);

        // For non-admin/non-privileged roles: scope to assigned tasks only
        if (! $isAdminOrCeo && ! $isManagerOrLeader) {
            $query->assignedTo($user->id);
        }

        // Assignee filter — allowed for elevated roles
        if (! empty($arguments['assignee_id'])) {
            $assigneeId = (int) $arguments['assignee_id'];
            if ($isAdminOrCeo || $isManagerOrLeader) {
                $query->assignedTo($assigneeId);
            }
        }

        // Department filter — Admin/CEO only
        if (! empty($arguments['department_id']) && $isAdminOrCeo) {
            $query->where('tasks.department_id', (int) $arguments['department_id']);
        }

        // Company filter — Admin/CEO only (restricted to user's company context)
        if (! empty($arguments['company_id']) && $isAdminOrCeo) {
            $requestedCompanyId = (int) $arguments['company_id'];
            if ($user->company_id && (int) $user->company_id !== $requestedCompanyId) {
                // Reject unauthorized cross-company access attempt
                $query->whereRaw('1 = 0');
            } else {
                $query->where('tasks.company_id', $requestedCompanyId);
            }
        }

        if (! empty($arguments['status'])) {
            $query->where('tasks.status', $arguments['status']);
        }

        if (! empty($arguments['priority'])) {
            $query->where('tasks.priority', $arguments['priority']);
        }

        if (! empty($arguments['project'])) {
            $query->where('tasks.project', 'like', '%'.trim((string) $arguments['project']).'%');
        }

        if (! empty($arguments['condition'])) {
            match ($arguments['condition']) {
                'overdue' => $query->overdue(),
                'at_risk' => $query->atRisk(),
                'normal' => $query->deadlineNormal(),
                default => null,
            };
        }

        if (! empty($arguments['from_date'])) {
            $from = Carbon::parse($arguments['from_date'])->startOfDay();
            $query->where(function (Builder $q) use ($from): void {
                $q->where('tasks.due_date', '>=', $from->toDateString())
                    ->orWhere(fn (Builder $sq) => $sq->whereNull('tasks.due_date')->where('tasks.original_deadline', '>=', $from->toDateString()));
            });
        }

        if (! empty($arguments['to_date'])) {
            $to = Carbon::parse($arguments['to_date'])->endOfDay();
            $query->where(function (Builder $q) use ($to): void {
                $q->where('tasks.due_date', '<=', $to->toDateString())
                    ->orWhere(fn (Builder $sq) => $sq->whereNull('tasks.due_date')->where('tasks.original_deadline', '<=', $to->toDateString()));
            });
        }

        try {
            $paginator = $query
                ->orderByRaw('CASE WHEN tasks.status = ? THEN 1 ELSE 0 END', ['completed'])
                ->orderByRaw('COALESCE(tasks.due_date, tasks.original_deadline) ASC')
                ->orderByDesc('tasks.id')
                ->paginate($perPage, ['*'], 'page', $page);
        } catch (QueryException $exception) {
            Log::error('MCP task database query failed', [
                'stage' => 'task_database_query',
                'exception_class' => $exception::class,
                'sql_state' => $exception->getCode(),
                'sql_template' => $exception->getSql(),
                'request_id' => $requestId,
            ]);

            throw $exception;
        }

        Log::debug('MCP task pagination completed', [
            'stage' => 'task_pagination_completed',
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'result_count' => count($paginator->items()),
            'total_count' => $paginator->total(),
            'request_id' => $requestId,
        ]);

        try {
            $tasks = collect($paginator->items())->map(function (Task $task): array {
                $deadline = $task->due_date ?? $task->original_deadline;
                $assignees = $task->activeAssignees->isNotEmpty()
                    ? $task->activeAssignees->pluck('name')->all()
                    : ($task->assignee ? [$task->assignee->name] : []);

                return [
                    'id' => $task->id,
                    'task_code' => $task->task_code,
                    'title' => $task->title,
                    'description' => $task->description,
                    'project' => $task->project,
                    'status' => $task->status,
                    'status_label' => Task::STATUS_LABELS[$task->status] ?? $task->status,
                    'priority' => $task->priority,
                    'progress_percentage' => (int) $task->progress_percentage,
                    'deadline_condition' => $task->deadline_condition,
                    'is_overdue' => $task->isOverdue(),
                    'due_date' => $deadline?->toDateString(),
                    'company' => $task->company?->name,
                    'department' => $task->department?->name,
                    'assignees' => $assignees,
                    'subtasks' => [
                        'total' => (int) $task->subtasks_count,
                        'completed' => (int) $task->completed_subtasks_count,
                    ],
                ];
            })->values()->all();
        } catch (Throwable $exception) {
            Log::error('MCP task serialization failed', [
                'stage' => 'task_result_serialization',
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
                'request_id' => $requestId,
            ]);

            throw $exception;
        }

        Log::debug('MCP task retrieval completed', [
            'stage' => 'task_retrieval_completed',
            'serialized_task_count' => count($tasks),
            'request_id' => $requestId,
        ]);

        return [
            'viewer' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->primaryRole?->name ?? 'Unknown',
                'designation' => $user->designation,
            ],
            'scope' => $isAdminOrCeo ? 'all_company_tasks' : ($isManagerOrLeader ? 'scoped_by_role' : 'my_assigned_tasks'),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'total_tasks' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
            'tasks' => $tasks,
            'applied_filters' => array_filter([
                'status' => $arguments['status'] ?? null,
                'condition' => $arguments['condition'] ?? null,
                'priority' => $arguments['priority'] ?? null,
                'project' => $arguments['project'] ?? null,
                'assignee_id' => isset($arguments['assignee_id']) ? (int) $arguments['assignee_id'] : null,
                'department_id' => isset($arguments['department_id']) ? (int) $arguments['department_id'] : null,
                'company_id' => isset($arguments['company_id']) ? (int) $arguments['company_id'] : null,
                'from_date' => $arguments['from_date'] ?? null,
                'to_date' => $arguments['to_date'] ?? null,
            ]),
        ];
    }
}

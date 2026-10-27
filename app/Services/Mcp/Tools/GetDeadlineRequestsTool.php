<?php

namespace App\Services\Mcp\Tools;

use App\Models\DeadlineExtensionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class GetDeadlineRequestsTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_deadline_requests';
    }

    public function description(): string
    {
        return 'Retrieve task deadline extension requests and their review status, filtered strictly by user permissions (employees view their own; reviewers view tasks under their supervision).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status: pending, approved, rejected.',
                    'enum' => [
                        DeadlineExtensionRequest::STATUS_PENDING,
                        DeadlineExtensionRequest::STATUS_APPROVED,
                        DeadlineExtensionRequest::STATUS_REJECTED,
                    ],
                ],
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'Optional filter for specific task ID.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum records to return (default: 25, max: 50).',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $query = DeadlineExtensionRequest::query()
            ->with(['task:id,task_code,title,project,due_date,original_deadline,department_id,company_id', 'requester:id,name', 'reviewer:id,name']);

        // Check if user is reviewer or employee
        $isReviewer = $user->hasAssignedRole('Admin')
            || $user->hasAssignedRole('CEO')
            || $user->hasAssignedRole('Manager')
            || $user->hasAssignedRole('Team Leader');

        if (! $isReviewer) {
            // Regular employee only views their own requests
            $query->where('requested_by', $user->id);
        } else {
            // Reviewer: view requests for tasks visible to them
            $query->where(function (Builder $builder) use ($user): void {
                $builder->where('requested_by', $user->id)
                    ->orWhereHas('task', fn (Builder $tq) => $tq->visibleTo($user));
            });
        }

        if (! empty($arguments['status'])) {
            $query->where('status', $arguments['status']);
        }

        if (! empty($arguments['task_id'])) {
            $query->where('task_id', (int) $arguments['task_id']);
        }

        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 25)));

        $requests = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $data = $requests->map(fn (DeadlineExtensionRequest $req): array => [
            'id' => $req->id,
            'task' => [
                'id' => $req->task?->id,
                'task_code' => $req->task?->task_code,
                'title' => $req->task_title ?: $req->task?->title,
                'project' => $req->task?->project,
            ],
            'current_deadline' => $req->current_deadline?->toDateString(),
            'requested_deadline' => $req->requested_deadline->toDateString(),
            'reason' => $req->reason,
            'status' => $req->status,
            'review_note' => $req->review_note,
            'requested_by' => $req->requester?->name,
            'reviewed_by' => $req->reviewer?->name,
            'reviewed_at' => $req->reviewed_at?->toIso8601String(),
            'created_at' => $req->created_at?->toIso8601String(),
        ])->values()->all();

        return [
            'total_requests' => count($data),
            'requests' => $data,
        ];
    }
}

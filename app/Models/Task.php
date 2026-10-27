<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'status' => 'unassigned',
    ];

    protected $fillable = [
        'task_code',
        'title',
        'description',
        'company_id',
        'department_id',
        'project',
        'task_type',
        'created_by',
        'assigned_to',
        'reviewer_id',
        'priority',
        'status',
        'progress_percentage',
        'start_date',
        'due_date',
        'original_deadline',
        'hold_reason',
        'risk_reason',
        'risk_flagged_by',
        'risk_flagged_at',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'submitted_at',
        'submission_comment',
        'reviewed_by',
        'reviewed_at',
        'review_comment',
        'estimated_hours',
        'expected_output',
        'completed_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'original_deadline' => 'date',
        'completed_at' => 'datetime',
        'risk_flagged_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'estimated_hours' => 'decimal:2',
        'progress_percentage' => 'integer',
    ];

    /** Valid priority values. */
    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    /** Valid task type values. */
    public const TYPES = ['process', 'volume', 'recurring', 'project'];

    /** Supported workflow statuses; deadline conditions are derived separately. */
    public const STATUSES = [
        'notified',
        'unassigned',
        'assigned',
        'in_progress',
        'on_hold',
        'submitted_for_review',
        'rework',
        'completed',
        'cancelled',
    ];

    public const DEADLINE_CONDITIONS = [
        'normal',
        'at_risk',
        'overdue',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'notified' => 'Notified',
        'unassigned' => 'Unassigned',
        'assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'on_hold' => 'On Hold',
        'submitted_for_review' => 'Submitted for Review',
        'rework' => 'Re-work',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    /** @var array<string, string> */
    public const DEADLINE_CONDITION_LABELS = [
        'normal' => 'Normal',
        'at_risk' => 'At Risk',
        'overdue' => 'Overdue',
    ];

    /** @var array<string, list<string>> */
    public const STATUS_TRANSITIONS = [
        'notified' => ['unassigned'],
        'unassigned' => ['assigned'],
        'assigned' => ['in_progress', 'on_hold'],
        'in_progress' => ['on_hold', 'submitted_for_review'],
        'on_hold' => ['in_progress', 'submitted_for_review'],
        'submitted_for_review' => ['on_hold'],
        'rework' => ['on_hold', 'submitted_for_review'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activeAssignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignments', 'task_id', 'user_id')
            ->withPivot(['assigned_by', 'reason', 'assigned_at', 'unassigned_at', 'is_active'])
            ->wherePivot('is_active', true)
            ->wherePivotNull('unassigned_at');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function riskFlaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'risk_flagged_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function pocs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_pocs', 'task_id', 'user_id')
            ->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class)->orderBy('assigned_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class)->orderBy('created_at');
    }

    public function deadlineExtensionRequests(): HasMany
    {
        return $this->hasMany(DeadlineExtensionRequest::class)->latest();
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class)->orderBy('created_at');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Subtask::class)->orderBy('sort_order')->orderBy('id');
    }

    public function getDisplayStatusAttribute(): string
    {
        return $this->status;
    }

    public function getDeadlineConditionAttribute(): string
    {
        if (in_array($this->status, ['completed', 'cancelled'], true)) {
            return 'normal';
        }

        $deadline = $this->due_date ?? $this->original_deadline;
        if ($deadline !== null && $deadline->lt(today())) {
            return 'overdue';
        }

        if (filled($this->risk_reason)) {
            return 'at_risk';
        }

        $riskThresholdDays = max(0, (int) config('tasks.deadline_risk_days', 2));

        return $deadline !== null
            && today()->diffInDays($deadline, false) <= $riskThresholdDays
            ? 'at_risk'
            : 'normal';
    }

    public function isOverdue(): bool
    {
        return $this->deadline_condition === 'overdue';
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotIn('tasks.status', ['completed', 'cancelled'])
            ->whereRaw('COALESCE(tasks.due_date, tasks.original_deadline) < ?', [today()->toDateString()]);
    }

    public function scopeAtRisk(Builder $query): Builder
    {
        $today = today()->toDateString();
        $riskThresholdDate = today()
            ->addDays(max(0, (int) config('tasks.deadline_risk_days', 2)))
            ->toDateString();

        return $query
            ->whereNotIn('tasks.status', ['completed', 'cancelled'])
            ->where(function (Builder $query) use ($today, $riskThresholdDate): void {
                $query->whereNotNull('tasks.risk_reason')
                    ->orWhereRaw(
                        'COALESCE(tasks.due_date, tasks.original_deadline) BETWEEN ? AND ?',
                        [$today, $riskThresholdDate],
                    );
            })
            ->where(function (Builder $query) use ($today): void {
                $query->whereRaw(
                    'COALESCE(tasks.due_date, tasks.original_deadline) IS NULL OR COALESCE(tasks.due_date, tasks.original_deadline) >= ?',
                    [$today],
                );
            });
    }

    public function scopeDeadlineNormal(Builder $query): Builder
    {
        $thresholdDate = today()
            ->addDays(max(0, (int) config('tasks.deadline_risk_days', 2)))
            ->toDateString();

        return $query
            ->whereNotIn('tasks.status', ['completed', 'cancelled'])
            ->whereNull('tasks.risk_reason')
            ->where(function (Builder $query) use ($thresholdDate): void {
                $query->whereRaw('COALESCE(tasks.due_date, tasks.original_deadline) IS NULL')
                    ->orWhereRaw('COALESCE(tasks.due_date, tasks.original_deadline) > ?', [$thresholdDate]);
            });
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $query) use ($userId): void {
            $query->where('tasks.assigned_to', $userId)
                ->orWhereHas('assignments', function (Builder $assignmentQuery) use ($userId): void {
                    $assignmentQuery
                        ->where('user_id', $userId)
                        ->where('is_active', true)
                        ->whereNull('unassigned_at');
                });
        });
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO')) {
            return $query;
        }

        if ($user->hasAssignedRole('Employee')) {
            return $query->where(function (Builder $query) use ($user): void {
                $query->assignedTo($user->id)
                    ->orWhereHas('pocs', fn (Builder $pocQuery) => $pocQuery
                        ->whereKey($user->id)
                        ->where('users.company_id', $user->company_id)
                        ->where('users.department_id', $user->department_id));
            });
        }

        if ($user->hasAssignedRole('Manager')) {
            return $user->company_id
                ? $query->where('tasks.company_id', $user->company_id)
                : $query->whereRaw('1 = 0');
        }

        if ($user->hasAssignedRole('Team Leader')) {
            return $user->department_id && $user->company_id
                ? $query->where('tasks.department_id', $user->department_id)
                    ->where('tasks.company_id', $user->company_id)
                : $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->assignedTo($user->id)
                ->orWhereHas('pocs', fn (Builder $pocQuery) => $pocQuery
                    ->whereKey($user->id)
                    ->where('users.company_id', $user->company_id)
                    ->where('users.department_id', $user->department_id));
        });
    }

    public static function generateCode(): string
    {
        $latest = static::withTrashed()->latest('id')->first();
        $number = $latest ? (int) preg_replace('/\D+/', '', $latest->task_code ?? '0') + 1 : 1;

        return 'TSK-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}

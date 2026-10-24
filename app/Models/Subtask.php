<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subtask extends Model
{
    use HasFactory;

    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    public const STATUSES = ['todo', 'assigned', 'in_progress', 'on_hold', 'in_review', 'completed'];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'todo' => 'Pending',
        'assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'on_hold' => 'On Hold',
        'in_review' => 'In Review',
        'completed' => 'Completed',
    ];

    protected $fillable = [
        'task_id',
        'created_by',
        'assigned_to',
        'title',
        'description',
        'priority',
        'status',
        'start_date',
        'due_date',
        'estimated_hours',
        'progress_percentage',
        'is_completed',
        'sort_order',
        'completed_at',
        'completed_by',
        'actual_started_at',
        'actual_elapsed_minutes',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'actual_started_at' => 'datetime',
        'actual_elapsed_minutes' => 'integer',
        'estimated_hours' => 'decimal:2',
        'progress_percentage' => 'integer',
        'sort_order' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date !== null
            && $this->due_date->lt(today())
            && ! $this->is_completed
            && $this->status !== 'completed';
    }
}

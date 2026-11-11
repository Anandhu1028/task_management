<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Subtask extends Model
{
    use HasFactory;

    public const STATUSES = ['todo', 'assigned', 'in_progress', 'on_hold', 'in_review', 'completed'];

    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'todo' => 'Pending',
        'assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'on_hold' => 'On Hold',
        'in_review' => 'In Review',
        'completed' => 'Completed',
    ];

    protected $attributes = [
        'is_completed' => false,
        'status' => 'todo',
        'sort_order' => 0,
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
        'actual_hours',
        'progress_percentage',
        'actual_start_at',
        'actual_started_at',
        'actual_elapsed_minutes',
        'is_completed',
        'sort_order',
        'completed_at',
        'completed_by',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'start_date' => 'date',
        'due_date' => 'date',
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'progress_percentage' => 'integer',
        'actual_start_at' => 'datetime',
        'actual_started_at' => 'datetime',
        'actual_elapsed_minutes' => 'integer',
        'completed_at' => 'datetime',
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

    public function dailyUpdates(): HasMany
    {
        return $this->hasMany(SubtaskDailyUpdate::class);
    }

    public function latestDailyUpdate(): HasOne
    {
        return $this->hasOne(SubtaskDailyUpdate::class)->latestOfMany();
    }

    public function volumeUpdates(): HasMany
    {
        return $this->hasMany(TaskVolumeUpdate::class);
    }

    public function getIsOverdueAttribute(): bool
    {
        return ! $this->is_completed
            && $this->status !== 'completed'
            && $this->due_date !== null
            && $this->due_date->lt(today());
    }
}

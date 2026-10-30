<?php

namespace App\Models;

use Database\Factories\MilestoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Milestone extends Model
{
    /** @use HasFactory<MilestoneFactory> */
    use HasFactory;

    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    /** @var array<string, list<string>> */
    public const STATUS_TRANSITIONS = [
        'pending' => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['pending', 'completed', 'cancelled'],
        'completed' => ['in_progress'],
        'cancelled' => [],
    ];

    protected $attributes = [
        'status' => 'pending',
        'progress_percentage' => 0,
    ];

    protected $fillable = [
        'task_id',
        'created_by',
        'title',
        'description',
        'expected_outcome',
        'target_date',
        'status',
        'progress_percentage',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'progress_percentage' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'milestone_assignees')->withTimestamps();
    }

    public function activity(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class)->latest('id');
    }
}

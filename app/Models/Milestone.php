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

    public const STATUSES = ['pending', 'in_progress', 'completed'];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
    ];

    /** @var array<string, list<string>> */
    public const STATUS_TRANSITIONS = [
        'pending' => ['in_progress', 'completed'],
        'in_progress' => ['pending', 'completed'],
        'completed' => ['in_progress', 'pending'],
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'task_id',
        'created_by',
        'title',
        'description',
        'status',
        'milestone_date',
        'completed_at',
        'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'milestone_date' => 'date',
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

    public function activities(): HasMany
    {
        return $this->hasMany(MilestoneActivity::class)->orderBy('created_at')->orderBy('id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskStatusHistory extends Model
{
    protected $fillable = [
        'task_id',
        'subtask_id',
        'daily_task_id',
        'milestone_id',
        'changed_by',
        'old_status',
        'new_status',
        'event_type',
        'remarks',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class);
    }

    public function dailyTask(): BelongsTo
    {
        return $this->belongsTo(DailyTask::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class, 'status_history_id');
    }
}

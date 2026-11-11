<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskVolumeUpdate extends Model
{
    protected $fillable = ['task_volume_id', 'task_id', 'subtask_id', 'user_id', 'update_date', 'completed_count', 'remarks'];

    protected function casts(): array
    {
        return ['update_date' => 'date', 'completed_count' => 'integer'];
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(TaskVolume::class, 'task_volume_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

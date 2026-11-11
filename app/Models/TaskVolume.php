<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskVolume extends Model
{
    protected $fillable = ['task_id', 'total_volume', 'start_date', 'deadline_date'];

    protected function casts(): array
    {
        return ['total_volume' => 'integer', 'start_date' => 'date', 'deadline_date' => 'date'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TaskVolumeUpdate::class);
    }
}

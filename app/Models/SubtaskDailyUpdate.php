<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubtaskDailyUpdate extends Model
{
    protected $fillable = [
        'task_id',
        'subtask_id',
        'employee_id',
        'reported_by',
        'task_title',
        'subtask_title',
        'employee_name',
        'work_date',
        'work_description',
        'work_completed',
        'current_status',
        'time_spent_minutes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'time_spent_minutes' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}

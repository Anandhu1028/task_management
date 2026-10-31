<?php

namespace App\Models;

use Database\Factories\DailyAccountabilityEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAccountabilityEvent extends Model
{
    /** @use HasFactory<DailyAccountabilityEventFactory> */
    use HasFactory;

    protected $fillable = [
        'task_id',
        'daily_task_id',
        'employee_id',
        'employee_name',
        'task_title',
        'daily_task_title',
        'source_history_id',
        'event_type',
        'report_date',
        'description',
        'event_key',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function dailyTask(): BelongsTo
    {
        return $this->belongsTo(DailyTask::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function sourceHistory(): BelongsTo
    {
        return $this->belongsTo(TaskStatusHistory::class, 'source_history_id');
    }
}

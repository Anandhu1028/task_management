<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringTask extends Model
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const STATUSES = ['active', 'paused', 'completed'];

    protected $fillable = ['task_id', 'repeat_frequency', 'start_date', 'end_date', 'start_time', 'end_time', 'timezone', 'status'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('occurrence_date');
    }

    public function nextOccurrenceDate(): ?CarbonImmutable
    {
        if ($this->status !== 'active') {
            return null;
        }

        $timezone = $this->timezone ?: config('app.timezone');
        $date = CarbonImmutable::parse($this->start_date->toDateString(), $timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        while ($date->lessThan($today) || $this->occurrences()->whereDate('occurrence_date', $date)->exists()) {
            $date = match ($this->repeat_frequency) {
                'weekly' => $date->addWeek(),
                'monthly' => $date->addMonthNoOverflow(),
                default => $date->addDay(),
            };
        }

        return $date->lessThanOrEqualTo($this->end_date) ? $date : null;
    }
}

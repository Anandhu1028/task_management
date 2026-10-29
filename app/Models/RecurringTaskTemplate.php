<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringTaskTemplate extends Model
{
    protected $fillable = [
        'title',
        'description',
        'company_id',
        'department_id',
        'created_by',
        'task_type',
        'priority',
        'expected_output',
        'original_deadline',
        'estimated_hours',
        'repeat_frequency',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'timezone',
        'status',
        'attachments',
    ];

    protected function casts(): array
    {
        return [
            'original_deadline' => 'date',
            'estimated_hours' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'attachments' => 'array',
        ];
    }

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const STATUSES = ['active', 'paused', 'cancelled'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pocs(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'recurring_task_template_pocs',
            'recurring_task_template_id',
            'user_id',
        )->withTimestamps();
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'recurring_task_template_assignees',
            'recurring_task_template_id',
            'user_id',
        )->withTimestamps();
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
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $startDate = CarbonImmutable::parse($this->start_date->toDateString(), $timezone);
        $endDate = CarbonImmutable::parse($this->end_date->toDateString(), $timezone);
        $nextOccurrence = $startDate;
        while ($nextOccurrence->lessThan($today)) {
            $nextOccurrence = $this->advanceOccurrenceDate($nextOccurrence);
        }

        if ($nextOccurrence->equalTo($today) && $now->format('H:i:s') >= $this->start_time) {
            $nextOccurrence = $this->advanceOccurrenceDate($nextOccurrence);
        }

        if ($nextOccurrence->greaterThan($endDate)) {
            return null;
        }

        return $nextOccurrence;
    }

    private function advanceOccurrenceDate(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this->repeat_frequency) {
            'weekly' => $date->addWeek(),
            'monthly' => $date->addMonthNoOverflow(),
            default => $date->addDay(),
        };
    }

    /**
     * @param  Builder<RecurringTaskTemplate>  $query
     * @return Builder<RecurringTaskTemplate>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO')) {
            return $query;
        }

        if ($user->hasAssignedRole('Employee')) {
            return $query->where(function (Builder $builder) use ($user): void {
                $builder->whereHas('assignees', fn (Builder $assigneeQuery) => $assigneeQuery->whereKey($user->id))
                    ->orWhereHas('pocs', fn (Builder $pocQuery) => $pocQuery->whereKey($user->id));
            });
        }

        if ($user->hasAssignedRole('Manager')) {
            return $user->company_id
                ? $query->where('recurring_task_templates.company_id', $user->company_id)
                : $query->whereRaw('1 = 0');
        }

        if ($user->hasAssignedRole('Team Leader')) {
            return $user->department_id && $user->company_id
                ? $query->where('recurring_task_templates.department_id', $user->department_id)
                    ->where('recurring_task_templates.company_id', $user->company_id)
                : $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $builder) use ($user): void {
            $builder->whereHas('assignees', fn (Builder $assigneeQuery) => $assigneeQuery->whereKey($user->id))
                ->orWhereHas('pocs', fn (Builder $pocQuery) => $pocQuery->whereKey($user->id));
        });
    }
}

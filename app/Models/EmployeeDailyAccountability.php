<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDailyAccountability extends Model
{
    protected $fillable = [
        'employee_id',
        'employee_name',
        'activity_date',
        'activity_status',
        'activity_count',
        'last_activity_at',
        'warning_status',
        'cutoff_evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'activity_count' => 'integer',
            'last_activity_at' => 'datetime',
            'warning_status' => 'boolean',
            'cutoff_evaluated_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}

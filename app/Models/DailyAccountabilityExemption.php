<?php

namespace App\Models;

use Database\Factories\DailyAccountabilityExemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAccountabilityExemption extends Model
{
    /** @use HasFactory<DailyAccountabilityExemptionFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'starts_on',
        'ends_on',
        'reason',
        'active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

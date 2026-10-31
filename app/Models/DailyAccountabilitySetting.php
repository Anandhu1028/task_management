<?php

namespace App\Models;

use Database\Factories\DailyAccountabilitySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyAccountabilitySetting extends Model
{
    /** @use HasFactory<DailyAccountabilitySettingFactory> */
    use HasFactory;

    /** @var list<string> */
    public const DEFAULT_WORKING_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'cutoff_time' => '18:00:00',
        'working_days' => self::DEFAULT_WORKING_DAYS,
    ];

    protected $fillable = [
        'cutoff_time',
        'working_days',
    ];

    protected function casts(): array
    {
        return [
            'working_days' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], self::DEFAULTS);
    }
}

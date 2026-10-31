<?php

namespace Database\Factories;

use App\Models\DailyAccountabilitySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyAccountabilitySetting>
 */
class DailyAccountabilitySettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            ...DailyAccountabilitySetting::DEFAULTS,
        ];
    }
}

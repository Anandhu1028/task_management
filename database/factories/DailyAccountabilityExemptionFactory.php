<?php

namespace Database\Factories;

use App\Models\DailyAccountabilityExemption;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyAccountabilityExemption>
 */
class DailyAccountabilityExemptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = now()->toDateString();

        return [
            'employee_id' => null,
            'starts_on' => $date,
            'ends_on' => $date,
            'reason' => fake()->sentence(),
            'active' => true,
            'created_by' => User::factory(),
        ];
    }
}

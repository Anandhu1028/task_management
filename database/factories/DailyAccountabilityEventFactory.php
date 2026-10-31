<?php

namespace Database\Factories;

use App\Models\DailyAccountabilityEvent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyAccountabilityEvent>
 */
class DailyAccountabilityEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reportDate = now()->toDateString();

        return [
            'task_id' => Task::factory(),
            'daily_task_id' => null,
            'employee_id' => User::factory(),
            'employee_name' => fake()->name(),
            'task_title' => fake()->sentence(4),
            'daily_task_title' => fake()->sentence(4),
            'source_history_id' => null,
            'event_type' => 'missed',
            'report_date' => $reportDate,
            'description' => 'No Daily Update was submitted by the cutoff.',
            'event_key' => fake()->unique()->uuid(),
        ];
    }
}

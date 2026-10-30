<?php

namespace Database\Factories;

use App\Models\DailyTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyTask>
 */
class DailyTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $workDate = fake()->dateTimeBetween('today', '+14 days')->format('Y-m-d');
        $startTime = '09:00';
        $endTime = '10:00';

        return [
            'task_id' => Task::factory(),
            'assigned_to' => User::factory(),
            'created_by' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'work_date' => $workDate,
            'planned_start_time' => $startTime,
            'planned_end_time' => $endTime,
            'original_work_date' => $workDate,
            'original_planned_start_time' => $startTime,
            'original_planned_end_time' => $endTime,
            'priority' => 'medium',
            'status' => 'planned',
            'progress_percentage' => 0,
            'estimated_minutes' => 60,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'created_by' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'expected_outcome' => fake()->optional()->sentence(),
            'target_date' => fake()->dateTimeBetween('today', '+30 days')->format('Y-m-d'),
            'status' => 'pending',
            'progress_percentage' => 0,
        ];
    }
}

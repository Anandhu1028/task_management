<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'task_code' => 'TSK-'.fake()->unique()->numerify('####'),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'company_id' => $company,
            'department_id' => Department::factory()->for($company),
            'created_by' => User::factory(),
            'priority' => 'medium',
            'status' => 'unassigned',
            'progress_percentage' => 0,
            'task_type' => 'process',
            'expected_output' => fake()->sentence(),
        ];
    }
}

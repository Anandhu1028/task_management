<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Database\Seeders\DemoOrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoOrganizationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_organizations_include_roles_departments_and_varied_assigned_tasks(): void
    {
        $this->seed();
        $this->seed(DemoOrganizationSeeder::class);

        $companies = Company::query()->where('name', 'like', 'Demo %')->get();
        $companyIds = $companies->modelKeys();

        $this->assertCount(4, $companies);
        $this->assertSame(24, Department::query()->whereIn('company_id', $companyIds)->count());
        $this->assertSame(220, User::query()->whereIn('company_id', $companyIds)->count());
        $this->assertSame(120, Task::query()->whereIn('company_id', $companyIds)->count());

        $this->assertSame(4, User::role('CEO')->whereIn('company_id', $companyIds)->count());
        $this->assertSame(24, User::role('Team Leader')->whereIn('company_id', $companyIds)->count());
        $this->assertSame(192, User::role('Employee')->whereIn('company_id', $companyIds)->count());

        foreach ($companies as $company) {
            $this->assertSame(6, $company->departments()->count());
            $this->assertSame(55, $company->users()->count());
            $this->assertSame(30, $company->tasks()->count());
        }

        $tasks = Task::query()->whereIn('company_id', $companyIds)->with(['assignee', 'assignments', 'pocs'])->get();
        $this->assertSame(120, $tasks->filter(fn (Task $task): bool => $task->assigned_to !== null)->count());
        $this->assertEqualsCanonicalizing(['assigned', 'in_progress', 'submitted_for_review', 'completed', 'on_hold'], $tasks->pluck('status')->unique()->values()->all());
        $this->assertSame(['process', 'project', 'recurring', 'volume'], $tasks->pluck('task_type')->unique()->sort()->values()->all());
        $this->assertTrue($tasks->every(fn (Task $task): bool => $task->assignee !== null
            && $task->assignee->department_id === $task->department_id
            && $task->assignments->contains(fn ($assignment): bool => $assignment->is_active && $assignment->user_id === $task->assigned_to)
            && $task->pocs->isNotEmpty()));

        $this->seed(DemoOrganizationSeeder::class);

        $this->assertSame(4, Company::query()->where('name', 'like', 'Demo %')->count());
        $this->assertSame(24, Department::query()->whereIn('company_id', $companyIds)->count());
        $this->assertSame(220, User::query()->whereIn('company_id', $companyIds)->count());
        $this->assertSame(120, Task::query()->whereIn('company_id', $companyIds)->count());
        $this->assertSame(120, TaskAssignment::query()
            ->whereHas('task', fn ($query) => $query->whereIn('company_id', $companyIds))
            ->where('is_active', true)
            ->count());
    }

    public function test_demo_seed_data_can_be_registered_as_a_company_ceo_and_department_lead(): void
    {
        $this->seed();
        $this->seed(DemoOrganizationSeeder::class);

        $company = Company::query()->where('name', 'Demo Atlas Technologies')->firstOrFail();
        $ceo = User::query()->where('email', 'atlas.ceo@example.test')->firstOrFail();
        $department = Department::query()->where('company_id', $company->id)->where('name', 'Engineering')->firstOrFail();
        $leaderDesignation = $department->designations()
            ->where('name', 'Engineering Team Leader')
            ->firstOrFail();

        $this->assertSame($company->id, $ceo->company_id);
        $this->assertTrue($ceo->hasRole(Role::query()->where('name', 'CEO')->firstOrFail()));
        $this->assertTrue($leaderDesignation->roles()->where('name', 'Team Leader')->exists());
    }
}

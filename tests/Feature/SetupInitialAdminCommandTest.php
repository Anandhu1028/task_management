<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SetupInitialAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_admin_with_an_interactive_password(): void
    {
        $this->seed('Database\\Seeders\\PermissionSeeder');

        $company = Company::create([
            'name' => 'Northwind',
            'description' => null,
            'status' => 'active',
        ]);
        $department = Department::create([
            'company_id' => $company->id,
            'name' => 'Operations',
            'designation' => 'Operations Lead',
            'description' => null,
            'status' => 'active',
        ]);
        $designationChoice = 'Northwind / Operations / Operations Lead';
        $password = 'sufficiently-long-test-password';

        $this->artisan('app:setup-initial-admin')
            ->expectsChoice('Choose the initial admin company, department, and designation.', $designationChoice, [$designationChoice])
            ->expectsQuestion('Full name', 'Casey Admin')
            ->expectsQuestion('Email address', 'casey@example.com')
            ->expectsQuestion('Employee ID', 'EMP-INITIAL')
            ->expectsQuestion('Phone number', '+1 555 010 2020')
            ->expectsQuestion('Password (minimum 12 characters)', $password)
            ->expectsQuestion('Confirm password', $password)
            ->expectsOutput('Initial Admin [casey@example.com] was created successfully.')
            ->assertExitCode(0);

        $admin = User::query()->where('email', 'casey@example.com')->firstOrFail();
        $this->assertSame('Casey Admin', $admin->name);
        $this->assertSame($company->id, $admin->company_id);
        $this->assertSame($department->id, $admin->department_id);
        $this->assertSame(
            Role::query()->where('name', 'Admin')->where('guard_name', 'web')->value('id'),
            $admin->role_id,
        );
        $this->assertSame('Operations Lead', $admin->designation);
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertSame(['Admin'], $admin->getRoleNames()->all());
    }

    public function test_it_refuses_to_create_another_initial_admin_once_any_user_exists(): void
    {
        User::factory()->create();

        $this->artisan('app:setup-initial-admin')
            ->expectsOutput('A non-demo account already exists. Initial admin setup is only available before a real account is created.')
            ->assertExitCode(1);
    }

    public function test_user_seeder_creates_idempotent_demo_accounts_with_real_organization_links(): void
    {
        $this->seed([
            PermissionSeeder::class,
            CompanySeeder::class,
            DepartmentSeeder::class,
            UserSeeder::class,
        ]);

        $this->assertDatabaseCount('users', 10);
        $this->assertDatabaseHas('users', [
            'email' => 'demo.user01@example.test',
            'employee_id' => 'DEMO-001',
            'name' => 'Demo User 01',
            'is_demo' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'demo.user10@example.test',
            'status' => 'inactive',
            'is_demo' => true,
        ]);

        $demoUser = User::query()->where('email', 'demo.user01@example.test')->firstOrFail();
        $this->assertTrue($demoUser->company()->exists());
        $this->assertTrue($demoUser->department()->where('company_id', $demoUser->company_id)->exists());
        $this->assertSame(['Employee'], $demoUser->getRoleNames()->all());
        $this->assertSame(
            Role::query()->where('name', 'Employee')->where('guard_name', 'web')->value('id'),
            $demoUser->role_id,
        );

        $this->seed(UserSeeder::class);

        $this->assertDatabaseCount('users', 10);
    }

    public function test_initial_admin_setup_is_allowed_when_only_demo_accounts_exist(): void
    {
        $this->seed([
            PermissionSeeder::class,
            CompanySeeder::class,
            DepartmentSeeder::class,
            UserSeeder::class,
        ]);

        $availableDepartments = Department::query()
            ->where('status', 'active')
            ->whereHas('company', fn ($query) => $query
                ->where('status', 'active')
            )
            ->with('company')
            ->orderBy('designation')
            ->get();
        $firstDepartment = $availableDepartments->firstOrFail();
        $designationChoice = $firstDepartment->company->name
            .' / '.$firstDepartment->name
            .' / '.$firstDepartment->designation;
        $choices = $availableDepartments->map(
            fn (Department $department): string => $department->company->name
                .' / '.$department->name
                .' / '.$department->designation
        )->all();
        $password = 'sufficiently-long-test-password';

        $this->artisan('app:setup-initial-admin')
            ->expectsChoice('Choose the initial admin company, department, and designation.', $designationChoice, $choices)
            ->expectsQuestion('Full name', 'Casey Admin')
            ->expectsQuestion('Email address', 'casey@example.com')
            ->expectsQuestion('Employee ID', 'EMP-INITIAL')
            ->expectsQuestion('Phone number', '+1 555 010 2020')
            ->expectsQuestion('Password (minimum 12 characters)', $password)
            ->expectsQuestion('Confirm password', $password)
            ->expectsOutput('Initial Admin [casey@example.com] was created successfully.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'casey@example.com',
            'is_demo' => false,
        ]);
    }
}

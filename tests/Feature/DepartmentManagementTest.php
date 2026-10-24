<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\DepartmentDesignation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->actingAs(User::query()->where('email', 'admin@example.com')->firstOrFail());
    }

    public function test_super_admin_can_view_department_management(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('departments.index'))
            ->assertOk()
            ->assertSee('Department Management')
            ->assertSee('Operations')
            ->assertSee('tf-user-summary', false)
            ->assertSee('tf-user-tabs', false)
            ->assertDontSee('tf-user-filter-dropdown', false)
            ->assertSee('Filter by department')
            ->assertSee('Filter by company')
            ->assertSee('Filter by designation')
            ->assertSee('Filter by assigned users')
            ->assertSee('Filter by tasks')
            ->assertSee('data-organization-select-all', false)
            ->assertSee('data-organization-selection-form', false)
            ->assertSee('Export')
            ->assertSee('Company Management')
            ->assertSee('Department Designations')
            ->assertSee('designations[${rowIndex}][name]', false)
            ->assertSee('designations[${rowIndex}][roles][]', false)
            ->assertSee('+ Role')
            ->assertSee('id="departmentFormModal"', false);
    }

    public function test_department_toolbar_filters_by_department_company_designation_users_and_tasks(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();
        $department = Department::create([
            'company_id' => $company->id,
            'name' => 'Toolbar Filter Department',
            'designation' => 'Toolbar Filter Specialist',
            'status' => 'active',
        ]);
        $department->designations()->create(['name' => 'Toolbar Filter Specialist']);
        $unassignedDepartment = Department::create([
            'company_id' => $company->id,
            'name' => 'Toolbar Filter Unassigned Department',
            'designation' => 'Toolbar Filter Specialist',
            'status' => 'active',
        ]);
        $unassignedDepartment->designations()->create(['name' => 'Toolbar Filter Specialist']);

        User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Toolbar Filter Task',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'unassigned',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Deliverable',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('departments.index', [
            'department_id' => $department->id,
            'company_id' => $company->id,
            'designation' => 'Toolbar Filter Specialist',
            'users' => 'with',
            'tasks' => 'with',
        ]));

        $response->assertOk()
            ->assertSee('<strong>Toolbar Filter Department</strong>', false)
            ->assertDontSee('<strong>Toolbar Filter Unassigned Department</strong>', false);

        $this->get(route('departments.index', [
            'department_id' => $department->id,
            'users' => 'without',
            'tasks' => 'without',
        ]))->assertOk()
            ->assertDontSee('<strong>Toolbar Filter Department</strong>', false);
    }

    public function test_department_create_and_edit_routes_open_the_management_modal(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $department = Department::query()->where('name', 'Operations')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('departments.create'))
            ->assertRedirect(route('departments.index', ['create' => 1]));

        $this->get(route('departments.index', ['create' => 1]))
            ->assertOk()
            ->assertSee('bootstrap.Modal.getOrCreateInstance(departmentFormModal).show()', false)
            ->assertSee('Create department');

        $this->get(route('departments.edit', $department))
            ->assertRedirect(route('departments.index', ['edit' => $department->id]));

        $this->get(route('departments.index', ['edit' => $department->id]))
            ->assertOk()
            ->assertSee('bootstrap.Modal.getOrCreateInstance(departmentFormModal).show()', false)
            ->assertSee('Edit department')
            ->assertSee('Operations');
    }

    public function test_department_can_be_created_with_one_designation_company_description_and_status(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('departments.store'), [
            'company_id' => $company->id,
            'name' => 'Customer Success',
            'designation' => 'Customer Success Manager',
            'description' => 'Customer onboarding and support',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('departments.index'));
        $this->assertDatabaseHas('departments', [
            'company_id' => $company->id,
            'name' => 'Customer Success',
            'description' => 'Customer onboarding and support',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('departments', [
            'name' => 'Customer Success',
            'designation' => 'Customer Success Manager',
        ]);
    }

    public function test_each_department_designation_can_store_multiple_roles(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();
        $roles = Role::query()->where('guard_name', 'web')->orderBy('name')->get();

        $this->actingAs($admin)->post(route('departments.store'), [
            'company_id' => $company->id,
            'name' => 'DM',
            'designations' => [
                1 => ['name' => 'DM Manager', 'roles' => [$roles[0]->id]],
                2 => ['name' => 'Developer', 'roles' => [$roles[0]->id]],
                3 => ['name' => 'Designers', 'roles' => [$roles[2]->id]],
            ],
            'description' => 'Department with multiple designations',
            'status' => 'active',
        ])->assertRedirect(route('departments.index'));

        $department = Department::query()
            ->where('company_id', $company->id)
            ->where('name', 'DM')
            ->firstOrFail();

        $this->assertSame('Department with multiple designations', $department->description);
        $this->assertSame('active', $department->status);
        $this->assertSame(3, $department->designations()->count());
        $this->assertSame(3, DB::table('department_designation_roles')
            ->whereIn('department_designation_id', $department->designations()->pluck('id'))
            ->count());

        $designationRoleMap = [
            'DM Manager' => $roles[0]->id,
            'Developer' => $roles[0]->id,
            'Designers' => $roles[2]->id,
        ];

        foreach ($designationRoleMap as $designationName => $roleId) {
            $designation = DepartmentDesignation::query()
                ->where('department_id', $department->id)
                ->where('name', $designationName)
                ->firstOrFail();

            $this->assertSame([$roleId], $designation->roles->pluck('id')->all());
            $this->assertDatabaseHas('department_designation_roles', [
                'department_designation_id' => $designation->id,
                'role_id' => $roleId,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('departments.index'))
            ->assertOk()
            ->assertSee('DM')
            ->assertSee('DM Manager')
            ->assertSee('Developer')
            ->assertSee('Designers')
            ->assertSee($roles[0]->name)
            ->assertSee($roles[2]->name);
    }

    public function test_department_can_be_updated(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $department = Department::query()->where('name', 'Operations')->firstOrFail();

        $response = $this->actingAs($admin)->put(route('departments.update', $department), [
            'company_id' => $department->company_id,
            'name' => 'Operations and Support',
            'designation' => 'Operations Lead',
            'description' => 'Updated description',
            'status' => 'inactive',
        ]);

        $response->assertRedirect(route('departments.index'));
        $this->assertDatabaseHas('departments', [
            'id' => $department->id,
            'name' => 'Operations and Support',
            'description' => 'Updated description',
            'status' => 'inactive',
        ]);
        $this->assertSame('Operations Lead', $department->fresh()->designation);
    }

    public function test_updating_a_department_designation_updates_assigned_users(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $department = Department::query()->where('name', 'Operations')->firstOrFail();
        User::factory()->create([
            'company_id' => $department->company_id,
            'department_id' => $department->id,
            'designation' => 'Team Member',
        ]);

        $this->actingAs($admin)->from(route('departments.index', ['edit' => $department->id]))
            ->put(route('departments.update', $department), [
                'company_id' => $department->company_id,
                'name' => $department->name,
                'designation' => 'Operations Lead',
                'description' => $department->description,
                'status' => $department->status,
            ])
            ->assertRedirect(route('departments.index'))
            ->assertSessionHas('success', 'Department and assigned user designations updated successfully.');

        $this->assertDatabaseHas('users', [
            'department_id' => $department->id,
            'designation' => 'Operations Lead',
        ]);
    }

    public function test_department_status_can_be_toggled(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $department = Department::query()->where('name', 'Operations')->firstOrFail();

        $response = $this->actingAs($admin)
            ->post(route('departments.toggle-status', $department))
            ->assertRedirect()
            ->assertSessionHas('success', 'Operations is now inactive.')
            ->assertSessionHas('operation_status', [
                'kind' => 'status',
                'user' => 'Operations',
                'from' => 'active',
                'to' => 'inactive',
                'message' => 'Operations is now inactive.',
            ]);

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'status' => 'inactive']);

        $this->get(route('departments.index'))
            ->assertOk()
            ->assertSee('window.tfCompletedOperation', false)
            ->assertSee('Operations is now inactive.')
            ->assertSee('data-tf-operation-transition', false);
    }

    public function test_unassigned_department_can_be_soft_deleted(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();
        $department = Department::create([
            'company_id' => $company->id,
            'name' => 'Temporary Team',
            'description' => null,
            'status' => 'inactive',
        ]);

        $this->actingAs($admin)
            ->delete(route('departments.destroy', $department))
            ->assertRedirect(route('departments.index'))
            ->assertSessionHas('success', 'Department deleted successfully.');

        $this->assertSoftDeleted('departments', ['id' => $department->id]);
    }

    public function test_department_cannot_be_deleted_or_moved_while_users_are_assigned(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $department = Department::query()->where('name', 'Operations')->firstOrFail();
        $otherCompany = Company::create([
            'name' => 'Contoso',
            'description' => null,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->delete(route('departments.destroy', $department))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->put(route('departments.update', $department), [
            'company_id' => $otherCompany->id,
            'name' => $department->name,
            'designation' => $department->designation,
            'description' => $department->description,
            'status' => $department->status,
        ])->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'company_id' => $department->company_id, 'deleted_at' => null]);
    }

    public function test_unassigned_departments_can_be_bulk_deleted(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();
        $department = Department::create([
            'company_id' => $company->id,
            'name' => 'Bulk Delete Department',
            'description' => null,
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($admin)->post(route('departments.bulk-destroy'), [
            'department_ids' => [$department->id],
        ]);

        $response->assertRedirect()
            ->assertSessionHas('success', '1 department(s) deleted successfully.');
        $this->assertSoftDeleted('departments', ['id' => $department->id]);
    }

    public function test_department_bulk_delete_rejects_departments_with_assigned_users(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $department = Department::query()->where('name', 'Operations')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('departments.bulk-destroy'), [
            'department_ids' => [$department->id],
        ]);

        $response->assertRedirect()
            ->assertSessionHas('error', 'Operations cannot be deleted because user accounts are assigned to it.');
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'deleted_at' => null]);
    }

    public function test_departments_can_be_exported_with_current_filters(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();
        Department::create([
            'company_id' => $company->id,
            'name' => 'Inactive Export Department',
            'description' => 'Do not export active',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($admin)->get(route('departments.export', ['status' => 'inactive']));

        $response->assertOk()->assertDownload()->assertStreamed();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Department,Company,Designation,Description,Users,Status,"Created Date"', $content);
        $this->assertStringContainsString('Inactive Export Department', $content);
        $this->assertStringNotContainsString('Operations', $content);
    }

    public function test_company_department_endpoint_returns_only_that_companys_departments(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();
        $otherCompany = Company::create([
            'name' => 'Contoso',
            'description' => null,
            'status' => 'active',
        ]);
        $inactiveDepartment = Department::create([
            'company_id' => $company->id,
            'name' => 'Archived Team',
            'designation' => 'Archived Specialist',
            'description' => null,
            'status' => 'inactive',
        ]);
        Department::create([
            'company_id' => $otherCompany->id,
            'name' => 'Finance',
            'designation' => 'Finance Analyst',
            'description' => null,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->getJson(route('departments.by-company', $company));

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment([
                'id' => $inactiveDepartment->id,
                'name' => 'Archived Team',
                'designation' => 'Archived Specialist',
                'status' => 'inactive',
            ])
            ->assertJsonFragment([
                'designation' => 'Archived Specialist',
            ])
            ->assertJsonMissing(['name' => 'Finance']);
    }

    public function test_guest_cannot_load_departments_for_a_company(): void
    {
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();

        Department::create([
            'company_id' => $company->id,
            'name' => 'Guest-visible Department',
            'status' => 'active',
        ]);

        Auth::guard('web')->logout();

        $this->getJson(route('departments.by-company', $company))->assertUnauthorized();
    }

    public function test_department_routes_require_department_view_permission(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->firstOrFail();

        $this->actingAs($user)
            ->get(route('departments.index'))
            ->assertForbidden();

        $this->getJson(route('departments.by-company', $company))
            ->assertForbidden();
    }

    public function test_department_management_table_shows_related_task_count(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();
        $department = Department::create([
            'company_id' => $company->id,
            'name' => 'Task Department Test',
            'status' => 'active',
        ]);

        Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Department Task 1',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'task_type' => 'process',
            'priority' => 'low',
            'status' => 'unassigned',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Completed',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('departments.index', ['search' => 'Task Department Test']));

        $response->assertOk();
        $response->assertSee('Tasks');
        $response->assertSee('Task Department Test');
        $response->assertSee(route('tasks.index', ['department_id' => $department->id]));
    }
}

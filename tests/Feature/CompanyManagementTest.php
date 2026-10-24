<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->actingAs(User::query()->where('email', 'admin@example.com')->firstOrFail());
    }

    public function test_super_admin_can_view_company_index(): void
    {
        $admin = User::query()->first();

        $response = $this->actingAs($admin)->get(route('companies.index'));

        $response->assertOk()
            ->assertSee('tf-user-summary', false)
            ->assertSee('tf-user-tabs', false)
            ->assertDontSee('tf-user-filter-dropdown', false)
            ->assertSee('Filter by company')
            ->assertSee('Filter by departments')
            ->assertSee('Filter by users')
            ->assertSee('Filter by tasks')
            ->assertSee('Filter by status')
            ->assertSee('data-organization-select-all', false)
            ->assertSee('data-organization-selection-form', false)
            ->assertSee('Export')
            ->assertSee('Add Company')
            ->assertSee('id="companyFormModal"', false)
            ->assertSee('Company information')
            ->assertSee('Acme Workspace');
    }

    public function test_company_create_and_edit_routes_open_the_management_modal(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('companies.create'))
            ->assertRedirect(route('companies.index', ['create' => 1]));

        $this->get(route('companies.index', ['create' => 1]))
            ->assertOk()
            ->assertSee('bootstrap.Modal.getOrCreateInstance(companyFormModal).show()', false)
            ->assertSee('Create company');

        $this->get(route('companies.edit', $company))
            ->assertRedirect(route('companies.index', ['edit' => $company->id]));

        $this->get(route('companies.index', ['edit' => $company->id]))
            ->assertOk()
            ->assertSee('bootstrap.Modal.getOrCreateInstance(companyFormModal).show()', false)
            ->assertSee('Edit company')
            ->assertSee('Acme Workspace');
    }

    public function test_company_toolbar_filters_by_departments_users_tasks_and_status(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $companyWithRelations = Company::create([
            'name' => 'Toolbar Filter Company',
            'status' => 'active',
        ]);
        $department = Department::create([
            'company_id' => $companyWithRelations->id,
            'name' => 'Toolbar Filter Department',
            'status' => 'active',
        ]);
        User::factory()->create([
            'company_id' => $companyWithRelations->id,
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Toolbar Filter Task',
            'company_id' => $companyWithRelations->id,
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
        $emptyCompany = Company::create([
            'name' => 'Toolbar Filter Empty Company',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get(route('companies.index', [
            'company_id' => $companyWithRelations->id,
            'departments' => 'with',
            'users' => 'with',
            'tasks' => 'with',
            'status' => 'active',
        ]));

        $response->assertOk()
            ->assertSee('<strong>Toolbar Filter Company</strong>', false)
            ->assertDontSee('<strong>Toolbar Filter Empty Company</strong>', false)
            ->assertSee('value="'.$companyWithRelations->id.'" selected', false)
            ->assertSee('value="with" selected', false)
            ->assertSee('value="active" selected', false);

        $this->get(route('companies.index', [
            'departments' => 'without',
            'users' => 'without',
            'tasks' => 'without',
            'status' => 'active',
        ]))->assertOk()
            ->assertSee('<strong>'.$emptyCompany->name.'</strong>', false)
            ->assertDontSee('<strong>Toolbar Filter Company</strong>', false);
    }

    public function test_company_can_be_created(): void
    {
        $admin = User::query()->first();

        $response = $this->actingAs($admin)->post(route('companies.store'), [
            'name' => 'Northwind Labs',
            'description' => 'Regional operations team',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('companies.index'));
        $this->assertDatabaseHas('companies', ['name' => 'Northwind Labs']);
    }

    public function test_company_status_can_be_toggled_from_its_actions_menu(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('companies.toggle-status', $company));

        $response->assertRedirect()
            ->assertSessionHas('success', 'Acme Workspace is now inactive.')
            ->assertSessionHas('operation_status.to', 'inactive');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'inactive']);
    }

    public function test_unassigned_companies_can_be_bulk_deleted(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::create([
            'name' => 'Bulk Delete Company',
            'description' => 'Empty company',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($admin)->post(route('companies.bulk-destroy'), [
            'company_ids' => [$company->id],
        ]);

        $response->assertRedirect()
            ->assertSessionHas('success', '1 company account(s) deleted successfully.');
        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    public function test_company_bulk_delete_rejects_companies_with_associated_departments(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('companies.bulk-destroy'), [
            'company_ids' => [$company->id],
        ]);

        $response->assertRedirect()
            ->assertSessionHas('error', 'Acme Workspace cannot be deleted because it has associated users or departments.');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'deleted_at' => null]);
    }

    public function test_companies_can_be_exported_with_current_filters(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        Company::create([
            'name' => 'Inactive Export Company',
            'description' => 'Do not export active',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($admin)->get(route('companies.export', ['status' => 'inactive']));

        $response->assertOk()->assertDownload()->assertStreamed();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Company,Description,Departments,Users,Status,"Created Date"', $content);
        $this->assertStringContainsString('Inactive Export Company', $content);
        $this->assertStringNotContainsString('Acme Workspace', $content);
    }

    public function test_company_management_table_shows_related_task_count(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::create([
            'name' => 'Task Testing Corp',
            'status' => 'active',
        ]);
        $department = Department::create([
            'company_id' => $company->id,
            'name' => 'Operations',
            'status' => 'active',
        ]);

        Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Company Task 1',
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

        $response = $this->actingAs($admin)->get(route('companies.index'));

        $response->assertOk();
        $response->assertSee('Tasks');
        $response->assertSee('Task Testing Corp');
        $response->assertSee(route('tasks.index', ['company_id' => $company->id]));
    }
}

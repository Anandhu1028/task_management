<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->actingAs(User::query()->where('email', 'admin@example.com')->firstOrFail());
    }

    public function test_super_admin_can_view_users_index(): void
    {
        $admin = User::query()->first();

        $response = $this->actingAs($admin)->get(route('users.index'));

        $response->assertOk()
            ->assertDontSee('tf-user-filter-dropdown', false)
            ->assertSee('Filter by company')
            ->assertSee('Filter by department')
            ->assertSee('Filter by tasks')
            ->assertSee('Filter by phone')
            ->assertSee('Filter by status')
            ->assertSee('data-bs-target="#addUserPanel"', false)
            ->assertSee('Add new user')
            ->assertSee('Total users')
            ->assertSee('Active rate')
            ->assertSee('Export')
            ->assertSee('data-user-bulk-delete-form', false)
            ->assertSee('Delete selected')
            ->assertSee('tf-user-row__company', false)
            ->assertSee('bi-buildings', false)
            ->assertSee('This account is protected from deletion')
            ->assertSee('newUserDepartmentField', false)
            ->assertSee('newUserDesignationField', false)
            ->assertSee('newUserRoleField', false)
            ->assertSee('name="role_id"', false)
            ->assertSee('name="designation"', false)
            ->assertSeeInOrder([
                'id="newUserName"',
                'id="newUserEmployeeId"',
                'id="newUserPhone"',
                'id="newUserEmail"',
                'Work information',
                'id="newUserCompany"',
                'id="newUserPassword"',
                'id="newUserPasswordConfirmation"',
            ], false)
            ->assertDontSee('name="role"', false)
            ->assertDontSee('type="file"', false);
    }

    public function test_user_toolbar_filters_by_company_department_tasks_phone_and_status(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'Acme Workspace')->firstOrFail();
        $department = Department::query()->where('company_id', $company->id)->where('status', 'active')->firstOrFail();
        $userWithPhoneAndTask = User::factory()->create([
            'name' => 'Toolbar Filter User With Phone',
            'email' => 'toolbar-with-phone@example.com',
            'phone' => '+1 555 111 2222',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        User::factory()->create([
            'name' => 'Toolbar Filter User Without Phone',
            'email' => 'toolbar-without-phone@example.com',
            'phone' => null,
            'company_id' => $company->id,
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Toolbar Filter Assigned Task',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'assigned',
            'assigned_to' => $userWithPhoneAndTask->id,
            'original_deadline' => now()->addDays(2)->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Deliverable',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('users.index', [
            'company_id' => $company->id,
            'department_id' => $department->id,
            'tasks' => 'with',
            'phone_presence' => 'with',
            'status' => 'active',
            'tab' => 'active',
        ]));

        $response->assertOk()
            ->assertSee('Toolbar Filter User With Phone')
            ->assertDontSee('Toolbar Filter User Without Phone')
            ->assertSee('value="'.$company->id.'" selected', false)
            ->assertSee('value="'.$department->id.'" selected', false)
            ->assertSee('value="with" selected', false)
            ->assertSee('value="active" selected', false);

        $this->get(route('users.index', [
            'company_id' => $company->id,
            'department_id' => $department->id,
            'tasks' => 'without',
            'phone_presence' => 'without',
            'status' => 'active',
            'tab' => 'active',
        ]))->assertOk()
            ->assertSee('Toolbar Filter User Without Phone')
            ->assertDontSee('Toolbar Filter User With Phone');
    }

    public function test_user_table_shows_company_style_pagination_and_entry_count(): void
    {
        User::factory()->count(11)->create();
        $totalUsers = User::query()->count();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('users.index'));

        $response->assertOk()
            ->assertSee("Showing 1–10 of {$totalUsers} users")
            ->assertSee('page=2', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('rel="next"', false);
    }

    public function test_success_feedback_uses_the_bottom_right_toast(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)
            ->withSession(['success' => 'User updated successfully.'])
            ->get(route('users.index'));

        $response->assertOk()
            ->assertSee('id="tfToast"', false)
            ->assertSee('data-tf-initial-toast', false)
            ->assertSee('User updated successfully.')
            ->assertDontSee('tf-management__notice--success');
    }

    public function test_admin_can_bulk_delete_selected_users(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $firstUser = User::factory()->create(['email' => 'bulk-one@example.com']);
        $secondUser = User::factory()->create(['email' => 'bulk-two@example.com']);

        $response = $this->actingAs($admin)->post(route('users.bulk-destroy'), [
            'user_ids' => [$firstUser->id, $secondUser->id],
        ]);

        $response->assertRedirect()
            ->assertSessionHas('success', '2 user account(s) deleted successfully.');
        $this->assertSoftDeleted('users', ['id' => $firstUser->id]);
        $this->assertSoftDeleted('users', ['id' => $secondUser->id]);
    }

    public function test_bulk_delete_rejects_protected_users_without_deleting_any_selection(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['email' => 'bulk-protection@example.com']);

        $response = $this->actingAs($admin)->post(route('users.bulk-destroy'), [
            'user_ids' => [$user->id, $admin->id],
        ]);

        $response->assertRedirect()
            ->assertSessionHas('error', 'You cannot delete your own account.');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
    }

    public function test_admin_can_toggle_a_user_account_status_from_the_actions_menu(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create([
            'name' => 'Status Test User',
            'email' => 'status-toggle@example.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->post(route('users.toggle-status', $user));

        $response->assertRedirect()
            ->assertSessionHas('success', $user->name.' account is now inactive.')
            ->assertSessionHas('operation_status', [
                'kind' => 'status',
                'user' => 'Status Test User',
                'from' => 'active',
                'to' => 'inactive',
                'message' => 'Status Test User is now inactive.',
            ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'inactive',
        ]);

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('tfCompletedOperation', false)
            ->assertSee('tf-operation__close', false)
            ->assertSee('Status Test User is now inactive.');
    }

    public function test_activating_a_user_shows_a_green_status_completion_dialog(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'activate-toggle@example.com',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($admin)->post(route('users.toggle-status', $user));

        $response->assertRedirect()
            ->assertSessionHas('success', $user->name.' account is now active.')
            ->assertSessionHas('operation_status.to', 'active')
            ->assertSessionHas('operation_status.from', 'inactive');

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('tfCompletedOperation', false)
            ->assertSee('tf-operation__close', false)
            ->assertSee($user->name.' is now active.');
    }

    public function test_admin_account_status_cannot_be_toggled(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $protectedAdmin = User::factory()->create([
            'email' => 'protected-status@example.com',
            'status' => 'active',
        ]);
        $protectedAdmin->assignRole('Admin');

        $response = $this->actingAs($admin)->post(route('users.toggle-status', $protectedAdmin));

        $response->assertRedirect()
            ->assertSessionHas('error', 'Protected Admin account status cannot be changed.');
        $this->assertDatabaseHas('users', [
            'id' => $protectedAdmin->id,
            'status' => 'active',
        ]);
    }

    public function test_users_can_be_exported_as_filtered_csv(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        User::factory()->create([
            'name' => 'Inactive Employee',
            'employee_id' => 'EMP-INACTIVE',
            'email' => 'inactive@example.com',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($admin)->get(route('users.export', ['tab' => 'inactive']));

        $response->assertOk()
            ->assertDownload()
            ->assertStreamed();

        $content = $response->streamedContent();

        $this->assertStringContainsString('User,"Employee ID",Company,Department,Designation,Phone,Email,Status', $content);
        $this->assertStringContainsString('inactive@example.com', $content);
        $this->assertStringNotContainsString('atlas.operations.employee01@example.test', $content);
    }

    public function test_add_user_route_redirects_to_the_users_list_and_opens_the_panel(): void
    {
        $admin = User::query()->first();

        $response = $this->actingAs($admin)->get(route('users.create'));

        $response->assertRedirect(route('users.index', ['create' => 1]));

        $this->get(route('users.index', ['create' => 1]))
            ->assertOk()
            ->assertSee('bootstrap.Modal.getOrCreateInstance(addUserPanel).show()', false);
    }

    public function test_edit_user_route_redirects_to_the_users_list_with_the_user_panel_open(): void
    {
        $admin = User::query()->first();
        $user = User::query()->where('email', 'atlas.operations.employee01@example.test')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('users.edit', $user));

        $response->assertRedirect(route('users.index', ['edit' => $user->id]));

        $this->get(route('users.index', ['edit' => $user->id]))
            ->assertOk()
            ->assertSee('Edit user')
            ->assertSee('atlas.operations.employee01@example.test')
            ->assertSee('current password is never shown. Leave blank to keep it unchanged.')
            ->assertSee('id="newUserPassword" type="password" name="password" class="form-control"', false)
            ->assertDontSee('id="newUserPassword" type="password" name="password" class="form-control" value=', false)
            ->assertDontSee($user->password)
            ->assertSee('bootstrap.Modal.getOrCreateInstance(addUserPanel).show()', false);
    }

    public function test_admin_can_update_a_user_from_the_list_panel(): void
    {
        $admin = User::query()->first();
        $user = User::query()->where('email', 'atlas.operations.employee01@example.test')->firstOrFail();
        $existingPasswordHash = $user->password;
        $user->assignRole('Employee');
        $company = Company::query()->firstOrFail();
        $department = Department::query()->where('company_id', $company->id)->firstOrFail();
        $role = $this->assignRoleToDesignation($department, 'Operations Lead', 'Employee');

        $response = $this->actingAs($admin)->put(route('users.update', $user), [
            'form_mode' => 'edit',
            'editing_user_id' => $user->id,
            'name' => 'Updated Employee',
            'employee_id' => $user->employee_id,
            'phone' => '+1 555 100 010',
            'email' => $user->email,
            'company_id' => $company->id,
            'department_id' => $department->id,
            'designation' => 'Operations Lead',
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Employee',
            'designation' => 'Operations Lead',
            'role_id' => $role->id,
        ]);
        $this->assertSame($existingPasswordHash, $user->fresh()->password);
        $this->assertSame(['Employee'], $user->fresh()->getRoleNames()->all());
        $response->assertSessionHas('operation_status', [
            'kind' => 'update',
            'user' => 'Updated Employee',
            'message' => 'Updated Employee information was updated successfully.',
        ]);

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('tfCompletedOperation', false)
            ->assertSee('tf-operation__close', false)
            ->assertSee('Updated Employee information was updated successfully.');
    }

    public function test_user_validation_errors_return_to_the_list_and_reopen_the_panel(): void
    {
        $admin = User::query()->first();

        $response = $this->actingAs($admin)
            ->from(route('users.index'))
            ->followingRedirects()
            ->post(route('users.store'), []);

        $response->assertOk()
            ->assertSee('bootstrap.Modal.getOrCreateInstance(addUserPanel).show()', false)
            ->assertSee('The name field is required.');
    }

    public function test_admin_can_create_user(): void
    {
        $company = Company::first();
        $department = Department::query()->where('company_id', $company->id)->firstOrFail();
        $department->update(['designation' => 'Operations Lead']);
        $role = $this->assignRoleToDesignation($department, 'Operations Lead', 'Team Leader');
        $admin = User::query()->first();

        $response = $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Asha Nair',
            'employee_id' => 'EMP-010',
            'phone' => '+1 555 100 010',
            'email' => 'asha@example.com',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'designation' => 'Operations Lead',
            'role_id' => $role->id,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'asha@example.com',
            'designation' => 'Operations Lead',
            'role_id' => $role->id,
        ]);
        $this->assertSame(
            ['Team Leader'],
            User::query()->where('email', 'asha@example.com')->firstOrFail()->getRoleNames()->all()
        );
    }

    public function test_admin_cannot_assign_a_new_user_to_an_inactive_department(): void
    {
        $company = Company::query()->firstOrFail();
        $department = Department::create([
            'company_id' => $company->id,
            'name' => 'Archived Department',
            'designation' => 'Team Member',
            'description' => null,
            'status' => 'inactive',
        ]);
        $role = $this->assignRoleToDesignation($department, 'Team Member', 'Employee');
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->from(route('users.index'))->post(route('users.store'), [
            'name' => 'Inactive Department User',
            'employee_id' => 'EMP-INACTIVE-DEPT',
            'phone' => '+1 555 200 020',
            'email' => 'inactive-department@example.com',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'designation' => 'Team Member',
            'role_id' => $role->id,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('users.index'))
            ->assertSessionHasErrors('department_id');
        $this->assertDatabaseMissing('users', ['email' => 'inactive-department@example.com']);
    }

    public function test_user_can_be_assigned_any_designation_configured_for_the_selected_department(): void
    {
        $company = Company::query()->firstOrFail();
        $department = Department::query()->where('company_id', $company->id)->firstOrFail();
        $developerRole = $this->assignRoleToDesignation($department, 'Developer', 'Employee');
        $designerRole = $this->assignRoleToDesignation($department, 'Designers', 'Team Leader');
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Designer User',
            'employee_id' => 'EMP-DESIGNER',
            'phone' => '+1 555 200 030',
            'email' => 'designer@example.com',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'designation' => 'Designers',
            'role_id' => $designerRole->id,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'status' => 'active',
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'designer@example.com',
            'designation' => 'Designers',
            'role_id' => $designerRole->id,
        ]);
        $designer = User::query()->where('email', 'designer@example.com')->firstOrFail();
        $this->assertSame(['Team Leader'], $designer->getRoleNames()->all());

        $departmentResponse = $this->get(route('departments.by-company', $company))->assertOk();
        $departmentData = collect($departmentResponse->json())->firstWhere('id', $department->id);
        $designationData = collect($departmentData['designations'])->keyBy('name');

        $this->assertSame($developerRole->id, $designationData['Developer']['roles'][0]['id']);
        $this->assertSame($designerRole->id, $designationData['Designers']['roles'][0]['id']);
    }

    public function test_user_cannot_be_assigned_a_designation_from_another_department(): void
    {
        $company = Company::query()->firstOrFail();
        $department = Department::query()->where('company_id', $company->id)->firstOrFail();
        $otherDepartment = Department::create([
            'company_id' => $company->id,
            'name' => 'Finance Department',
            'designation' => 'Finance Analyst',
            'description' => null,
            'status' => 'active',
        ]);
        $role = $this->assignRoleToDesignation($department, 'Operations Lead', 'Employee');
        $this->assignRoleToDesignation($otherDepartment, 'Finance Analyst', 'Team Leader');
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.store'), [
                'name' => 'Invalid Designation User',
                'employee_id' => 'EMP-INVALID-DESIGNATION',
                'phone' => '+1 555 200 031',
                'email' => 'invalid-designation@example.com',
                'company_id' => $company->id,
                'department_id' => $department->id,
                'designation' => 'Finance Analyst',
                'role_id' => $role->id,
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'status' => 'active',
            ])
            ->assertRedirect(route('users.index'))
            ->assertSessionHasErrors('designation');

        $this->assertDatabaseMissing('users', ['email' => 'invalid-designation@example.com']);
    }

    private function assignRoleToDesignation(Department $department, string $designationName, string $roleName): Role
    {
        $designation = $department->designations()->firstOrCreate(['name' => $designationName]);
        $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->firstOrFail();
        $designation->roles()->syncWithoutDetaching([$role->id]);

        return $role;
    }

    public function test_inactive_tab_only_lists_inactive_accounts(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        User::factory()->create([
            'name' => 'Inactive Employee',
            'employee_id' => 'EMP-INACTIVE',
            'email' => 'inactive@example.com',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($admin)->get(route('users.index', ['tab' => 'inactive']));

        $response->assertOk()
            ->assertSee('inactive@example.com')
            ->assertDontSee('atlas.operations.employee01@example.test');
    }

    public function test_authenticated_users_are_redirected_away_from_the_login_page(): void
    {
        $this->get('/login')->assertRedirect(route('dashboard'));
    }

    public function test_user_profile_shows_assigned_task_summary_and_recent_tasks(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('status', 'active')->firstOrFail();
        $department = Department::query()->where('company_id', $company->id)->where('status', 'active')->firstOrFail();
        $profileUser = User::factory()->create([
            'name' => 'Profile Task Worker',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'status' => 'active',
        ]);

        Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Profile In Progress Task',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'assigned_to' => $profileUser->id,
            'task_type' => 'process',
            'priority' => 'high',
            'status' => 'in_progress',
            'original_deadline' => now()->addDays(3)->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'estimated_hours' => 6,
            'expected_output' => 'Profile task output',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('users.show', $profileUser));

        $response->assertOk()
            ->assertSee('Profile Task Worker')
            ->assertSee('Profile details')
            ->assertSee('Account & access')
            ->assertSee('Total tasks')
            ->assertSee('Open')
            ->assertSee('Profile In Progress Task')
            ->assertSee('tf-profile-task-status--in_progress', false)
            ->assertSee('40 hrs');
    }

    public function test_user_management_table_shows_assigned_task_count(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('status', 'active')->firstOrFail();
        $department = Department::query()->where('company_id', $company->id)->where('status', 'active')->firstOrFail();

        $userWithTasks = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'name' => 'Task Assigned Worker',
            'status' => 'active',
        ]);

        Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Assigned Task 1',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'assigned_to' => $userWithTasks->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'unassigned',
            'original_deadline' => now()->addDays(3)->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'estimated_hours' => 5,
            'expected_output' => 'Output 1',
            'created_by' => $admin->id,
        ]);

        Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Assigned Task 2',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'assigned_to' => $userWithTasks->id,
            'task_type' => 'volume',
            'priority' => 'high',
            'status' => 'in_progress',
            'original_deadline' => now()->addDays(4)->toDateString(),
            'due_date' => now()->addDays(4)->toDateString(),
            'estimated_hours' => 8,
            'expected_output' => 'Output 2',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get(route('users.index', [
            'search' => 'Task Assigned Worker',
        ]));

        $response->assertOk();
        $response->assertSee('Tasks');
        $response->assertSee('Task Assigned Worker');
        $response->assertSee('2');
    }
}

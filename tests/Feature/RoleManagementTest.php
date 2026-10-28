<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentDesignation;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_role_management_and_protected_system_roles(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('roles.index'))
            ->assertOk()
            ->assertSee('Role Management')
            ->assertSee('All roles')
            ->assertSee('Protected')
            ->assertSee('aria-controls="settingsSubmenu"', false)
            ->assertSee(route('roles.index'), false)
            ->assertSee('css/roles.css', false)
            ->assertSee('Create Role')
            ->assertSee('aria-label="Admin is a protected system role and cannot be edited"', false)
            ->assertSee('aria-label="Admin is a protected system role and cannot be deleted"', false);
    }

    public function test_admin_role_sees_settings_when_role_permissions_are_missing(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $adminRole = Role::findByName('Admin', 'web');
        $adminRole->syncPermissions([]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-controls="settingsSubmenu"', false)
            ->assertSee(route('roles.index'), false);

        $this->get(route('roles.index'))->assertForbidden();
    }

    public function test_admin_can_create_a_role_with_selected_permissions(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $permission = Permission::findByName('users.view', 'web');

        $this->actingAs($admin)
            ->post(route('roles.store'), [
                'name' => 'Project Coordinator',
                'permissions' => [$permission->name],
            ])
            ->assertRedirect(route('roles.index'))
            ->assertSessionHas('success', 'Role created successfully.');

        $role = Role::findByName('Project Coordinator', 'web');

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_admin_can_update_role_details_and_sync_permissions(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $role = Role::create(['name' => 'Old Coordinator', 'guard_name' => 'web']);
        $previousPermission = Permission::findByName('users.view', 'web');
        $nextPermission = Permission::findByName('companies.view', 'web');
        $role->syncPermissions([$previousPermission]);

        $this->actingAs($admin)
            ->patch(route('roles.update', $role), [
                'name' => 'Project Coordinator',
                'permissions' => [$nextPermission->name],
            ])
            ->assertRedirect(route('roles.index'))
            ->assertSessionHas('success', 'Role updated successfully.');

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Project Coordinator',
        ]);
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $nextPermission->id,
        ]);
        $this->assertDatabaseMissing('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $previousPermission->id,
        ]);
    }

    public function test_custom_roles_cannot_receive_role_management_permissions(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('roles.store'), [
                'name' => 'Delegated Administrator',
                'permissions' => ['roles.view'],
            ])
            ->assertSessionHasErrors('permissions.0');

        $this->assertDatabaseMissing('roles', [
            'name' => 'Delegated Administrator',
            'guard_name' => 'web',
        ]);
    }

    public function test_system_roles_cannot_be_renamed_or_deleted(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $systemRole = Role::findByName('Admin', 'web');

        $this->actingAs($admin)
            ->patch(route('roles.update', $systemRole), ['name' => 'Renamed Admin'])
            ->assertForbidden();

        $this->delete(route('roles.destroy', $systemRole))->assertForbidden();

        $this->assertDatabaseHas('roles', [
            'id' => $systemRole->id,
            'name' => 'Admin',
        ]);
    }

    public function test_roles_assigned_to_users_or_designations_cannot_be_deleted(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $primaryRole = Role::create(['name' => 'Primary Assignment', 'guard_name' => 'web']);
        $pivotRole = Role::create(['name' => 'Spatie Assignment', 'guard_name' => 'web']);
        $designationRole = Role::create(['name' => 'Designation Assignment', 'guard_name' => 'web']);

        User::factory()->create(['role_id' => $primaryRole->id]);
        User::factory()->create()->assignRole($pivotRole);

        $designation = DepartmentDesignation::create([
            'department_id' => Department::query()->firstOrFail()->id,
            'name' => 'Role-linked designation',
        ]);
        $designation->roles()->attach($designationRole);

        $this->actingAs($admin);

        foreach ([$primaryRole, $pivotRole, $designationRole] as $role) {
            $this->delete(route('roles.destroy', $role))
                ->assertRedirect(route('roles.index'))
                ->assertSessionHas('error');

            $this->assertDatabaseHas('roles', ['id' => $role->id]);
        }
    }

    public function test_admin_can_delete_an_unused_custom_role(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $role = Role::create(['name' => 'Unused Project Role', 'guard_name' => 'web']);

        $this->actingAs($admin)
            ->delete(route('roles.destroy', $role))
            ->assertRedirect(route('roles.index'))
            ->assertSessionHas('success', 'Role deleted successfully.');

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_users_without_role_management_access_cannot_view_or_create_roles(): void
    {
        $this->seed();
        $employee = User::factory()->create();
        $employeeRole = Role::findByName('Employee', 'web');
        $employee->forceFill(['role_id' => $employeeRole->id])->save();
        $employee->syncRoles([$employeeRole]);

        $this->actingAs($employee)
            ->get(route('roles.index'))
            ->assertForbidden();

        $this->post(route('roles.store'), ['name' => 'Unauthorized Role'])
            ->assertForbidden();

        $this->assertDatabaseMissing('roles', [
            'name' => 'Unauthorized Role',
            'guard_name' => 'web',
        ]);
    }

    public function test_permission_seeding_preserves_custom_roles(): void
    {
        $this->seed();
        $customRole = Role::create(['name' => 'Persistent Custom Role', 'guard_name' => 'web']);

        $this->seed(PermissionSeeder::class);

        $this->assertDatabaseHas('roles', [
            'id' => $customRole->id,
            'name' => 'Persistent Custom Role',
        ]);
        $this->assertTrue(Role::findByName('Admin', 'web')->hasPermissionTo('roles.view'));
    }
}

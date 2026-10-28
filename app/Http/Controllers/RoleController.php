<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): View
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->withCount('permissions')
            ->with(['permissions' => fn (BelongsToMany $query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();

        $roleUserAssignments = DB::table('users')
            ->select('role_id', 'id as user_id')
            ->whereNotNull('role_id')
            ->union(
                DB::table(config('permission.table_names.model_has_roles'))
                    ->select(
                        (config('permission.column_names.role_pivot_key') ?: 'role_id').' as role_id',
                        (config('permission.column_names.model_morph_key') ?: 'model_id').' as user_id',
                    )
                    ->where('model_type', (new User)->getMorphClass())
            );

        $assignedUserCounts = DB::query()
            ->fromSub($roleUserAssignments, 'role_user_assignments')
            ->select('role_id')
            ->selectRaw('COUNT(*) as assigned_users_count')
            ->groupBy('role_id')
            ->pluck('assigned_users_count', 'role_id');

        $designationCounts = DB::table('department_designation_roles')
            ->select('role_id')
            ->selectRaw('COUNT(DISTINCT department_designation_id) as designations_count')
            ->groupBy('role_id')
            ->pluck('designations_count', 'role_id');

        $protectedRoleNames = config('role-management.protected_names', []);

        $roles->each(function (Role $role) use ($assignedUserCounts, $designationCounts, $protectedRoleNames): void {
            $role->setAttribute('assigned_users_count', (int) ($assignedUserCounts[$role->id] ?? 0));
            $role->setAttribute('designations_count', (int) ($designationCounts[$role->id] ?? 0));
            $role->setAttribute('is_protected', in_array($role->name, $protectedRoleNames, true));
        });

        $permissionGroups = Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', config('role-management.management_permissions', []))
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission): string => Str::headline(Str::before($permission->name, '.')))
            ->map(fn (Collection $permissions): Collection => $permissions->map(fn (Permission $permission): array => [
                'name' => $permission->name,
                'label' => Str::headline(Str::after($permission->name, '.')),
            ]));

        return view('roles.index', compact('roles', 'permissionGroups'));
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated): void {
            $role = Role::create([
                'name' => $validated['name'],
                'guard_name' => 'web',
            ]);
            $role->syncPermissions($validated['permissions'] ?? []);
        });

        return to_route('roles.index')->with('success', 'Role created successfully.');
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        abort_unless($role->guard_name === 'web', 404);

        $validated = $request->validated();

        DB::transaction(function () use ($role, $validated): void {
            $role->update(['name' => $validated['name']]);
            $role->syncPermissions($validated['permissions'] ?? []);
        });

        return to_route('roles.index')->with('success', 'Role updated successfully.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_unless($role->guard_name === 'web', 404);

        if (in_array($role->name, config('role-management.protected_names', []), true)) {
            abort(403);
        }

        $deleted = DB::transaction(function () use ($role): bool {
            Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();

            if ($this->hasAssignments($role)) {
                return false;
            }

            $role->delete();

            return true;
        });

        if (! $deleted) {
            return to_route('roles.index')
                ->with('error', 'This role cannot be deleted while it is assigned to users or department designations.');
        }

        return to_route('roles.index')->with('success', 'Role deleted successfully.');
    }

    private function hasAssignments(Role $role): bool
    {
        $rolePivotKey = config('permission.column_names.role_pivot_key') ?: 'role_id';

        return DB::table('users')->where('role_id', $role->id)->exists()
            || DB::table(config('permission.table_names.model_has_roles'))
                ->where($rolePivotKey, $role->id)
                ->where('model_type', (new User)->getMorphClass())
                ->exists()
            || DB::table('department_designation_roles')->where('role_id', $role->id)->exists();
    }
}

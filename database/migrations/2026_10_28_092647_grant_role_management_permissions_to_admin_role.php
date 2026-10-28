<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $rolePivot = $columnNames['role_pivot_key'] ?? 'role_id';
        $permissionPivot = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $managementPermissions = config('role-management.management_permissions', []);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($managementPermissions as $permissionName) {
            DB::table($tableNames['permissions'])->insertOrIgnore([
                'name' => $permissionName,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $adminRoleId = DB::table($tableNames['roles'])
            ->where('name', 'Admin')
            ->where('guard_name', 'web')
            ->value('id');

        if ($adminRoleId !== null) {
            $permissionIds = DB::table($tableNames['permissions'])
                ->where('guard_name', 'web')
                ->whereIn('name', $managementPermissions)
                ->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table($tableNames['role_has_permissions'])->insertOrIgnore([
                    $rolePivot => $adminRoleId,
                    $permissionPivot => $permissionId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $rolePivot = $columnNames['role_pivot_key'] ?? 'role_id';
        $permissionPivot = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $managementPermissions = config('role-management.management_permissions', []);
        $permissionIds = DB::table($tableNames['permissions'])
            ->where('guard_name', 'web')
            ->whereIn('name', $managementPermissions)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table($tableNames['role_has_permissions'])
                ->whereIn($rolePivot, function ($query) use ($tableNames): void {
                    $query->select('id')
                        ->from($tableNames['roles'])
                        ->where('name', 'Admin')
                        ->where('guard_name', 'web');
                })
                ->whereIn($permissionPivot, $permissionIds)
                ->delete();

            $permissionIdsInUse = DB::table($tableNames['role_has_permissions'])
                ->whereIn($permissionPivot, $permissionIds)
                ->pluck($permissionPivot)
                ->all();

            DB::table($tableNames['permissions'])
                ->whereIn('id', $permissionIds->diff($permissionIdsInUse))
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

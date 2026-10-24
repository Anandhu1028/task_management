<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('department_designation_roles', 'role_id')) {
            Schema::table('department_designation_roles', function (Blueprint $table): void {
                $table->foreignId('role_id')
                    ->nullable()
                    ->after('department_designation_id')
                    ->constrained('roles')
                    ->cascadeOnDelete();
            });
        }

        $roleAssignments = DB::table('department_designation_roles')->get(['id', 'name']);
        foreach ($roleAssignments as $assignment) {
            $roleId = DB::table('roles')
                ->where('name', $assignment->name)
                ->where('guard_name', 'web')
                ->value('id');

            if ($roleId === null) {
                throw new RuntimeException(
                    "Cannot link designation role '{$assignment->name}' because it does not exist in the roles table."
                );
            }

            DB::table('department_designation_roles')
                ->where('id', $assignment->id)
                ->update(['role_id' => $roleId]);
        }

        Schema::table('department_designation_roles', function (Blueprint $table): void {
            $table->unique(
                ['department_designation_id', 'role_id'],
                'ddr_designation_role_unique',
            );
            $table->dropUnique('ddr_designation_name_unique');
            $table->dropColumn('name');
            $table->foreignId('role_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('department_designation_roles', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('role_id');
        });

        foreach (DB::table('department_designation_roles')->get(['id', 'role_id']) as $assignment) {
            $roleName = DB::table('roles')->where('id', $assignment->role_id)->value('name');
            DB::table('department_designation_roles')
                ->where('id', $assignment->id)
                ->update(['name' => $roleName]);
        }

        Schema::table('department_designation_roles', function (Blueprint $table): void {
            $table->unique(
                ['department_designation_id', 'name'],
                'ddr_designation_name_unique',
            );
            $table->dropUnique('ddr_designation_role_unique');
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
            $table->string('name')->nullable(false)->change();
        });
    }
};

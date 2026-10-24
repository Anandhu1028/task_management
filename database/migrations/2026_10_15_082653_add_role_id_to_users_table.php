<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')
                ->nullable()
                ->after('department_id')
                ->constrained('roles')
                ->nullOnDelete();
        });

        if (! Schema::hasTable('model_has_roles')) {
            return;
        }

        $singleRoleAssignments = DB::table('model_has_roles')
            ->select('model_id', DB::raw('MIN(role_id) as role_id'))
            ->where('model_type', 'App\\Models\\User')
            ->groupBy('model_id')
            ->havingRaw('COUNT(DISTINCT role_id) = 1')
            ->get();

        foreach ($singleRoleAssignments as $assignment) {
            DB::table('users')
                ->where('id', $assignment->model_id)
                ->whereNull('role_id')
                ->update(['role_id' => $assignment->role_id]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};

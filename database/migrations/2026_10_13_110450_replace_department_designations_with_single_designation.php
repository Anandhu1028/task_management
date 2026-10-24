<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $conflictingDepartments = DB::table('users')
            ->select('department_id')
            ->whereNotNull('department_id')
            ->whereNotNull('designation')
            ->groupBy('department_id')
            ->havingRaw('COUNT(DISTINCT designation) > 1')
            ->pluck('department_id');

        if ($conflictingDepartments->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot reduce departments to one designation while multiple user designations exist for department IDs: '
                .$conflictingDepartments->implode(', ').'. Reconcile those users before rerunning this migration.'
            );
        }

        Schema::table('departments', function (Blueprint $table): void {
            $table->string('designation')->default('Team Member')->after('name');
        });

        DB::table('departments')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($departments): void {
                foreach ($departments as $department) {
                    $designation = DB::table('users')
                        ->where('department_id', $department->id)
                        ->whereNotNull('designation')
                        ->value('designation')
                        ?? DB::table('department_designations')
                            ->where('department_id', $department->id)
                            ->orderBy('id')
                            ->value('name')
                        ?? 'Team Member';

                    DB::table('departments')
                        ->where('id', $department->id)
                        ->update(['designation' => $designation]);
                }
            });

        Schema::dropIfExists('department_designations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('department_designations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['department_id', 'name']);
        });

        $timestamp = now();
        DB::table('departments')
            ->select(['id', 'designation'])
            ->orderBy('id')
            ->chunkById(500, function ($departments) use ($timestamp): void {
                $designations = $departments->map(fn ($department): array => [
                    'department_id' => $department->id,
                    'name' => $department->designation,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all();

                DB::table('department_designations')->insert($designations);
            });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('designation');
        });
    }
};

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
        Schema::create('department_designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['department_id', 'name']);
        });

        DB::table('departments')
            ->whereNotNull('designation')
            ->orderBy('id')
            ->chunkById(500, function ($departments): void {
                $timestamp = now();
                $designations = $departments->map(fn ($department): array => [
                    'department_id' => $department->id,
                    'name' => $department->designation,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all();

                DB::table('department_designations')->insertOrIgnore($designations);
            });

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('designation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('designation')->default('Team Member');
        });

        DB::table('departments')
            ->orderBy('id')
            ->chunkById(500, function ($departments): void {
                foreach ($departments as $department) {
                    $designation = DB::table('department_designations')
                        ->where('department_id', $department->id)
                        ->orderBy('id')
                        ->value('name');

                    if ($designation !== null) {
                        DB::table('departments')
                            ->where('id', $department->id)
                            ->update(['designation' => $designation]);
                    }
                }
            });

        Schema::dropIfExists('department_designations');
    }
};

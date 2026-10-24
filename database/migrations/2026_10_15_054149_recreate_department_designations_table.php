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
        if (! Schema::hasTable('department_designations')) {
            Schema::create('department_designations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('department_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->timestamps();
                $table->unique(['department_id', 'name']);
            });
        }

        // Backfill from departments.designation if exists
        if (Schema::hasColumn('departments', 'designation')) {
            DB::table('departments')
                ->whereNotNull('designation')
                ->where('designation', '!=', '')
                ->orderBy('id')
                ->chunkById(500, function ($departments): void {
                    $timestamp = now();
                    foreach ($departments as $department) {
                        DB::table('department_designations')->insertOrIgnore([
                            'department_id' => $department->id,
                            'name' => trim($department->designation),
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                    }
                });
        }

        // Also backfill distinct designations from users belonging to departments
        if (Schema::hasColumn('users', 'designation')) {
            $userDesignations = DB::table('users')
                ->select('department_id', 'designation')
                ->whereNotNull('department_id')
                ->whereNotNull('designation')
                ->where('designation', '!=', '')
                ->distinct()
                ->get();

            $timestamp = now();
            foreach ($userDesignations as $ud) {
                DB::table('department_designations')->insertOrIgnore([
                    'department_id' => $ud->department_id,
                    'name' => trim($ud->designation),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_designations');
    }
};

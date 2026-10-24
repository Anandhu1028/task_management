<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('department_designation_roles')) {
            Schema::table('department_designation_roles', function (Blueprint $table): void {
                $table->unique(['department_designation_id', 'name'], 'ddr_designation_name_unique');
            });

            return;
        }

        Schema::create('department_designation_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_designation_id')
                ->constrained('department_designations')
                ->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['department_designation_id', 'name'], 'ddr_designation_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_designation_roles');
    }
};

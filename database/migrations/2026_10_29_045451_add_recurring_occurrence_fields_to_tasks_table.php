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
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('recurring_task_template_id')
                ->nullable()
                ->after('estimated_hours')
                ->constrained('recurring_task_templates')
                ->nullOnDelete();
            $table->date('occurrence_date')->nullable()->after('recurring_task_template_id');

            $table->unique(
                ['recurring_task_template_id', 'occurrence_date'],
                'tasks_recurring_template_occurrence_unique',
            );
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->decimal('estimated_hours', 8, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('tasks')->whereNull('estimated_hours')->exists()) {
            throw new RuntimeException('Cannot require estimated effort while recurring tasks have no estimate.');
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique('tasks_recurring_template_occurrence_unique');
            $table->dropConstrainedForeignId('recurring_task_template_id');
            $table->dropColumn('occurrence_date');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->decimal('estimated_hours', 8, 2)->nullable(false)->change();
        });
    }
};

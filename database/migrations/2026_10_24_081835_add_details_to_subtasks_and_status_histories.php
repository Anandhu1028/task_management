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
        Schema::table('subtasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('subtasks', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
            if (! Schema::hasColumn('subtasks', 'priority')) {
                $table->string('priority', 20)->default('medium')->after('description');
            }
            if (! Schema::hasColumn('subtasks', 'status')) {
                $table->string('status', 30)->default('todo')->after('priority');
            }
            if (! Schema::hasColumn('subtasks', 'start_date')) {
                $table->date('start_date')->nullable()->after('status');
            }
        });

        Schema::table('task_status_histories', function (Blueprint $table): void {
            if (! Schema::hasColumn('task_status_histories', 'subtask_id')) {
                $table->foreignId('subtask_id')->nullable()->after('task_id')->constrained('subtasks')->cascadeOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_status_histories', function (Blueprint $table): void {
            if (Schema::hasColumn('task_status_histories', 'subtask_id')) {
                $table->dropForeign(['subtask_id']);
                $table->dropColumn('subtask_id');
            }
        });

        Schema::table('subtasks', function (Blueprint $table): void {
            $columnsToDrop = [];
            foreach (['start_date', 'status', 'priority', 'description'] as $column) {
                if (Schema::hasColumn('subtasks', $column)) {
                    $columnsToDrop[] = $column;
                }
            }
            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};

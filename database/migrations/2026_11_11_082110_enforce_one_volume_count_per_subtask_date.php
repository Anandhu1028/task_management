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
        Schema::table('task_volume_updates', function (Blueprint $table): void {
            $table->index('task_id', 'task_volume_updates_task_id_index');
            $table->dropUnique('task_volume_updates_scope_unique');
            $table->unique(['task_id', 'subtask_id', 'update_date'], 'task_volume_updates_subtask_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_volume_updates', function (Blueprint $table): void {
            $table->dropUnique('task_volume_updates_subtask_date_unique');
            $table->unique(['task_id', 'subtask_id', 'user_id', 'update_date'], 'task_volume_updates_scope_unique');
            $table->dropIndex('task_volume_updates_task_id_index');
        });
    }
};

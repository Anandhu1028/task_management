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
        Schema::table('task_status_histories', function (Blueprint $table) {
            $table->foreignId('daily_task_id')->nullable()->after('subtask_id')
                ->constrained('daily_tasks')->nullOnDelete();
            $table->index(['daily_task_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_status_histories', function (Blueprint $table) {
            $table->dropIndex(['daily_task_id', 'created_at']);
            $table->dropConstrainedForeignId('daily_task_id');
        });
    }
};

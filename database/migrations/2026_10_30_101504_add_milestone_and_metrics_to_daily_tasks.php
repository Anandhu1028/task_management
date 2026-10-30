<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_tasks', function (Blueprint $table) {
            $table->foreignId('milestone_id')->nullable()->after('subtask_id')
                ->constrained('milestones')->nullOnDelete();
            $table->json('operational_metrics')->nullable()->after('estimated_minutes');
            $table->index(['milestone_id', 'work_date']);
        });

        Schema::table('task_status_histories', function (Blueprint $table) {
            $table->foreignId('milestone_id')->nullable()->after('daily_task_id')
                ->constrained('milestones')->nullOnDelete();
            $table->index(['milestone_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('task_status_histories', function (Blueprint $table) {
            $table->dropIndex(['milestone_id', 'created_at']);
            $table->dropConstrainedForeignId('milestone_id');
        });

        Schema::table('daily_tasks', function (Blueprint $table) {
            $table->dropIndex(['milestone_id', 'work_date']);
            $table->dropConstrainedForeignId('milestone_id');
            $table->dropColumn('operational_metrics');
        });
    }
};

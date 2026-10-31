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
            $table->date('activity_date')->nullable()->after('event_type');
            $table->index(['task_id', 'activity_date'], 'task_status_histories_task_activity_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_status_histories', function (Blueprint $table) {
            $table->dropIndex('task_status_histories_task_activity_date_index');
            $table->dropColumn('activity_date');
        });
    }
};

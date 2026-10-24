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
        Schema::table('subtasks', function (Blueprint $table): void {
            $table->decimal('estimated_hours', 8, 2)->nullable()->after('start_date');
            $table->unsignedTinyInteger('progress_percentage')->default(0)->after('estimated_hours');
            $table->timestamp('actual_started_at')->nullable()->after('completed_at');
            $table->unsignedInteger('actual_elapsed_minutes')->nullable()->after('actual_started_at');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('task_status_histories', function (Blueprint $table): void {
                $table->dropForeign('task_status_histories_subtask_id_foreign');
                $table->foreign('subtask_id')
                    ->references('id')
                    ->on('subtasks')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('task_status_histories', function (Blueprint $table): void {
                $table->dropForeign('task_status_histories_subtask_id_foreign');
                $table->foreign('subtask_id')
                    ->references('id')
                    ->on('subtasks')
                    ->cascadeOnDelete();
            });
        }

        Schema::table('subtasks', function (Blueprint $table): void {
            $table->dropColumn([
                'estimated_hours',
                'progress_percentage',
                'actual_started_at',
                'actual_elapsed_minutes',
            ]);
        });
    }
};

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
        Schema::create('subtask_daily_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subtask_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task_title');
            $table->string('subtask_title');
            $table->string('employee_name');
            $table->date('work_date');
            $table->text('work_description');
            $table->text('work_completed')->nullable();
            $table->string('current_status', 30);
            $table->unsignedInteger('time_spent_minutes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date'], 'subtask_daily_updates_employee_date_index');
            $table->index(['task_id', 'work_date'], 'subtask_daily_updates_task_date_index');
            $table->index(['subtask_id', 'work_date'], 'subtask_daily_updates_subtask_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subtask_daily_updates');
    }
};

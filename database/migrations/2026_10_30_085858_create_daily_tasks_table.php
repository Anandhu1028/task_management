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
        Schema::create('daily_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subtask_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->date('work_date');
            $table->time('planned_start_time');
            $table->time('planned_end_time');
            $table->date('original_work_date');
            $table->time('original_planned_start_time');
            $table->time('original_planned_end_time');
            $table->string('priority', 20)->default('medium');
            $table->string('status', 30)->default('planned');
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->timestamp('actual_started_at')->nullable();
            $table->timestamp('actual_ended_at')->nullable();
            $table->timestamp('missed_at')->nullable();
            $table->text('hold_reason')->nullable();
            $table->text('blockers')->nullable();
            $table->text('remarks')->nullable();
            $table->text('completion_remarks')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'work_date', 'status']);
            $table->index(['task_id', 'work_date', 'status']);
            $table->index(['subtask_id', 'work_date']);
            $table->index(['status', 'work_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_tasks');
    }
};

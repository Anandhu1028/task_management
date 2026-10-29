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
        Schema::create('recurring_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained('tasks')->cascadeOnDelete();
            $table->string('repeat_frequency', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('timezone', 64);
            $table->enum('status', ['active', 'paused', 'completed'])->default('active');
            $table->timestamps();
            $table->index(['status', 'start_date', 'end_date']);
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('recurring_task_id')->nullable()->after('estimated_hours')
                ->constrained('recurring_tasks')->nullOnDelete();
            $table->unique(['recurring_task_id', 'occurrence_date'], 'tasks_recurring_task_occurrence_unique');
        });

        if (! Schema::hasTable('recurring_task_templates')) {
            return;
        }

        DB::table('recurring_task_templates')->orderBy('id')->each(function (object $template): void {
            $taskId = DB::table('tasks')->insertGetId([
                'task_code' => 'RCS-'.str_pad((string) $template->id, 6, '0', STR_PAD_LEFT),
                'title' => $template->title,
                'description' => $template->description,
                'company_id' => $template->company_id,
                'department_id' => $template->department_id,
                'created_by' => $template->created_by,
                'priority' => $template->priority,
                'task_type' => 'recurring',
                'status' => 'unassigned',
                'progress_percentage' => 0,
                'expected_output' => $template->expected_output,
                'created_at' => $template->created_at,
                'updated_at' => $template->updated_at,
            ]);
            $scheduleId = DB::table('recurring_tasks')->insertGetId([
                'task_id' => $taskId,
                'repeat_frequency' => $template->repeat_frequency,
                'start_date' => $template->start_date,
                'end_date' => $template->end_date,
                'start_time' => $template->start_time,
                'end_time' => $template->end_time,
                'timezone' => $template->timezone,
                'status' => $template->status === 'cancelled' ? 'completed' : $template->status,
                'created_at' => $template->created_at,
                'updated_at' => $template->updated_at,
            ]);

            DB::table('recurring_task_template_pocs')->where('recurring_task_template_id', $template->id)
                ->orderBy('id')->each(fn (object $poc) => DB::table('task_pocs')->insert([
                    'task_id' => $taskId, 'user_id' => $poc->user_id, 'created_at' => $poc->created_at, 'updated_at' => $poc->updated_at,
                ]));
            DB::table('recurring_task_template_assignees')->where('recurring_task_template_id', $template->id)
                ->orderBy('id')->each(fn (object $assignee) => DB::table('task_assignments')->insert([
                    'task_id' => $taskId, 'user_id' => $assignee->user_id, 'assigned_by' => $template->created_by,
                    'reason' => 'Migrated recurring schedule assignment', 'assigned_at' => $template->created_at,
                    'is_active' => true, 'created_at' => $template->created_at, 'updated_at' => $template->updated_at,
                ]));
            DB::table('tasks')->where('recurring_task_template_id', $template->id)->update([
                'recurring_task_id' => $scheduleId, 'task_type' => 'recurring',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('recurring_tasks')->exists()) {
            throw new RuntimeException('Recurring schedule data has been migrated; rolling back this migration would destroy it.');
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique('tasks_recurring_task_occurrence_unique');
            $table->dropConstrainedForeignId('recurring_task_id');
        });

        Schema::dropIfExists('recurring_tasks');
    }
};

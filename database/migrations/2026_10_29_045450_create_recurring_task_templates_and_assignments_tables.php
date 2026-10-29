<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recurring_task_templates')) {
            Schema::create('recurring_task_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->text('description')->nullable();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('department_id')->constrained()->restrictOnDelete();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->enum('task_type', ['process', 'volume', 'project'])->default('process');
                $table->enum('priority', ['low', 'medium', 'high', 'critical']);
                $table->text('expected_output');
                $table->date('original_deadline')->nullable();
                $table->decimal('estimated_hours', 8, 2)->nullable();
                $table->string('repeat_frequency', 20)->default('daily');
                $table->date('start_date');
                $table->date('end_date');
                $table->time('start_time');
                $table->time('end_time');
                $table->string('timezone', 64);
                $table->enum('status', ['active', 'paused', 'cancelled'])->default('active');
                $table->json('attachments')->nullable();
                $table->timestamps();

                $table->index(['status', 'start_date', 'end_date']);
            });
        }

        if (! Schema::hasTable('recurring_task_template_pocs')) {
            Schema::create('recurring_task_template_pocs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('recurring_task_template_id');
                $table->foreign('recurring_task_template_id', 'recurring_template_pocs_template_fk')
                    ->references('id')
                    ->on('recurring_task_templates')
                    ->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['recurring_task_template_id', 'user_id'],
                    'recurring_template_pocs_unique',
                );
            });
        } elseif (! Schema::hasIndex('recurring_task_template_pocs', ['recurring_task_template_id', 'user_id'], 'unique')) {
            Schema::table('recurring_task_template_pocs', function (Blueprint $table): void {
                $table->unique(
                    ['recurring_task_template_id', 'user_id'],
                    'recurring_template_pocs_unique',
                );
            });
        }

        if (! Schema::hasTable('recurring_task_template_assignees')) {
            Schema::create('recurring_task_template_assignees', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('recurring_task_template_id');
                $table->foreign('recurring_task_template_id', 'recurring_template_assignees_template_fk')
                    ->references('id')
                    ->on('recurring_task_templates')
                    ->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['recurring_task_template_id', 'user_id'],
                    'recurring_template_assignees_unique',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_task_template_assignees');
        Schema::dropIfExists('recurring_task_template_pocs');
        Schema::dropIfExists('recurring_task_templates');
    }
};

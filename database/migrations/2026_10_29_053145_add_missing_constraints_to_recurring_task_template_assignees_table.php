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
        if (! Schema::hasIndex('recurring_task_template_assignees', ['recurring_task_template_id', 'user_id'], 'unique')) {
            Schema::table('recurring_task_template_assignees', function (Blueprint $table): void {
                $table->unique(
                    ['recurring_task_template_id', 'user_id'],
                    'recurring_template_assignees_unique',
                );
            });
        }

        if (! Schema::hasIndex('recurring_task_template_assignees', 'recurring_template_assignees_user_idx')) {
            Schema::table('recurring_task_template_assignees', function (Blueprint $table): void {
                $table->index('user_id', 'recurring_template_assignees_user_idx');
            });
        }

        if (! Schema::hasForeignKey('recurring_task_template_assignees', 'recurring_template_assignees_template_fk')) {
            Schema::table('recurring_task_template_assignees', function (Blueprint $table): void {
                $table->foreign('recurring_task_template_id', 'recurring_template_assignees_template_fk')
                    ->references('id')
                    ->on('recurring_task_templates')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasForeignKey('recurring_task_template_assignees', 'recurring_template_assignees_user_fk')) {
            Schema::table('recurring_task_template_assignees', function (Blueprint $table): void {
                $table->foreign('user_id', 'recurring_template_assignees_user_fk')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // These constraints are required by the original recurring-template schema.
    }
};

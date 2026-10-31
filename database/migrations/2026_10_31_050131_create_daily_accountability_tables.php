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
        Schema::create('daily_accountability_settings', function (Blueprint $table) {
            $table->id();
            $table->time('cutoff_time');
            $table->json('working_days');
            $table->unsignedInteger('missed_penalty_points')->default(0);
            $table->unsignedInteger('late_penalty_points')->default(0);
            $table->boolean('late_replaces_missed')->default(true);
            $table->unsignedInteger('repeat_miss_threshold')->default(0);
            $table->unsignedInteger('repeat_miss_penalty_points')->default(0);
            $table->timestamps();
        });

        Schema::create('daily_accountability_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 500);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'starts_on', 'ends_on', 'active'], 'accountability_exemptions_employee_dates_index');
        });

        Schema::create('daily_accountability_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('daily_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_name');
            $table->string('task_title');
            $table->string('daily_task_title');
            $table->foreignId('source_history_id')->nullable()->constrained('task_status_histories')->nullOnDelete();
            $table->foreignId('reverses_event_id')->nullable()->constrained('daily_accountability_events')->nullOnDelete();
            $table->string('event_type', 40);
            $table->date('report_date');
            $table->integer('points')->default(0);
            $table->string('description', 1000);
            $table->string('event_key', 191)->unique();
            $table->timestamps();

            $table->index(['employee_id', 'report_date'], 'accountability_events_employee_date_index');
            $table->index(['task_id', 'report_date'], 'accountability_events_task_date_index');
            $table->index(['daily_task_id', 'report_date'], 'accountability_events_daily_task_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_accountability_events');
        Schema::dropIfExists('daily_accountability_exemptions');
        Schema::dropIfExists('daily_accountability_settings');
    }
};

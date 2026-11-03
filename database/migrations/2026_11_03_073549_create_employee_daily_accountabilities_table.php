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
        Schema::create('employee_daily_accountabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('employee_name');
            $table->date('activity_date');
            $table->string('activity_status', 20)->default('pending');
            $table->unsignedInteger('activity_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->boolean('warning_status')->default(false);
            $table->timestamp('cutoff_evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'activity_date'], 'employee_daily_accountabilities_employee_date_unique');
            $table->index(['activity_date', 'warning_status'], 'employee_daily_accountabilities_date_warning_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_daily_accountabilities');
    }
};

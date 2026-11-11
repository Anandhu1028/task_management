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
        Schema::table('subtask_daily_updates', function (Blueprint $table) {
            $table->unsignedInteger('completed_count')->nullable()->after('work_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subtask_daily_updates', function (Blueprint $table) {
            $table->dropColumn('completed_count');
        });
    }
};

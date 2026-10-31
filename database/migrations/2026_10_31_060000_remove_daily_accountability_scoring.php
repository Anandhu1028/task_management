<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_accountability_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'missed_penalty_points',
                'late_penalty_points',
                'late_replaces_missed',
                'repeat_miss_threshold',
                'repeat_miss_penalty_points',
            ]);
        });

        Schema::table('daily_accountability_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reverses_event_id');
            $table->dropColumn('points');
        });
    }

    public function down(): void
    {
        Schema::table('daily_accountability_settings', function (Blueprint $table): void {
            $table->unsignedInteger('missed_penalty_points')->default(0);
            $table->unsignedInteger('late_penalty_points')->default(0);
            $table->boolean('late_replaces_missed')->default(true);
            $table->unsignedInteger('repeat_miss_threshold')->default(0);
            $table->unsignedInteger('repeat_miss_penalty_points')->default(0);
        });

        Schema::table('daily_accountability_events', function (Blueprint $table): void {
            $table->integer('points')->default(0);
            $table->foreignId('reverses_event_id')
                ->nullable()
                ->constrained('daily_accountability_events')
                ->nullOnDelete();
        });
    }
};

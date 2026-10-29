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
        Schema::table('recurring_task_templates', function (Blueprint $table): void {
            $table->enum('task_type', ['recurring', 'process', 'volume', 'project'])
                ->default('recurring')
                ->change();
        });

        DB::table('recurring_task_templates')
            ->where('task_type', 'recurring')
            ->update(['task_type' => 'process']);

        Schema::table('recurring_task_templates', function (Blueprint $table): void {
            $table->enum('task_type', ['process', 'volume', 'project'])
                ->default('process')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_task_templates', function (Blueprint $table): void {
            $table->enum('task_type', ['recurring', 'process', 'volume', 'project'])
                ->default('process')
                ->change();
        });

        DB::table('recurring_task_templates')
            ->whereIn('task_type', ['process', 'volume', 'project'])
            ->update(['task_type' => 'recurring']);

        Schema::table('recurring_task_templates', function (Blueprint $table): void {
            $table->enum('task_type', ['recurring'])
                ->default('recurring')
                ->change();
        });
    }
};

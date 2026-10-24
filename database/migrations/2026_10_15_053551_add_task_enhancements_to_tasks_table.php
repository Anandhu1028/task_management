<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->enum('task_type', ['process', 'volume', 'recurring', 'project'])
                ->default('process')
                ->after('project');

            $table->date('original_deadline')
                ->nullable()
                ->after('task_type')
                ->comment('Immutable after creation');

            $table->text('expected_output')
                ->nullable()
                ->after('original_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['task_type', 'original_deadline', 'expected_output']);
        });
    }
};

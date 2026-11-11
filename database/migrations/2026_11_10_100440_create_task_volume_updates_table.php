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
        Schema::create('task_volume_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_volume_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subtask_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('update_date');
            $table->unsignedInteger('completed_count');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'subtask_id', 'user_id', 'update_date'], 'task_volume_updates_scope_unique');
            $table->index(['task_volume_id', 'update_date']);
            $table->index(['user_id', 'update_date']);
            $table->index(['subtask_id', 'update_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_volume_updates');
    }
};

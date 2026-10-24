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
        DB::table('tasks')
            ->whereNull('due_date')
            ->whereNotNull('original_deadline')
            ->update(['due_date' => DB::raw('original_deadline')]);

        $tasksMissingRequiredData = DB::table('tasks')
            ->where(function ($query): void {
                $query->whereNull('department_id')
                    ->orWhereNull('original_deadline')
                    ->orWhereNull('estimated_hours')
                    ->orWhereNull('expected_output');
            })
            ->count();

        if ($tasksMissingRequiredData > 0) {
            throw new RuntimeException(
                "Cannot enforce required task fields: {$tasksMissingRequiredData} existing task(s) need a department, original deadline, estimated effort, and expected output before this migration can run."
            );
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable(false)->change();
            $table->date('original_deadline')->nullable(false)->change();
            $table->date('due_date')->nullable(false)->change();
            $table->decimal('estimated_hours', 8, 2)->nullable(false)->change();
            $table->text('expected_output')->nullable(false)->change();
            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->change();
            $table->date('original_deadline')->nullable()->change();
            $table->date('due_date')->nullable()->change();
            $table->decimal('estimated_hours', 8, 2)->nullable()->change();
            $table->text('expected_output')->nullable()->change();
            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();
        });
    }
};

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
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('original_deadline')->nullable()->change();
            $table->date('due_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('tasks')->whereNull('original_deadline')->orWhereNull('due_date')->exists()) {
            throw new RuntimeException('Cannot restore required task deadlines while tasks have no original or due date.');
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->date('original_deadline')->nullable(false)->change();
            $table->date('due_date')->nullable(false)->change();
        });
    }
};

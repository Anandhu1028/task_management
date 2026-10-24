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
        Schema::table('subtasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('subtasks', 'estimated_hours')) {
                $table->decimal('estimated_hours', 8, 2)->nullable()->after('due_date');
            }
            if (! Schema::hasColumn('subtasks', 'actual_hours')) {
                $table->decimal('actual_hours', 8, 2)->nullable()->after('estimated_hours');
            }
            if (! Schema::hasColumn('subtasks', 'progress_percentage')) {
                $table->unsignedTinyInteger('progress_percentage')->default(0)->after('status');
            }
            if (! Schema::hasColumn('subtasks', 'actual_start_at')) {
                $table->timestamp('actual_start_at')->nullable()->after('start_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subtasks', function (Blueprint $table): void {
            $cols = ['actual_start_at', 'progress_percentage', 'actual_hours', 'estimated_hours'];
            $drop = array_filter($cols, fn ($col) => Schema::hasColumn('subtasks', $col));
            if (! empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};

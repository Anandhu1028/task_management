<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a designation to each existing department.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('designation')->default('Team Member')->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('designation');
        });
    }
};

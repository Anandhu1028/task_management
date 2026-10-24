<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'employee_id')) {
                $table->string('employee_id')->nullable()->after('name');
            }

            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('employee_id');
            }

            if (! Schema::hasColumn('users', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('phone')->constrained('companies')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'department_id')) {
                $table->foreignId('department_id')->nullable()->after('company_id')->constrained('departments')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'designation')) {
                $table->string('designation')->nullable()->after('department_id');
            }

            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('active')->after('designation');
            }

            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('employee_id');
            $table->index('status');
            $table->index('company_id');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['company_id']);
            $table->dropIndex(['department_id']);

            $table->dropColumn(['employee_id', 'phone', 'company_id', 'department_id', 'designation', 'status', 'deleted_at']);
        });
    }
};

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
        if (! Schema::hasTable('mcp_audit_logs')) {
            Schema::create('mcp_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->string('client_id', 100)->nullable()->index();
                $table->string('tool_name', 100)->nullable()->index();
                $table->string('action', 50)->index(); // call, authorize, token, revoke
                $table->string('resource_type', 50)->nullable();
                $table->string('resource_id', 100)->nullable();
                $table->string('status', 30)->default('success'); // success, error, denied
                $table->text('error_message')->nullable();
                $table->decimal('duration_ms', 8, 2)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};

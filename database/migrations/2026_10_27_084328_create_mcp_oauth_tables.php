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
        Schema::create('mcp_oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->unique();
            $table->string('name');
            $table->string('secret')->nullable();
            $table->json('redirect_uris');
            $table->json('scopes')->nullable();
            $table->boolean('is_confidential')->default(false);
            $table->boolean('is_revoked')->default(false);
            $table->timestamps();
        });

        Schema::create('mcp_oauth_auth_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->string('client_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('redirect_uri');
            $table->string('code_challenge');
            $table->string('code_challenge_method', 20)->default('S256');
            $table->json('scopes')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('mcp_oauth_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_id', 64)->unique();
            $table->string('client_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('scopes')->nullable();
            $table->string('refresh_token_hash', 64)->nullable()->index();
            $table->boolean('is_revoked')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_tokens');
        Schema::dropIfExists('mcp_oauth_auth_codes');
        Schema::dropIfExists('mcp_oauth_clients');
    }
};

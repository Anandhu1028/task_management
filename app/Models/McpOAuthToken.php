<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpOAuthToken extends Model
{
    use HasFactory;

    protected $table = 'mcp_oauth_tokens';

    protected $fillable = [
        'token_id',
        'client_id',
        'user_id',
        'scopes',
        'refresh_token_hash',
        'is_revoked',
        'expires_at',
        'refresh_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'is_revoked' => 'boolean',
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRefreshExpired(): bool
    {
        return $this->refresh_expires_at === null || $this->refresh_expires_at->isPast();
    }
}

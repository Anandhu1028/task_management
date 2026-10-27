<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpOAuthAuthCode extends Model
{
    use HasFactory;

    protected $table = 'mcp_oauth_auth_codes';

    protected $fillable = [
        'code_hash',
        'client_id',
        'user_id',
        'redirect_uri',
        'code_challenge',
        'code_challenge_method',
        'scopes',
        'is_used',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'is_used' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

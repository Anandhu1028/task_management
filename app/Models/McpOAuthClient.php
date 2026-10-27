<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class McpOAuthClient extends Model
{
    use HasFactory;

    protected $table = 'mcp_oauth_clients';

    protected $fillable = [
        'client_id',
        'name',
        'secret',
        'redirect_uris',
        'scopes',
        'is_confidential',
        'is_revoked',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'scopes' => 'array',
            'is_confidential' => 'boolean',
            'is_revoked' => 'boolean',
        ];
    }

    public function isValidRedirectUri(string $uri): bool
    {
        $allowed = $this->redirect_uris ?? [];

        // Exact match
        if (in_array($uri, $allowed, true)) {
            return true;
        }

        // Support ChatGPT official callback patterns (dynamic connector IDs)
        if (str_starts_with($uri, 'https://chatgpt.com/connector/oauth/')
            || str_starts_with($uri, 'https://chat.openai.com/connector/oauth/')
            || str_starts_with($uri, 'https://chatgpt.com/aip/')
            || str_starts_with($uri, 'https://chat.openai.com/aip/')) {
            return true;
        }

        // Wildcard prefix matching for custom configured URIs
        foreach ($allowed as $pattern) {
            if (str_ends_with($pattern, '*')) {
                $prefix = rtrim($pattern, '*');
                if (str_starts_with($uri, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}

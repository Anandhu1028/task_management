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
        $uri = trim($uri);
        if ($uri === '') {
            return false;
        }

        $parts = parse_url($uri);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';

        // Disallow fragment
        if (isset($parts['fragment'])) {
            return false;
        }

        // Allow HTTP only for local development on localhost or 127.0.0.1
        $isLocalDev = in_array(config('app.env'), ['local', 'testing'], true)
            && in_array($host, ['localhost', '127.0.0.1'], true);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $isLocalDev)) {
            return false;
        }

        $allowed = $this->redirect_uris ?? [];

        // Exact match against registered redirect URIs (strictly no wildcards or arbitrary paths)
        if (in_array($uri, $allowed, true)) {
            return true;
        }

        // Exact match normalized (ignoring trailing slash)
        $normalizedUri = rtrim($uri, '/');
        foreach ($allowed as $allowedUri) {
            if (rtrim($allowedUri, '/') === $normalizedUri) {
                return true;
            }
        }

        return false;
    }
}

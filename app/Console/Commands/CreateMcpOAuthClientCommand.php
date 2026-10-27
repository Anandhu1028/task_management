<?php

namespace App\Console\Commands;

use App\Services\Mcp\OAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateMcpOAuthClientCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mcp:create-oauth-client
                            {--name=ChatGPT Connector : The client name}
                            {--redirect_uri=https://chatgpt.com/aip/g-connector/oauth/callback : Allowed redirect URI (comma-separated for multiples)}
                            {--client_id= : Custom client ID}
                            {--client_secret= : Custom client secret (leave empty for public PKCE client)}
                            {--confidential : Whether client must provide a secret}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register an OAuth 2.1 client for ChatGPT or another MCP client';

    public function handle(OAuthService $oauthService): int
    {
        $name = (string) $this->option('name');
        $redirectUrisInput = (string) $this->option('redirect_uri');
        $redirectUris = array_values(array_filter(array_map('trim', explode(',', $redirectUrisInput))));
        $customClientId = $this->option('client_id') ? (string) $this->option('client_id') : null;
        $customSecret = $this->option('client_secret') ? (string) $this->option('client_secret') : null;
        $isConfidential = (bool) $this->option('confidential');

        if ($isConfidential && ! $customSecret) {
            $customSecret = Str::random(40);
        }

        $client = $oauthService->createClient(
            name: $name,
            redirectUris: $redirectUris,
            clientId: $customClientId,
            secret: $customSecret,
            isConfidential: $isConfidential,
        );

        $this->info("OAuth client '{$client->name}' successfully created!");
        $this->table(
            ['Property', 'Value'],
            [
                ['Client ID', $client->client_id],
                ['Client Secret', $customSecret ?: '(None - Public PKCE Client)'],
                ['Redirect URIs', implode("\n", $client->redirect_uris ?? [])],
                ['Confidential', $client->is_confidential ? 'Yes' : 'No'],
                ['Authorization URL', url('/oauth/authorize')],
                ['Token URL', url('/oauth/token')],
                ['MCP Endpoint', url('/mcp')],
            ]
        );

        return self::SUCCESS;
    }
}

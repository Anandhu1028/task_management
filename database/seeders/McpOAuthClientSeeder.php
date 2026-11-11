<?php

namespace Database\Seeders;

use App\Models\McpOAuthClient;
use Illuminate\Database\Seeder;

class McpOAuthClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // ChatGPT uses Authorization Code + PKCE S256 (public OAuth 2.0/2.1 client).
        // It must NOT be confidential (is_confidential = false) and must NOT have a secret,
        // because ChatGPT does not send client secrets during public token exchange.
        McpOAuthClient::updateOrCreate(
            ['client_id' => 'chatgpt_connector_live'],
            [
                'name' => 'ChatGPT Connector',
                'secret' => null,
                'redirect_uris' => [
                    'https://chatgpt.com/connector/oauth/callback',
                    'https://chatgpt.com/connector/oauth/yHyws2Of_8Kj',
                    'https://chatgpt.com/connector/oauth/yHyws2Qf_8Kj',
                    'https://chatgpt.com/aip/g-connector/oauth/callback',
                    'https://chat.openai.com/aip/g-connector/oauth/callback',
                ],
                'scopes' => [
                    'tasks:read',
                    'tasks:write',
                    'reports:read',
                    'offline_access',
                ],
                'is_confidential' => false,
                'is_revoked' => false,
            ]
        );
    }
}

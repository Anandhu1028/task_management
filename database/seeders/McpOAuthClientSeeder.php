<?php

namespace Database\Seeders;

use App\Models\McpOAuthClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class McpOAuthClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        McpOAuthClient::updateOrCreate(
            ['client_id' => 'chatgpt_connector_live'],
            [
                'name' => 'ChatGPT Connector',
                'secret' => Hash::make('focuz_tracker_secret_2026'),
                'redirect_uris' => [
                    'https://chatgpt.com/connector/oauth/*',
                    'https://chat.openai.com/connector/oauth/*',
                    'https://chatgpt.com/aip/*',
                    'https://chat.openai.com/aip/*',
                    'https://chatgpt.com/connector/oauth/yHyws2Of_8Kj',
                    'https://chatgpt.com/aip/g-connector/oauth/callback',
                    'https://chat.openai.com/aip/g-connector/oauth/callback',
                ],
                'scopes' => ['tasks:read', 'reports:read', 'offline_access'],
                'is_confidential' => true,
                'is_revoked' => false,
            ]
        );
    }
}

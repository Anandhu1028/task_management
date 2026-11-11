<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MCP Server Information
    |--------------------------------------------------------------------------
    */
    'server_name' => env('MCP_SERVER_NAME', 'TaskFlow MCP Server'),
    'server_version' => env('MCP_SERVER_VERSION', '1.0.0'),
    'protocol_version' => env('MCP_PROTOCOL_VERSION', '2024-11-05'),

    /*
    |--------------------------------------------------------------------------
    | OAuth 2.1 & Security Configuration
    |--------------------------------------------------------------------------
    */
    'audience' => env('MCP_AUDIENCE', 'mcp-server'),
    'access_token_ttl' => (int) env('MCP_ACCESS_TOKEN_TTL', 28800), // 8 hours in seconds
    'refresh_token_ttl' => (int) env('MCP_REFRESH_TOKEN_TTL', 2592000), // 30 days in seconds
    'auth_code_ttl' => (int) env('MCP_AUTH_CODE_TTL', 600), // 10 minutes in seconds

    /*
    |--------------------------------------------------------------------------
    | Supported Scopes
    |--------------------------------------------------------------------------
    */
    'scopes' => [
        'tasks:read' => 'Read tasks, subtasks, timelines, and progress',
        'tasks:write' => 'Create tasks and update authorized task statuses',
        'reports:read' => 'Access task reports, overdue summaries, and analytics',
        'offline_access' => 'Maintain persistent access via refresh tokens',
    ],

    'default_scopes' => [
        'tasks:read',
        'reports:read',
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    */
    'audit_logging' => (bool) env('MCP_AUDIT_LOG_ENABLED', true),
];

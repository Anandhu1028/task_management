<?php

namespace App\Services\Mcp\Tools;

use App\Models\User;

interface McpToolInterface
{
    /**
     * The unique name of the MCP tool.
     */
    public function name(): string;

    /**
     * A human-readable description of what the tool does.
     */
    public function description(): string;

    /**
     * JSON Schema for the tool input arguments.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Execute the tool on behalf of the authenticated user.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(User $user, array $arguments): array;
}

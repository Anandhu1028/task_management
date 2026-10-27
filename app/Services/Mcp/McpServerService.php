<?php

namespace App\Services\Mcp;

use App\Models\User;
use App\Services\Mcp\Tools\GetDeadlineRequestsTool;
use App\Services\Mcp\Tools\GetMyTasksTool;
use App\Services\Mcp\Tools\GetOverdueTasksTool;
use App\Services\Mcp\Tools\GetProjectReportTool;
use App\Services\Mcp\Tools\GetSubtaskReportTool;
use App\Services\Mcp\Tools\GetTaskDetailsTool;
use App\Services\Mcp\Tools\GetTaskReportTool;
use App\Services\Mcp\Tools\GetTeamReportTool;
use App\Services\Mcp\Tools\McpToolInterface;
use App\Services\Mcp\Tools\SearchTasksTool;
use Illuminate\Support\Facades\Log;
use Throwable;

class McpServerService
{
    public const PROTOCOL_VERSION = '2024-11-05';

    public const SERVER_NAME = 'TaskFlow MCP Server';

    public const SERVER_VERSION = '1.0.0';

    /**
     * @var array<string, McpToolInterface>
     */
    private array $tools = [];

    public function __construct()
    {
        $this->registerDefaultTools();
    }

    private function registerDefaultTools(): void
    {
        $tools = [
            new GetMyTasksTool,
            new SearchTasksTool,
            new GetTaskDetailsTool,
            new GetTaskReportTool,
            new GetOverdueTasksTool,
            new GetTeamReportTool,
            new GetSubtaskReportTool,
            new GetDeadlineRequestsTool,
            new GetProjectReportTool,
        ];

        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * @return array<string, McpToolInterface>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * Handle an incoming JSON-RPC 2.0 payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function handleJsonRpc(User $user, array $payload): ?array
    {
        $id = $payload['id'] ?? null;
        $method = $payload['method'] ?? null;
        $params = $payload['params'] ?? [];

        if (! is_string($method)) {
            return $this->errorResponse($id, -32600, 'Invalid Request: method must be a string.');
        }

        try {
            switch ($method) {
                case 'initialize':
                    return $this->handleInitialize($id, $params);

                case 'notifications/initialized':
                    // Notification, no response required
                    return null;

                case 'ping':
                    return $this->successResponse($id, new \stdClass);

                case 'tools/list':
                    return $this->handleToolsList($id);

                case 'tools/call':
                    return $this->handleToolsCall($user, $id, $params);

                default:
                    return $this->errorResponse($id, -32601, "Method '{$method}' not found.");
            }
        } catch (Throwable $e) {
            Log::error('MCP tool execution exception', [
                'user_id' => $user->id,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse($id, -32603, 'Internal error during MCP request processing.');
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function handleInitialize(mixed $id, array $params): array
    {
        $clientVersion = $params['protocolVersion'] ?? self::PROTOCOL_VERSION;

        return $this->successResponse($id, [
            'protocolVersion' => $clientVersion ?: self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleToolsList(mixed $id): array
    {
        $toolList = [];

        foreach ($this->tools as $tool) {
            $toolList[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }

        return $this->successResponse($id, [
            'tools' => $toolList,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(User $user, mixed $id, array $params): array
    {
        $toolName = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (! isset($this->tools[$toolName])) {
            return $this->errorResponse($id, -32602, "Tool '{$toolName}' not found.");
        }

        $tool = $this->tools[$toolName];

        $startTime = microtime(true);

        try {
            $result = $tool->execute($user, $arguments);
            $executionDuration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info("MCP Tool executed: {$toolName}", [
                'user_id' => $user->id,
                'tool' => $toolName,
                'duration_ms' => $executionDuration,
            ]);

            return $this->successResponse($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'isError' => false,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->successResponse($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Invalid arguments: {$e->getMessage()}",
                    ],
                ],
                'isError' => true,
            ]);
        } catch (Throwable $e) {
            Log::error("Error executing MCP tool {$toolName}", [
                'user_id' => $user->id,
                'tool' => $toolName,
                'exception' => $e->getMessage(),
            ]);

            return $this->successResponse($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Error executing tool {$toolName}: {$e->getMessage()}",
                    ],
                ],
                'isError' => true,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function successResponse(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}

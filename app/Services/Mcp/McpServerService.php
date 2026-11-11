<?php

namespace App\Services\Mcp;

use App\Models\User;
use App\Services\Mcp\Tools\CreateTaskTool;
use App\Services\Mcp\Tools\GetCompanyReportTool;
use App\Services\Mcp\Tools\GetDailyWorkReportTool;
use App\Services\Mcp\Tools\GetDeadlineRequestsTool;
use App\Services\Mcp\Tools\GetEmployeePerformanceTool;
use App\Services\Mcp\Tools\GetMyTasksTool;
use App\Services\Mcp\Tools\GetOverdueTasksTool;
use App\Services\Mcp\Tools\GetProjectReportTool;
use App\Services\Mcp\Tools\GetSubtaskDetailsTool;
use App\Services\Mcp\Tools\GetSubtaskReportTool;
use App\Services\Mcp\Tools\GetTaskDetailsTool;
use App\Services\Mcp\Tools\GetTaskReportTool;
use App\Services\Mcp\Tools\GetTeamReportTool;
use App\Services\Mcp\Tools\GetTeamTasksTool;
use App\Services\Mcp\Tools\McpToolInterface;
use App\Services\Mcp\Tools\SearchTasksTool;
use App\Services\Mcp\Tools\UpdateDailyTaskTool;
use App\Services\Mcp\Tools\UpdateTaskStatusTool;
use Illuminate\Database\QueryException;
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

    public function __construct(
        private readonly ?McpAuditService $auditService = null,
    ) {
        $this->registerDefaultTools();
    }

    private function registerDefaultTools(): void
    {
        $tools = [
            new GetMyTasksTool,
            new GetTeamTasksTool,
            new SearchTasksTool,
            new GetTaskDetailsTool,
            new GetSubtaskDetailsTool,
            new GetTaskReportTool,
            new GetOverdueTasksTool,
            new GetTeamReportTool,
            new GetSubtaskReportTool,
            new GetDeadlineRequestsTool,
            new GetProjectReportTool,
            new GetDailyWorkReportTool,
            new GetCompanyReportTool,
            new GetEmployeePerformanceTool,
            new CreateTaskTool,
            new UpdateTaskStatusTool,
            new UpdateDailyTaskTool,
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
            Log::error('MCP request processing failed', $this->exceptionContext($e, 'json_rpc_request') + [
                'method' => $method,
            ]);

            return $this->errorResponse($id, -32603, 'Internal error during MCP request processing.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function exceptionContext(Throwable $exception, string $stage): array
    {
        $context = [
            'stage' => $stage,
            'exception_class' => $exception::class,
            'exception_code' => $exception->getCode(),
            'request_id' => request()->attributes->get('mcp_request_id'),
        ];

        if ($exception instanceof QueryException) {
            $context['sql_state'] = $exception->getCode();
            $context['sql_template'] = $exception->getSql();
        }

        return $context;
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
                'name' => config('mcp.server_name', self::SERVER_NAME),
                'version' => config('mcp.server_version', self::SERVER_VERSION),
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

        Log::debug('MCP tool registry listed', [
            'stage' => 'tool_registration',
            'tool_count' => count($toolList),
            'get_my_tasks_registered' => isset($this->tools['get_my_tasks']),
            'request_id' => request()->attributes->get('mcp_request_id'),
        ]);

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
            Log::warning('MCP tool lookup failed', [
                'stage' => 'tool_registration_lookup',
                'tool' => $toolName,
                'request_id' => request()->attributes->get('mcp_request_id'),
            ]);

            return $this->errorResponse($id, -32602, "Tool '{$toolName}' not found.");
        }

        $tool = $this->tools[$toolName];

        $startTime = microtime(true);
        Log::debug('MCP tool execution started', [
            'stage' => 'tool_execution_started',
            'tool' => $toolName,
            'argument_names' => array_keys($arguments),
            'request_id' => request()->attributes->get('mcp_request_id'),
        ]);

        try {
            $result = $tool->execute($user, $arguments);
            $executionDuration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info("MCP Tool executed: {$toolName}", [
                'stage' => 'tool_execution_completed',
                'tool' => $toolName,
                'duration_ms' => $executionDuration,
                'request_id' => request()->attributes->get('mcp_request_id'),
            ]);

            $serializedResult = json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );

            Log::debug('MCP tool result serialized', [
                'stage' => 'mcp_response_serialization',
                'tool' => $toolName,
                'serialized_bytes' => strlen($serializedResult),
                'request_id' => request()->attributes->get('mcp_request_id'),
            ]);

            $this->auditService?->log([
                'user' => $user,
                'tool_name' => $toolName,
                'action' => 'call',
                'status' => 'success',
                'duration_ms' => $executionDuration,
            ]);

            return $this->successResponse($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $serializedResult,
                    ],
                ],
                'isError' => false,
            ]);
        } catch (\InvalidArgumentException $e) {
            $executionDuration = round((microtime(true) - $startTime) * 1000, 2);

            Log::warning('MCP tool arguments rejected', [
                'stage' => 'tool_argument_validation',
                'tool' => $toolName,
                'exception_class' => $e::class,
                'request_id' => request()->attributes->get('mcp_request_id'),
            ]);

            $this->auditService?->log([
                'user' => $user,
                'tool_name' => $toolName,
                'action' => 'call',
                'status' => 'denied',
                'duration_ms' => $executionDuration,
            ]);

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
            $executionDuration = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('MCP tool execution failed', $this->exceptionContext($e, 'tool_execution') + [
                'tool' => $toolName,
                'duration_ms' => $executionDuration,
            ]);

            $this->auditService?->log([
                'user' => $user,
                'tool_name' => $toolName,
                'action' => 'call',
                'status' => 'error',
                'duration_ms' => $executionDuration,
            ]);

            return $this->successResponse($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Tool {$toolName} failed due to an internal error.",
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

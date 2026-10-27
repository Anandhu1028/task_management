<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class McpController extends Controller
{
    public function __construct(
        private readonly McpServerService $mcpServer,
    ) {}

    /**
     * Handle MCP Streamable HTTP POST endpoint.
     * POST /mcp
     */
    public function handle(Request $request): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: Authentication required.',
                ],
            ], 401);
        }

        $payload = $request->json()->all();

        if (empty($payload)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32700,
                    'message' => 'Parse error: Request body must be valid JSON.',
                ],
            ], 400);
        }

        // Handle batch JSON-RPC requests
        if (array_is_list($payload) && count($payload) > 0) {
            $responses = [];
            foreach ($payload as $requestItem) {
                if (is_array($requestItem)) {
                    $res = $this->mcpServer->handleJsonRpc($user, $requestItem);
                    if ($res !== null) {
                        $responses[] = $res;
                    }
                }
            }

            return response()->json($responses);
        }

        // Handle single JSON-RPC request
        $response = $this->mcpServer->handleJsonRpc($user, $payload);

        if ($response === null) {
            return response()->noContent();
        }

        return response()->json($response);
    }

    /**
     * MCP Server Information / Health check.
     * GET /mcp
     */
    public function info(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'active',
            'server' => McpServerService::SERVER_NAME,
            'version' => McpServerService::SERVER_VERSION,
            'protocol_version' => McpServerService::PROTOCOL_VERSION,
            'transport' => 'streamable_http',
            'tools_count' => count($this->mcpServer->getTools()),
            'documentation' => 'Use POST /mcp with JSON-RPC 2.0 and Bearer authentication.',
        ]);
    }
}

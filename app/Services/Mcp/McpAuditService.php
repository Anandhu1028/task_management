<?php

namespace App\Services\Mcp;

use App\Models\McpAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class McpAuditService
{
    /**
     * Record an MCP or OAuth audit log entry safely without sensitive credentials.
     *
     * @param  array{
     *     user?: ?User,
     *     user_id?: ?int,
     *     company_id?: ?int,
     *     client_id?: ?string,
     *     tool_name?: ?string,
     *     action: string,
     *     resource_type?: ?string,
     *     resource_id?: ?string,
     *     status?: string,
     *     error_message?: ?string,
     *     duration_ms?: ?float,
     *     request?: ?Request,
     * }  $params
     */
    public function log(array $params): ?McpAuditLog
    {
        if (! config('mcp.audit_logging', true)) {
            return null;
        }

        try {
            $user = $params['user'] ?? null;
            $userId = $params['user_id'] ?? $user?->id;
            $companyId = $params['company_id'] ?? $user?->company_id;

            $request = $params['request'] ?? (request() instanceof Request ? request() : null);
            $ip = $request?->ip();
            $userAgent = $request ? substr((string) $request->userAgent(), 0, 500) : null;

            return McpAuditLog::create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'client_id' => $params['client_id'] ?? null,
                'tool_name' => $params['tool_name'] ?? null,
                'action' => $params['action'],
                'resource_type' => $params['resource_type'] ?? null,
                'resource_id' => $params['resource_id'] ? (string) $params['resource_id'] : null,
                'status' => $params['status'] ?? 'success',
                'error_message' => $params['error_message'] ?? null,
                'duration_ms' => $params['duration_ms'] ?? null,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);
        } catch (Throwable) {
            // Never allow audit logging failure to crash primary business flow
            return null;
        }
    }
}

<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\OAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OAuthDiscoveryController extends Controller
{
    /**
     * RFC 8414: OAuth 2.0 Authorization Server Metadata
     * GET /.well-known/oauth-authorization-server
     */
    public function authorizationServer(Request $request): JsonResponse
    {
        $baseUrl = url('/');

        return response()->json([
            'issuer' => $baseUrl,
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'revocation_endpoint' => url('/oauth/revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => OAuthService::SUPPORTED_SCOPES,
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'service_documentation' => url('/mcp'),
            'ui_locales_supported' => ['en'],
            'op_policy_uri' => url('/privacy-policy'),
            'op_tos_uri' => url('/terms'),
        ]);
    }

    /**
     * RFC 9728: OAuth 2.0 Protected Resource Metadata
     * GET /.well-known/oauth-protected-resource
     */
    public function protectedResource(Request $request): JsonResponse
    {
        $baseUrl = url('/');

        return response()->json([
            'resource' => url('/mcp'),
            'authorization_servers' => [$baseUrl],
            'scopes_supported' => OAuthService::SUPPORTED_SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => url('/mcp'),
        ]);
    }

    /**
     * OpenID Connect Discovery
     * GET /.well-known/openid-configuration
     */
    public function openidConfiguration(Request $request): JsonResponse
    {
        return $this->authorizationServer($request);
    }
}

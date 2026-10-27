<?php

use App\Http\Controllers\Mcp\McpController;
use App\Http\Controllers\Mcp\McpRestController;
use App\Http\Controllers\Mcp\OAuthController;
use App\Http\Controllers\Mcp\OAuthDiscoveryController;
use Illuminate\Support\Facades\Route;

// RFC 8414 and RFC 9728 Discovery Metadata
Route::get('/.well-known/oauth-authorization-server', [OAuthDiscoveryController::class, 'authorizationServer']);
Route::get('/.well-known/oauth-protected-resource', [OAuthDiscoveryController::class, 'protectedResource']);
Route::get('/.well-known/openid-configuration', [OAuthDiscoveryController::class, 'openidConfiguration']);

// OAuth 2.1 Authorization Code Flow (Interactive User Consent)
Route::middleware(['web', 'auth', 'active'])->group(function (): void {
    Route::get('/oauth/authorize', [OAuthController::class, 'showConsent'])->name('oauth.authorize');
    Route::post('/oauth/authorize', [OAuthController::class, 'approve'])->name('oauth.approve');
});

// OAuth 2.1 Token Endpoint (Machine-to-Machine Token Exchange)
Route::post('/oauth/token', [OAuthController::class, 'token'])
    ->middleware('throttle:30,1')
    ->name('oauth.token');

// MCP Server Streamable HTTP Endpoint
Route::get('/mcp', [McpController::class, 'info'])->name('mcp.info');
Route::post('/mcp', [McpController::class, 'handle'])
    ->middleware(['throttle:60,1', 'mcp.auth:tasks:read'])
    ->name('mcp.handle');

// OpenAPI 3.0 Schema for ChatGPT Custom Actions / Connectors
Route::get('/api/mcp/openapi.json', [McpRestController::class, 'openApi'])->name('mcp.openapi');

// REST Endpoints for ChatGPT Custom Actions
Route::middleware(['throttle:60,1', 'mcp.auth:tasks:read'])->prefix('api/mcp')->group(function (): void {
    Route::get('/my-tasks', [McpRestController::class, 'myTasks'])->name('mcp.rest.my-tasks');
    Route::get('/search-tasks', [McpRestController::class, 'searchTasks'])->name('mcp.rest.search-tasks');
    Route::get('/task-details', [McpRestController::class, 'taskDetails'])->name('mcp.rest.task-details');
    Route::get('/task-report', [McpRestController::class, 'taskReport'])->name('mcp.rest.task-report');
    Route::get('/overdue-tasks', [McpRestController::class, 'overdueTasks'])->name('mcp.rest.overdue-tasks');
    Route::get('/team-report', [McpRestController::class, 'teamReport'])->name('mcp.rest.team-report');
    Route::get('/subtask-report', [McpRestController::class, 'subtaskReport'])->name('mcp.rest.subtask-report');
    Route::get('/deadline-requests', [McpRestController::class, 'deadlineRequests'])->name('mcp.rest.deadline-requests');
    Route::get('/project-report', [McpRestController::class, 'projectReport'])->name('mcp.rest.project-report');
});

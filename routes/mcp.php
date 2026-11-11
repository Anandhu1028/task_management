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

// RFC 7009 Token Revocation Endpoint
Route::post('/oauth/revoke', [OAuthController::class, 'revoke'])
    ->middleware('throttle:30,1')
    ->name('oauth.revoke');

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
    Route::get('/daily-work-report', [McpRestController::class, 'dailyWorkReport'])->name('mcp.rest.daily-work-report');
    Route::get('/company-report', [McpRestController::class, 'companyReport'])->name('mcp.rest.company-report');
    Route::get('/employee-performance', [McpRestController::class, 'employeePerformance'])->name('mcp.rest.employee-performance');
    Route::get('/subtask-details', [McpRestController::class, 'subtaskDetails'])->name('mcp.rest.subtask-details');
    Route::get('/team-tasks', [McpRestController::class, 'teamTasks'])->name('mcp.rest.team-tasks');
    Route::post('/create-task', [McpRestController::class, 'createTask'])->name('mcp.rest.create-task');
    Route::post('/update-task-status', [McpRestController::class, 'updateTaskStatus'])->name('mcp.rest.update-task-status');
    Route::post('/update-daily-task', [McpRestController::class, 'updateDailyTask'])->name('mcp.rest.update-daily-task');
});

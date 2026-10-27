<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DesktopMetaController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\SubtaskController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/desktop/meta', [DesktopMetaController::class, 'show']);

    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::put('/tasks/{task}', [TaskController::class, 'update']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update']);
    Route::post('/tasks/{task}/accept', [TaskController::class, 'accept']);
    Route::post('/tasks/{task}/start', [TaskController::class, 'startWork']);
    Route::post('/tasks/{task}/work-updates', [TaskController::class, 'addWorkUpdate']);
    Route::post('/tasks/{task}/resume', [TaskController::class, 'resume']);
    Route::post('/tasks/{task}/status', [TaskController::class, 'updateStatus']);
    Route::post('/tasks/{task}/risk', [TaskController::class, 'updateRisk']);
    Route::post('/tasks/{task}/review', [TaskController::class, 'review']);

    Route::post('/tasks/{task}/subtasks', [SubtaskController::class, 'store']);
    Route::get('/tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'show'])->scopeBindings();
    Route::patch('/tasks/{task}/subtasks/{subtask}/toggle', [SubtaskController::class, 'toggle'])->scopeBindings();
    Route::patch('/tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'update'])->scopeBindings();
    Route::delete('/tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'destroy'])->scopeBindings();
});

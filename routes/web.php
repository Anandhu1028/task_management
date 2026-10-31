<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DailyAccountabilityController;
use App\Http\Controllers\DailyAccountabilitySettingsController;
use App\Http\Controllers\DailyTaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeadlineExtensionRequestController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PwaTaskController;
use App\Http\Controllers\RecurringTaskController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SubtaskController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::prefix('pwa')->name('pwa.')->middleware('pwa.nostore')->group(function (): void {
        Route::get('/tasks', [PwaTaskController::class, 'index'])->name('tasks.index');
        Route::get('/tasks/{task}', [PwaTaskController::class, 'show'])->name('tasks.show');
    });
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/data', [DashboardController::class, 'data'])->name('dashboard.data');
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/daily-accountability', [DailyAccountabilityController::class, 'index'])
        ->name('daily-accountability.index');

    Route::prefix('settings/daily-accountability')->name('daily-accountability.settings.')->group(function (): void {
        Route::get('/', [DailyAccountabilitySettingsController::class, 'index'])->name('index');
        Route::put('/', [DailyAccountabilitySettingsController::class, 'update'])->name('update');
        Route::post('/exemptions', [DailyAccountabilitySettingsController::class, 'storeExemption'])->name('exemptions.store');
        Route::patch('/exemptions/{exemption}/deactivate', [DailyAccountabilitySettingsController::class, 'deactivateExemption'])
            ->name('exemptions.deactivate');
    });

    Route::prefix('settings/roles')->name('roles.')->group(function (): void {
        Route::get('/', [RoleController::class, 'index'])->middleware('can:roles.view')->name('index');
        Route::post('/', [RoleController::class, 'store'])->middleware('can:roles.create')->name('store');
        Route::patch('/{role}', [RoleController::class, 'update'])->middleware('can:roles.update')->name('update');
        Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.delete')->name('destroy');
    });

    // Task routes
    Route::get('/recurring-tasks', [RecurringTaskController::class, 'index'])->name('recurring-tasks.index');
    Route::get('/recurring-tasks/{recurringTask}', [RecurringTaskController::class, 'show'])->name('recurring-tasks.show');
    Route::patch('/recurring-tasks/{recurringTask}/status', [RecurringTaskController::class, 'updateScheduleStatus'])->name('recurring-tasks.status.update');
    Route::post('/recurring-tasks/{recurringTask}/tasks/{task}/status', [RecurringTaskController::class, 'updateStatus'])->name('recurring-tasks.tasks.status.update');
    Route::get('/tasks/export', [TaskController::class, 'export'])->name('tasks.export');
    Route::get('/tasks/users-by-department/{department}', [TaskController::class, 'usersByDepartment'])->name('tasks.users-by-department');
    Route::post('/tasks/{task}/reassign', [TaskController::class, 'reassign'])->name('tasks.reassign');
    Route::post('/tasks/{task}/deadline-extension-requests', [DeadlineExtensionRequestController::class, 'store'])
        ->name('tasks.deadline-extension-requests.store');
    Route::post('/tasks/{task}/deadline-extension-requests/{extensionRequest}/review', [DeadlineExtensionRequestController::class, 'review'])
        ->name('tasks.deadline-extension-requests.review');
    Route::post('/tasks/{task}/accept', [TaskController::class, 'accept'])->name('tasks.accept');
    Route::post('/tasks/{task}/start', [TaskController::class, 'startWork'])->name('tasks.start');
    Route::post('/tasks/{task}/work-updates', [TaskController::class, 'addWorkUpdate'])->name('tasks.work-updates.store');
    Route::post('/tasks/{task}/resume', [TaskController::class, 'resumeWork'])->name('tasks.resume');
    Route::post('/tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status.update');
    Route::post('/tasks/{task}/risk', [TaskController::class, 'updateRisk'])->name('tasks.risk.update');
    Route::post('/tasks/{task}/review', [TaskController::class, 'review'])->name('tasks.review');
    Route::get('/notifications/poll', [NotificationController::class, 'poll'])->name('notifications.poll');
    Route::get('/notifications/{notification}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::get('/tasks/{task}/attachments/{attachment}', [TaskController::class, 'downloadAttachment'])->name('tasks.attachments.download');
    Route::get('/tasks/{task}/attachments/{attachment}/preview', [TaskController::class, 'previewAttachment'])->name('tasks.attachments.preview');
    Route::resource('tasks', TaskController::class);

    Route::get('/daily-tasks', [DailyTaskController::class, 'index'])->name('daily-tasks.index');
    Route::get('/tasks/{task}/daily-tasks', [DailyTaskController::class, 'forTask'])->name('daily-tasks.for-task');
    Route::get('/tasks/{task}/daily-tasks/options', [DailyTaskController::class, 'subtasks'])->name('daily-tasks.options');
    Route::get('/tasks/{task}/daily-tasks/create', [DailyTaskController::class, 'create'])->name('daily-tasks.create');
    Route::post('/tasks/{task}/daily-tasks', [DailyTaskController::class, 'store'])->name('daily-tasks.store');
    Route::get('/tasks/{task}/daily-tasks/{dailyTask}', [DailyTaskController::class, 'show'])->name('daily-tasks.show')->scopeBindings();
    Route::post('/tasks/{task}/daily-tasks/{dailyTask}/{action}', [DailyTaskController::class, 'action'])->name('daily-tasks.action')->scopeBindings();
    Route::post('/tasks/{task}/milestones', [MilestoneController::class, 'store'])->name('milestones.store');
    Route::patch('/tasks/{task}/milestones/{milestone}', [MilestoneController::class, 'update'])
        ->name('milestones.update')->scopeBindings();

    // Subtask routes
    Route::post('/tasks/{task}/subtasks', [SubtaskController::class, 'store'])->name('subtasks.store');
    Route::post('/tasks/{task}/subtasks/bulk', [SubtaskController::class, 'bulkStore'])->name('subtasks.bulk-store');
    Route::get('/tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'show'])->name('subtasks.show')->scopeBindings();
    Route::patch('/tasks/{task}/subtasks/{subtask}/toggle', [SubtaskController::class, 'toggle'])->name('subtasks.toggle')->scopeBindings();
    Route::patch('/tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'update'])->name('subtasks.update')->scopeBindings();
    Route::delete('/tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'destroy'])->name('subtasks.destroy')->scopeBindings();
    Route::post('/tasks/{task}/subtasks/reorder', [SubtaskController::class, 'reorder'])->name('subtasks.reorder');

    // Company routes
    Route::get('/companies/export', [CompanyController::class, 'export'])->name('companies.export');
    Route::post('/companies/bulk-delete', [CompanyController::class, 'bulkDestroy'])->name('companies.bulk-destroy');
    Route::resource('companies', CompanyController::class);
    Route::post('/companies/{company}/toggle-status', [CompanyController::class, 'toggleStatus'])->name('companies.toggle-status');

    // Department routes
    Route::get('/departments/export', [DepartmentController::class, 'export'])->name('departments.export');
    Route::post('/departments/bulk-delete', [DepartmentController::class, 'bulkDestroy'])->name('departments.bulk-destroy');
    Route::get('/departments/company/{company}', [DepartmentController::class, 'byCompany'])->name('departments.by-company');
    Route::post('/departments/{department}/toggle-status', [DepartmentController::class, 'toggleStatus'])->name('departments.toggle-status');
    Route::resource('departments', DepartmentController::class)->except(['show']);

    // User routes
    Route::get('/users/export', [UserController::class, 'export'])->name('users.export');
    Route::post('/users/bulk-delete', [UserController::class, 'bulkDestroy'])->name('users.bulk-destroy');
    Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
    Route::resource('users', UserController::class);
});

require __DIR__.'/mcp.php';

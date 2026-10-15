<?php

use App\Http\Controllers\Api\V1\AccessControlController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CallLogController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FollowUpController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Version 1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Public Health Check
    Route::get('/health', fn() => response()->json(['status' => 'healthy', 'timestamp' => now()->toIso8601String()]));

    // Public Auth
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Protected Routes
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

        // Session & Current User
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/permissions', [AuthController::class, 'permissions']);

        // Dashboard
        Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);

        // Leads Domain
        Route::prefix('leads')->group(function () {
            Route::get('/', [LeadController::class, 'index'])->middleware('permission:leads.view_assigned');
            Route::post('/', [LeadController::class, 'store'])->middleware('permission:leads.create');
            Route::post('/check-duplicate', [LeadController::class, 'checkDuplicate']);
            Route::post('/import', [LeadController::class, 'import'])->middleware('permission:leads.import');
            Route::get('/export', [LeadController::class, 'export'])->middleware('permission:leads.export');
            Route::get('/{id}', [LeadController::class, 'show'])->middleware('permission:leads.view_assigned');
            Route::put('/{id}', [LeadController::class, 'update'])->middleware('permission:leads.update');
            Route::delete('/{id}', [LeadController::class, 'destroy'])->middleware('permission:leads.delete');
            Route::post('/{id}/assign', [LeadController::class, 'assign'])->middleware('permission:leads.assign');
        });

        // Calling & Call Outcomes
        Route::prefix('calls')->group(function () {
            Route::get('/', [CallLogController::class, 'index'])->middleware('permission:calls.view_own');
            Route::post('/', [CallLogController::class, 'store'])->middleware('permission:calls.log');
        });

        // Follow-ups & Tasks
        Route::prefix('follow-ups')->group(function () {
            Route::get('/', [FollowUpController::class, 'index'])->middleware('permission:followups.view_own');
            Route::post('/', [FollowUpController::class, 'store'])->middleware('permission:followups.create');
            Route::post('/{id}/complete', [FollowUpController::class, 'complete'])->middleware('permission:followups.complete');
            Route::post('/{id}/cancel', [FollowUpController::class, 'cancel'])->middleware('permission:followups.cancel');
        });

        // Teams & Users Management
        Route::prefix('teams')->group(function () {
            Route::get('/', [TeamController::class, 'index'])->middleware('permission:teams.view');
            Route::post('/', [TeamController::class, 'store'])->middleware('permission:teams.create');
            Route::put('/{id}', [TeamController::class, 'update'])->middleware('permission:teams.update');
        });

        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index'])->middleware('permission:users.view');
            Route::post('/', [UserController::class, 'store'])->middleware('permission:users.create');
            Route::put('/{id}', [UserController::class, 'update'])->middleware('permission:users.update');
        });

        // Dynamic Access Control & RBAC Matrix
        Route::prefix('access-control')->group(function () {
            Route::get('/matrix', [AccessControlController::class, 'getMatrix'])->middleware('permission:permissions.view');
            Route::put('/roles/{roleId}/permissions', [AccessControlController::class, 'updateRolePermissions'])->middleware('permission:permissions.manage');
            Route::get('/users/{userId}/permissions', [AccessControlController::class, 'getUserPermissions'])->middleware('permission:permissions.view');
            Route::post('/users/{userId}/permissions/override', [AccessControlController::class, 'setUserOverride'])->middleware('permission:permissions.manage');
            Route::delete('/users/{userId}/permissions/override/{permissionId}', [AccessControlController::class, 'removeUserOverride'])->middleware('permission:permissions.manage');
        });

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/leads-by-status', [ReportController::class, 'leadsByStatus'])->middleware('permission:reports.view_own');
            Route::get('/calls-by-outcome', [ReportController::class, 'callsByOutcome'])->middleware('permission:reports.view_own');
            Route::get('/team-performance', [ReportController::class, 'teamPerformance'])->middleware('permission:reports.view_team');
        });

        // In-app Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
            Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        });

        // Audit Trail
        Route::get('/audit', [AuditEventController::class, 'index'])->middleware('permission:audit.view');

        // Workspace CRM Settings
        Route::prefix('settings')->group(function () {
            Route::get('/', [SettingController::class, 'index'])->middleware('permission:settings.view');
            Route::put('/', [SettingController::class, 'update'])->middleware('permission:settings.manage');
        });

    });

});

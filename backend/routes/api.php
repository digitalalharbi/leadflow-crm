<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FollowUpController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PipelineController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SuperAdmin\TenantController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Public
    |--------------------------------------------------------------------------
    */
    Route::get('/branding', [BrandingController::class, 'show']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Lead ingestion. Authenticated by the per-endpoint shared secret, not a session.
    Route::prefix('integrations/webhooks/{key}')->middleware('throttle:webhook')->group(function () {
        Route::post('/leads', [WebhookController::class, 'receive']);
        Route::get('/leads', [WebhookController::class, 'verify']); // Meta subscription handshake
    });

    /*
    |--------------------------------------------------------------------------
    | Authenticated
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('/auth/password', [AuthController::class, 'changePassword']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        /*
        | Tenant workspace. `tenant.active` blocks suspended / expired accounts.
        */
        Route::middleware('tenant.active')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index']);

            // Leads
            Route::get('/leads/export', [LeadController::class, 'export']);
            Route::get('/leads', [LeadController::class, 'index']);
            Route::post('/leads', [LeadController::class, 'store']);
            Route::get('/leads/{uuid}', [LeadController::class, 'show']);
            Route::patch('/leads/{uuid}', [LeadController::class, 'update']);
            Route::delete('/leads/{uuid}', [LeadController::class, 'destroy']);
            Route::post('/leads/{uuid}/assign', [LeadController::class, 'assign']);
            Route::post('/leads/{uuid}/status', [LeadController::class, 'changeStatus']);
            Route::post('/leads/{uuid}/temperature', [LeadController::class, 'changeTemperature']);
            Route::post('/leads/{uuid}/notes', [LeadController::class, 'addNote']);
            Route::post('/leads/{uuid}/contact', [LeadController::class, 'logContact']);

            Route::get('/pipeline', [PipelineController::class, 'index']);

            // Follow-ups
            Route::get('/follow-ups', [FollowUpController::class, 'index']);
            Route::get('/follow-ups/counts', [FollowUpController::class, 'counts']);
            Route::post('/follow-ups', [FollowUpController::class, 'store']);
            Route::patch('/follow-ups/{uuid}', [FollowUpController::class, 'update']);
            Route::post('/follow-ups/{uuid}/complete', [FollowUpController::class, 'complete']);
            Route::delete('/follow-ups/{uuid}', [FollowUpController::class, 'destroy']);

            // Projects
            Route::apiResource('projects', ProjectController::class)
                ->parameters(['projects' => 'uuid']);

            // Users
            Route::get('/users/assignable', [UserController::class, 'assignable']);
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::patch('/users/{uuid}', [UserController::class, 'update']);
            Route::delete('/users/{uuid}', [UserController::class, 'destroy']);

            // Reports
            Route::get('/reports', [ReportController::class, 'index']);
            Route::get('/reports/export', [ReportController::class, 'export']);

            // Integrations (admin only, enforced by policy)
            Route::get('/integrations', [IntegrationController::class, 'index']);
            Route::post('/integrations', [IntegrationController::class, 'store']);
            Route::patch('/integrations/{uuid}', [IntegrationController::class, 'update']);
            Route::delete('/integrations/{uuid}', [IntegrationController::class, 'destroy']);
            Route::post('/integrations/{uuid}/webhooks', [IntegrationController::class, 'createWebhook']);
            Route::post('/webhook-endpoints/{uuid}/rotate', [IntegrationController::class, 'rotateWebhookSecret']);
            Route::delete('/webhook-endpoints/{uuid}', [IntegrationController::class, 'deleteWebhook']);
            Route::get('/webhook-logs', [IntegrationController::class, 'logs']);

            // CSV import
            Route::get('/imports', [ImportController::class, 'index']);
            Route::post('/imports', [ImportController::class, 'upload']);
            Route::get('/imports/{uuid}', [ImportController::class, 'show']);
            Route::post('/imports/{uuid}/process', [ImportController::class, 'process']);

            // Settings & white label
            Route::get('/settings', [SettingsController::class, 'show']);
            Route::patch('/settings/branding', [SettingsController::class, 'updateBranding']);
            Route::patch('/settings', [SettingsController::class, 'updateSettings']);
            Route::post('/settings/onboarding', [SettingsController::class, 'completeOnboardingStep']);
            Route::post('/settings/lost-reasons', [SettingsController::class, 'storeLostReason']);
            Route::patch('/settings/lost-reasons/{uuid}', [SettingsController::class, 'updateLostReason']);
            Route::post('/settings/custom-fields', [SettingsController::class, 'storeCustomField']);
            Route::patch('/settings/custom-fields/{uuid}', [SettingsController::class, 'updateCustomField']);
        });

        /*
        | Platform console — SaaS owner only. Returns 404 to everyone else.
        */
        Route::prefix('super-admin')->middleware(EnsureSuperAdmin::class)->group(function () {
            Route::get('/stats', [TenantController::class, 'stats']);
            Route::get('/tenants', [TenantController::class, 'index']);
            Route::post('/tenants', [TenantController::class, 'store']);
            Route::get('/tenants/{uuid}', [TenantController::class, 'show']);
            Route::patch('/tenants/{uuid}', [TenantController::class, 'update']);
            Route::post('/tenants/{uuid}/admins', [TenantController::class, 'createAdmin']);
        });
    });
});

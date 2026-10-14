<?php

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationRuleController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\CampaignSpendController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DataQualityController;
use App\Http\Controllers\Api\V1\DemoController;
use App\Http\Controllers\Api\V1\FollowUpController;
use App\Http\Controllers\Api\V1\GitHubController;
use App\Http\Controllers\Api\V1\GitHubWebhookController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadMessageController;
use App\Http\Controllers\Api\V1\MessageTemplateController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PipelineController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectDevelopmentController;
use App\Http\Controllers\Api\V1\QualificationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SalesPerformanceController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SheetSourceController;
use App\Http\Controllers\Api\V1\SuperAdmin\TenantController;
use App\Http\Controllers\Api\V1\TestLeadController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WhatsappSettingsController;
use App\Http\Controllers\Api\V1\WhatsappWebhookController;
use App\Http\Controllers\Api\V1\WorkQueueController;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

/*
 * Every public identifier is a UUID. Constraining the segment turns a malformed
 * id into a 404 at the router, before it can reach a Postgres `uuid` column and
 * raise SQLSTATE 22P02 — a 500 that any caller could trigger with a typo.
 */
Route::pattern('uuid', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

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
     * WhatsApp Cloud API callbacks. One URL for the whole platform, because Meta
     * allows one per app: the tenant is decided by the phone number id inside
     * the payload, after the signature has been checked against that tenant's
     * own app secret.
     */
    /*
     * GitHub deliveries. Public, like lead ingestion, and throttled the same
     * way: the key in the path establishes the workspace, the signature is
     * checked against that workspace's own secret, and nothing in the body is
     * trusted before it verifies.
     */
    Route::post('integrations/github/webhook/{key}', GitHubWebhookController::class)
        ->middleware('throttle:webhook');

    Route::prefix('integrations/whatsapp/webhook')->middleware('throttle:whatsapp-webhook')->group(function () {
        Route::post('/', [WhatsappWebhookController::class, 'receive']);
        Route::get('/', [WhatsappWebhookController::class, 'verify']);
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

        /*
        | Tenant workspace. `tenant.active` blocks suspended / expired accounts.
        | Notifications live inside it: their payloads carry lead names and phone
        | numbers, so a suspended tenant must not be able to keep reading them.
        | Only /auth/me and the logout routes stay outside, so the SPA can still
        | sign a user out of a suspended account.
        */
        Route::middleware('tenant.active')->group(function () {
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
                ->whereUuid('id');
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

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

            // Whether messaging is available at all, for anyone who works leads.
            Route::get('/whatsapp/capability', [WhatsappSettingsController::class, 'capability']);

            // Conversation
            Route::get('/leads/{uuid}/messages', [LeadMessageController::class, 'index']);
            Route::post('/leads/{uuid}/messages', [LeadMessageController::class, 'store']);
            Route::post('/leads/{uuid}/messages/preview', [LeadMessageController::class, 'preview']);
            Route::post('/leads/{uuid}/whatsapp-opt-out', [LeadMessageController::class, 'optOut']);

            // Qualification
            Route::get('/qualification/options', [QualificationController::class, 'options']);
            Route::get('/leads/{uuid}/qualification', [QualificationController::class, 'show']);
            Route::patch('/leads/{uuid}/qualification', [QualificationController::class, 'update']);

            Route::get('/pipeline', [PipelineController::class, 'index']);

            // Follow-ups
            Route::get('/follow-ups', [FollowUpController::class, 'index']);
            Route::get('/follow-ups/counts', [FollowUpController::class, 'counts']);
            Route::post('/follow-ups', [FollowUpController::class, 'store']);
            Route::patch('/follow-ups/{uuid}', [FollowUpController::class, 'update']);
            Route::post('/follow-ups/{uuid}/complete', [FollowUpController::class, 'complete']);
            Route::delete('/follow-ups/{uuid}', [FollowUpController::class, 'destroy']);

            // The rep's work queue
            Route::get('/work/next', [WorkQueueController::class, 'next']);
            Route::get('/work/summary', [WorkQueueController::class, 'summary']);
            Route::post('/work/outcome', [WorkQueueController::class, 'outcome']);

            // Automation rules — written by the workspace, not by a developer.
            Route::get('/automation/schema', [AutomationRuleController::class, 'schema']);
            Route::get('/search', SearchController::class);
            Route::get('/data-quality', DataQualityController::class);

            // "Does any of this actually work for us?" — the first-day question.
            Route::get('/onboarding/checklist', [TestLeadController::class, 'checklist']);
            Route::post('/onboarding/test-lead', [TestLeadController::class, 'store']);
            Route::get('/onboarding/test-lead/{uuid}', [TestLeadController::class, 'show']);
            Route::delete('/onboarding/test-lead/{uuid}', [TestLeadController::class, 'destroy']);
            Route::get('/automation/templates', [AutomationRuleController::class, 'templates']);
            Route::post('/automation/templates/{key}', [AutomationRuleController::class, 'installTemplate']);
            Route::get('/automation/insights', [AutomationRuleController::class, 'insights']);
            Route::get('/automation/rules', [AutomationRuleController::class, 'index']);
            Route::post('/automation/rules', [AutomationRuleController::class, 'store']);
            Route::patch('/automation/rules/{uuid}', [AutomationRuleController::class, 'update']);
            Route::delete('/automation/rules/{uuid}', [AutomationRuleController::class, 'destroy']);
            Route::get('/automation/rules/{uuid}/preview', [AutomationRuleController::class, 'preview']);
            Route::post('/automation/rules/{uuid}/duplicate', [AutomationRuleController::class, 'duplicate']);
            Route::get('/leads/{leadUuid}/automation-runs', [AutomationRuleController::class, 'runsForLead']);

            /*
             * Cancelling sits with the lead, not with the rules: the authority
             * to change this lead is what it takes, because the person watching
             * it is the one who knows the message a rule promised is now wrong.
             */
            Route::delete('/scheduled-actions/{uuid}', [AutomationRuleController::class, 'cancelScheduled']);

            // Appointments
            Route::get('/appointments', [AppointmentController::class, 'index']);
            Route::post('/appointments', [AppointmentController::class, 'store']);
            Route::patch('/appointments/{uuid}', [AppointmentController::class, 'update']);

            // Projects
            Route::apiResource('projects', ProjectController::class)
                ->parameters(['projects' => 'uuid']);

            // Which repository a project is built in, and what has happened in
            // it. Nested under the project because that is whose question it is.
            Route::get('/projects/{uuid}/development', ProjectDevelopmentController::class);
            Route::get('/projects/{uuid}/repository', [GitHubController::class, 'forProject']);
            Route::post('/projects/{uuid}/repository', [GitHubController::class, 'link']);
            Route::delete('/projects/{uuid}/repository', [GitHubController::class, 'unlink']);

            // Users
            Route::get('/users/assignable', [UserController::class, 'assignable']);
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::patch('/users/{uuid}', [UserController::class, 'update']);
            Route::delete('/users/{uuid}', [UserController::class, 'destroy']);

            // Reports
            Route::get('/reports', [ReportController::class, 'index']);
            Route::get('/reports/drivers', [ReportController::class, 'drivers']);
            Route::get('/campaign-spend', [CampaignSpendController::class, 'index']);
            Route::post('/campaign-spend', [CampaignSpendController::class, 'store']);
            Route::delete('/campaign-spend/{id}', [CampaignSpendController::class, 'destroy']);
            Route::get('/reports/export', [ReportController::class, 'export']);

            /*
             * Which campaigns bring buyers rather than clicks, and how fast the
             * team works what arrives. Separate from /reports because the row
             * shape is wider than the shared one.
             */
            Route::get('/reports/campaign-quality', [SalesPerformanceController::class, 'campaignQuality']);
            Route::get('/reports/campaign-quality/export', [SalesPerformanceController::class, 'exportCampaignQuality']);
            Route::get('/reports/performance', [SalesPerformanceController::class, 'performance']);
            Route::get('/reports/sales-team', [SalesPerformanceController::class, 'salesTeam']);

            // Integrations (admin only, enforced by policy)
            Route::get('/integrations', [IntegrationController::class, 'index']);
            Route::get('/integrations/ingestion', [IntegrationController::class, 'ingestion']);
            Route::post('/integrations', [IntegrationController::class, 'store']);
            // WhatsApp Business. Secrets are written here and never read back.
            /*
             * GitHub, for the development activity a project manager needs to
             * see. Under `integrations` because it is one — same health, same
             * credentials handling, same audit trail — even though it brings in
             * no leads.
             */
            Route::get('/integrations/github', [GitHubController::class, 'show']);
            Route::post('/integrations/github', [GitHubController::class, 'connect']);
            Route::post('/integrations/github/test', [GitHubController::class, 'test']);
            Route::post('/integrations/github/sync', [GitHubController::class, 'sync']);
            Route::delete('/integrations/github', [GitHubController::class, 'disconnect']);
            Route::get('/integrations/github/repositories', [GitHubController::class, 'repositories']);
            Route::get('/integrations/github/discover', [GitHubController::class, 'discover']);

            /*
             * Leads that arrive in a spreadsheet. A source per tab, mapped
             * once, then read on a schedule — the same pipeline a webhook
             * feeds, never a second one.
             */
            Route::get('/integrations/sheets', [SheetSourceController::class, 'index']);
            Route::post('/integrations/sheets', [SheetSourceController::class, 'store']);
            Route::get('/integrations/sheets/spreadsheets', [SheetSourceController::class, 'spreadsheets']);
            Route::get('/integrations/sheets/tabs', [SheetSourceController::class, 'tabs']);
            Route::post('/integrations/sheets/preview', [SheetSourceController::class, 'preview']);
            Route::patch('/integrations/sheets/{uuid}', [SheetSourceController::class, 'update']);
            Route::delete('/integrations/sheets/{uuid}', [SheetSourceController::class, 'destroy']);
            Route::post('/integrations/sheets/{uuid}/sync', [SheetSourceController::class, 'sync']);

            Route::get('/integrations/whatsapp', [WhatsappSettingsController::class, 'show']);
            Route::patch('/integrations/whatsapp', [WhatsappSettingsController::class, 'update']);
            Route::post('/integrations/whatsapp/test', [WhatsappSettingsController::class, 'test']);
            Route::post('/integrations/whatsapp/rotate-verify-token', [WhatsappSettingsController::class, 'rotateVerifyToken']);
            Route::delete('/integrations/whatsapp', [WhatsappSettingsController::class, 'disconnect']);

            Route::get('/message-templates', [MessageTemplateController::class, 'index']);
            Route::post('/message-templates', [MessageTemplateController::class, 'store']);
            Route::patch('/message-templates/{uuid}', [MessageTemplateController::class, 'update']);
            Route::delete('/message-templates/{uuid}', [MessageTemplateController::class, 'destroy']);

            Route::patch('/integrations/{uuid}', [IntegrationController::class, 'update']);
            Route::delete('/integrations/{uuid}', [IntegrationController::class, 'destroy']);
            Route::post('/integrations/{uuid}/sync', [IntegrationController::class, 'sync']);
            Route::post('/integrations/{uuid}/webhooks', [IntegrationController::class, 'createWebhook']);
            Route::post('/webhook-endpoints/{uuid}/rotate', [IntegrationController::class, 'rotateWebhookSecret']);
            Route::delete('/webhook-endpoints/{uuid}', [IntegrationController::class, 'deleteWebhook']);
            Route::get('/webhook-logs', [IntegrationController::class, 'logs']);

            // CSV import
            Route::get('/imports', [ImportController::class, 'index']);
            Route::post('/imports', [ImportController::class, 'upload']);
            Route::get('/imports/{uuid}', [ImportController::class, 'show']);
            Route::post('/imports/{uuid}/process', [ImportController::class, 'process']);

            /*
             * Sales demo helper. Registered only outside production: it creates a
             * believable inbound lead on demand so the pipeline can be shown
             * working live. The controller refuses production as well.
             */
            if (! app()->environment('production')) {
                Route::post('/demo/simulate-lead', [DemoController::class, 'simulateLead']);
            }

            // Settings & white label
            Route::get('/settings', [SettingsController::class, 'show']);
            Route::patch('/settings/branding', [SettingsController::class, 'updateBranding']);
            Route::patch('/settings', [SettingsController::class, 'updateSettings']);
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

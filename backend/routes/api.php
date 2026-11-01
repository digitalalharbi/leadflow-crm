<?php

use App\Domain\Mail\EmailDnsReadiness;
use App\Http\Controllers\Api\SharedReportController;
use App\Http\Controllers\Api\V1\AccessController;
use App\Http\Controllers\Api\V1\AccountAccessController;
use App\Http\Controllers\Api\V1\AccountSecurityController;
use App\Http\Controllers\Api\V1\ActionProposalController;
use App\Http\Controllers\Api\V1\AdConnectionController;
use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AnomalySettingsController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AttributionController;
use App\Http\Controllers\Api\V1\AuditTrailController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationCentreController;
use App\Http\Controllers\Api\V1\AutomationRuleController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CallSettingsController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\CampaignSpendController;
use App\Http\Controllers\Api\V1\ClientRuleController;
use App\Http\Controllers\Api\V1\CommandCenterController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CreativeController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DataExportController;
use App\Http\Controllers\Api\V1\DataQualityController;
use App\Http\Controllers\Api\V1\DemoController;
use App\Http\Controllers\Api\V1\EmailDeliveryController;
use App\Http\Controllers\Api\V1\EmailSettingsController;
use App\Http\Controllers\Api\V1\FollowUpController;
use App\Http\Controllers\Api\V1\FollowUpPlanController;
use App\Http\Controllers\Api\V1\FormAssetController;
use App\Http\Controllers\Api\V1\GitHubController;
use App\Http\Controllers\Api\V1\GitHubWebhookController;
use App\Http\Controllers\Api\V1\GoogleOAuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\InsightController;
use App\Http\Controllers\Api\V1\IntegrationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\JourneyController;
use App\Http\Controllers\Api\V1\LandingFormController;
use App\Http\Controllers\Api\V1\LeadAnswerController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadEmailController;
use App\Http\Controllers\Api\V1\LeadMessageController;
use App\Http\Controllers\Api\V1\LeadPrivacyController;
use App\Http\Controllers\Api\V1\MessageTemplateController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\PartnerController;
use App\Http\Controllers\Api\V1\PipelineController;
use App\Http\Controllers\Api\V1\Platform\ImpersonationController;
use App\Http\Controllers\Api\V1\Platform\PlatformAuthController;
use App\Http\Controllers\Api\V1\ProjectAcquisitionController;
use App\Http\Controllers\Api\V1\ProjectActivityController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectDevelopmentController;
use App\Http\Controllers\Api\V1\ProjectFileController;
use App\Http\Controllers\Api\V1\ProjectLeadSourceController;
use App\Http\Controllers\Api\V1\ProjectQuestionController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\PublicApiController;
use App\Http\Controllers\Api\V1\PublicLandingController;
use App\Http\Controllers\Api\V1\QualificationController;
use App\Http\Controllers\Api\V1\ReadinessController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReportScheduleController;
use App\Http\Controllers\Api\V1\ReportShareController;
use App\Http\Controllers\Api\V1\RepPerformanceController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\SalesPerformanceController;
use App\Http\Controllers\Api\V1\SavedViewController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SelfApprovalController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SheetSourceController;
use App\Http\Controllers\Api\V1\SuperAdmin\InvoiceController;
use App\Http\Controllers\Api\V1\SuperAdmin\PlanController;
use App\Http\Controllers\Api\V1\SuperAdmin\PlatformController;
use App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionController;
use App\Http\Controllers\Api\V1\SuperAdmin\TenantController;
use App\Http\Controllers\Api\V1\SuppressionController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TestLeadController;
use App\Http\Controllers\Api\V1\TrackBeaconController;
use App\Http\Controllers\Api\V1\TrackingDestinationController;
use App\Http\Controllers\Api\V1\TrackingHealthController;
use App\Http\Controllers\Api\V1\TrackingLinkController;
use App\Http\Controllers\Api\V1\TwilioStatusController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionController;
use App\Http\Controllers\Api\V1\WhatsappSettingsController;
use App\Http\Controllers\Api\V1\WhatsappWebhookController;
use App\Http\Controllers\Api\V1\WorkQueueController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\EmailInboundController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsurePlatformSession;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\RequireWorkspaceMfa;
use App\Support\ApiResponse;
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
    // Into an account without a password in hand. Every answer is the same whether or not an email is registered.
    Route::middleware('throttle:login')->group(function () {
        Route::post('/auth/password/forgot', [AccountAccessController::class, 'forgot']);
        Route::get('/auth/password/links/{token}', [AccountAccessController::class, 'link'])->where('token', '[A-Za-z0-9]{48}');
        Route::post('/auth/password/reset', [AccountAccessController::class, 'reset']);
        Route::get('/public/invitations/{token}', [AccountAccessController::class, 'invitation'])->where('token', '[A-Za-z0-9]{48}');
        Route::post('/public/invitations/{token}/accept', [AccountAccessController::class, 'accept'])->where('token', '[A-Za-z0-9]{48}');
    });

    // The platform owner's own door — never a company's login.
    Route::post('/platform/auth/login', [PlatformAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', EnsurePlatformSession::class])->prefix('platform/auth')->group(function () {
        Route::get('/me', [PlatformAuthController::class, 'me']);
        Route::post('/logout', [PlatformAuthController::class, 'logout']);
        Route::post('/mfa/setup', [PlatformAuthController::class, 'setupMfa']);
        Route::post('/mfa/confirm', [PlatformAuthController::class, 'confirmMfa'])->middleware('throttle:login');
        Route::delete('/mfa', [PlatformAuthController::class, 'disableMfa'])->middleware('throttle:login');
    });

    // What a landing page tells us a visitor did. Public; the link token says whose it is.
    Route::post('/track/{token}', TrackBeaconController::class)->middleware('throttle:landing-beacon')->where('token', '[A-Za-z0-9]{6,24}');

    // The landing page a tracking link opens, and its form. Public by nature; the token is the only thing the visitor holds.
    Route::get('/public/form-assets/{name}', [FormAssetController::class, 'show'])->middleware('throttle:landing-asset')->where('name', '[a-f0-9]{32}\.(png|jpg|webp|svg)');
    Route::get('/public/landing/{token}', [PublicLandingController::class, 'show'])->middleware('throttle:landing-view')->where('token', '[A-Za-z0-9-]{3,64}');
    Route::post('/public/landing/{token}/submit', [PublicLandingController::class, 'submit'])->middleware('throttle:landing-submit')->where('token', '[A-Za-z0-9-]{3,64}');

    // A live report behind a link. The token is the credential; a password may be the second. Throttled hard.
    Route::get('/shared/reports/{token}', SharedReportController::class)->middleware('throttle:60,1')->where('token', '[A-Za-z0-9]{40}');

    // A person's calendar feed, read by their calendar app. The secret in the address is the credential.
    Route::get('/public/calendar/{token}.ics', [CalendarController::class, 'publicFeed'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{48}');

    // The public API: a company's own data through one of its API keys (Authorization: Bearer mht_…). No session.
    Route::prefix('public')->middleware('throttle:api-key')->group(function () {
        Route::get('/leads', [PublicApiController::class, 'leads'])->middleware(AuthenticateApiKey::class.':leads:read');
        Route::post('/leads', [PublicApiController::class, 'storeLead'])->middleware(AuthenticateApiKey::class.':leads:write');
        Route::get('/leads/{uuid}', [PublicApiController::class, 'showLead'])->middleware(AuthenticateApiKey::class.':leads:read')->whereUuid('uuid');
        Route::get('/projects', [PublicApiController::class, 'projects'])->middleware(AuthenticateApiKey::class.':projects:read');
        Route::get('/visits', [PublicApiController::class, 'visits'])->middleware(AuthenticateApiKey::class.':visits:read');
        Route::get('/reservations', [PublicApiController::class, 'reservations'])->middleware(AuthenticateApiKey::class.':reservations:read');
        Route::get('/revenue', [PublicApiController::class, 'revenue'])->middleware(AuthenticateApiKey::class.':revenue:read');
    });

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

    /*
     * Twilio call status. Public for the same reason as the two above: Twilio
     * is not a signed-in user. The integration's uuid in the path names the
     * workspace and `X-Twilio-Signature`, keyed with that workspace's own auth
     * token, is what makes the name believable.
     */
    Route::post('integrations/calls/twilio/{integrationUuid}/status', TwilioStatusController::class)
        ->middleware('throttle:webhook');

    /*
     * "A row was just added." Sent by a one-line Apps Script trigger on the
     * sheet, so a lead reaches the queue in seconds rather than at the next
     * scheduled sweep. The path names the sheet and carries its own secret;
     * the ping only asks for a sync — nothing in its body is believed.
     */
    Route::post('integrations/sheets/{uuid}/ping/{token}', [SheetSourceController::class, 'ping'])
        ->whereUuid('uuid')
        ->middleware('throttle:webhook');

    /*
     * Email replies and bounces from the mail provider's inbound webhook. The
     * secret is the platform's, set where the provider is configured; replies
     * are matched to the exact email they answer, never by sender alone.
     */
    Route::post('integrations/email/inbound/{secret}', [EmailInboundController::class, 'receive'])
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
    Route::middleware(['auth:sanctum', 'tenant', RequireWorkspaceMfa::class, 'support.guard', 'company', 'project.scope', 'permission', 'hide.fields'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/auth/security', [AccountSecurityController::class, 'show']);
        Route::post('/auth/security/mfa/setup', [AccountSecurityController::class, 'setup'])->middleware('throttle:10,1');
        Route::post('/auth/security/mfa/confirm', [AccountSecurityController::class, 'confirm'])->middleware('throttle:10,1');
        Route::post('/auth/security/mfa/disable', [AccountSecurityController::class, 'disable'])->middleware('throttle:10,1');
        Route::get('/auth/security/sessions', [AccountSecurityController::class, 'sessions']);
        Route::delete('/auth/security/sessions/{id}', [AccountSecurityController::class, 'endSession'])->whereNumber('id');
        Route::post('/auth/password', [AuthController::class, 'changePassword']);
        // One person in several companies: the server lists their memberships and moves the session.
        Route::get('/auth/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/auth/workspaces/{uuid}/switch', [WorkspaceController::class, 'switch'])->middleware('throttle:login');
        // Support ends from inside the company, with the support session's own token.
        Route::post('/auth/impersonation/stop', [ImpersonationController::class, 'stop']);
        // Outside `tenant.active`: a suspended company's admin can still read why, and what to do.
        Route::get('/billing', [BillingController::class, 'show']);
        Route::get('/auth/calendar-feed', [CalendarController::class, 'feed']);
        Route::post('/auth/calendar-feed/rotate', [CalendarController::class, 'rotate']);
        Route::delete('/auth/calendar-feed', [CalendarController::class, 'disable']);
        Route::get('/auth/notification-preferences', [NotificationPreferenceController::class, 'show']);
        Route::patch('/auth/notification-preferences', [NotificationPreferenceController::class, 'update']);
        Route::get('/auth/notification-preferences/whatsapp-log', [NotificationPreferenceController::class, 'whatsappLog']);

        /*
        | Tenant workspace. `tenant.active` blocks suspended / expired accounts.
        | Notifications live inside it: their payloads carry lead names and phone
        | numbers, so a suspended tenant must not be able to keep reading them.
        | Only /auth/me and the logout routes stay outside, so the SPA can still
        | sign a user out of a suspended account.
        */
        Route::middleware(['tenant.active', 'entitlements', 'audit.exports'])->group(function () {
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
                ->whereUuid('id');
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

            Route::get('/dashboard', [DashboardController::class, 'index']);
            Route::get('/dashboard/command', [CommandCenterController::class, 'index']);
            Route::get('/dashboard/manager', [CommandCenterController::class, 'manager']);
            Route::get('/dashboard/journey', [JourneyController::class, 'show']);
            Route::get('/dashboard/ops', [InsightController::class, 'ops']);
            Route::get('/dashboard/home', [InsightController::class, 'home']);
            Route::get('/insights', [InsightController::class, 'index']);
            Route::get('/insights/recommendations', [InsightController::class, 'recommendations']);
            Route::get('/audit', AuditTrailController::class);
            Route::post('/anomalies/{uuid}/dismiss', [InsightController::class, 'dismiss'])->whereUuid('uuid');
            Route::get('/anomaly-settings', [AnomalySettingsController::class, 'show']);
            Route::patch('/anomaly-settings', [AnomalySettingsController::class, 'update']);
            Route::get('/settings/self-approval', [SelfApprovalController::class, 'show']);
            Route::patch('/settings/self-approval', [SelfApprovalController::class, 'update']);
            Route::patch('/settings/security', [AccountSecurityController::class, 'policy']);
            Route::get('/settings/suppressions', [SuppressionController::class, 'index']);
            Route::post('/settings/suppressions', [SuppressionController::class, 'store']);
            Route::delete('/settings/suppressions/{uuid}', [SuppressionController::class, 'destroy'])->whereUuid('uuid');
            Route::get('/projects/{uuid}/questions', [ProjectQuestionController::class, 'index']);
            Route::post('/projects/{uuid}/questions', [ProjectQuestionController::class, 'store']);
            Route::post('/projects/{uuid}/questions/real-estate', [ProjectQuestionController::class, 'seedRealEstate']);
            Route::patch('/project-questions/{uuid}', [ProjectQuestionController::class, 'update']);
            Route::delete('/project-questions/{uuid}', [ProjectQuestionController::class, 'destroy']);
            Route::get('/leads/{uuid}/answers', [LeadAnswerController::class, 'show']);
            Route::put('/leads/{uuid}/answers', [LeadAnswerController::class, 'update']);
            Route::get('/companies', [CompanyController::class, 'index']);
            Route::post('/companies', [CompanyController::class, 'store']);
            Route::patch('/companies/{uuid}', [CompanyController::class, 'update']);
            Route::delete('/companies/{uuid}', [CompanyController::class, 'destroy']);
            Route::put('/companies/{uuid}/members', [CompanyController::class, 'members']);
            Route::get('/companies/{uuid}/projects', [CompanyController::class, 'projects']);

            // Tasks
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::patch('/tasks/{uuid}', [TaskController::class, 'update'])->whereUuid('uuid');
            Route::delete('/tasks/{uuid}', [TaskController::class, 'destroy'])->whereUuid('uuid');

            // Leads
            Route::get('/leads/export', [LeadController::class, 'export']);
            Route::get('/exports', [DataExportController::class, 'index']);
            Route::post('/exports', [DataExportController::class, 'store'])->middleware('throttle:10,1');
            Route::post('/exports/{uuid}/link', [DataExportController::class, 'link']);
            Route::get('/leads/segments', [LeadController::class, 'segments']);
            Route::get('/leads/tags', [LeadController::class, 'tags']);
            Route::get('/leads/saved-views', [SavedViewController::class, 'index']);
            Route::post('/leads/saved-views', [SavedViewController::class, 'store']);
            Route::delete('/leads/saved-views/{uuid}', [SavedViewController::class, 'destroy'])->whereUuid('uuid');
            Route::get('/leads/overview', [LeadController::class, 'overview']);
            Route::get('/leads', [LeadController::class, 'index']);
            Route::post('/leads', [LeadController::class, 'store']);
            Route::get('/leads/{uuid}', [LeadController::class, 'show']);
            Route::get('/leads/{uuid}/journey', [LeadController::class, 'journey']);
            Route::get('/leads/{uuid}/follow-up-plan', [FollowUpPlanController::class, 'show']);
            Route::post('/leads/{uuid}/follow-up-plan/pause', [FollowUpPlanController::class, 'pause']);
            Route::post('/leads/{uuid}/follow-up-plan/resume', [FollowUpPlanController::class, 'resume']);
            Route::post('/leads/{uuid}/follow-up-plan/stop', [FollowUpPlanController::class, 'stop']);
            Route::post('/leads/{uuid}/follow-up-plan/reschedule', [FollowUpPlanController::class, 'reschedule']);
            Route::post('/leads/{uuid}/conversation/analyze', [LeadController::class, 'analyzeConversation']);
            Route::get('/leads/{uuid}/duplicates', [LeadController::class, 'duplicates']);
            Route::post('/leads/{uuid}/merge', [LeadController::class, 'merge']);
            Route::patch('/leads/{uuid}', [LeadController::class, 'update']);
            Route::delete('/leads/{uuid}', [LeadController::class, 'destroy']);
            Route::post('/leads/{uuid}/assign', [LeadController::class, 'assign']);
            Route::post('/leads/{uuid}/status', [LeadController::class, 'changeStatus']);
            Route::post('/leads/{uuid}/temperature', [LeadController::class, 'changeTemperature']);
            Route::put('/leads/{uuid}/tags', [LeadController::class, 'setTags']);
            Route::get('/leads/{uuid}/personal-data', [LeadPrivacyController::class, 'export']);
            Route::post('/leads/{uuid}/erase', [LeadPrivacyController::class, 'erase']);
            Route::post('/leads/{uuid}/interest', [LeadController::class, 'setInterest']);
            Route::post('/leads/{uuid}/notes', [LeadController::class, 'addNote']);
            Route::post('/leads/{uuid}/contact', [LeadController::class, 'logContact']);
            // What came of a call, once the rep knows. Nothing watches a rep's
            // phone, so the product learns it when a person says so.
            Route::post('/leads/{uuid}/calls/{call}', [LeadController::class, 'recordCall']);

            // Whether messaging is available at all, for anyone who works leads.
            Route::get('/whatsapp/capability', [WhatsappSettingsController::class, 'capability']);

            // Conversation
            Route::get('/leads/{uuid}/messages', [LeadMessageController::class, 'index']);
            Route::post('/leads/{uuid}/messages', [LeadMessageController::class, 'store']);
            Route::post('/leads/{uuid}/messages/preview', [LeadMessageController::class, 'preview']);
            Route::post('/leads/{uuid}/whatsapp-opt-out', [LeadMessageController::class, 'optOut']);
            Route::post('/leads/{uuid}/emails', [LeadEmailController::class, 'store']);
            Route::post('/leads/{uuid}/email-opt-out', [LeadEmailController::class, 'optOut']);
            Route::get('/email/capability', [EmailSettingsController::class, 'capability']);

            // Qualification
            Route::get('/qualification/options', [QualificationController::class, 'options']);
            Route::get('/leads/{uuid}/qualification', [QualificationController::class, 'show']);
            Route::patch('/leads/{uuid}/qualification', [QualificationController::class, 'update']);

            Route::get('/pipeline', [PipelineController::class, 'index']);
            Route::get('/pipeline/overview', [PipelineController::class, 'overview']);

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
            Route::get('/automation/summary', [AutomationCentreController::class, 'summary']);
            Route::get('/automation/history', [AutomationCentreController::class, 'history']);
            Route::get('/automation/routines', [AutomationCentreController::class, 'routines']);
            Route::get('/projects/{uuid}/scoring', [AutomationCentreController::class, 'scoring'])->whereUuid('uuid');
            Route::get('/projects/{uuid}/thresholds', [AutomationCentreController::class, 'thresholds'])->whereUuid('uuid');
            Route::patch('/projects/{uuid}/thresholds', [AutomationCentreController::class, 'updateThresholds'])->whereUuid('uuid');
            Route::get('/projects/{uuid}/automation-pack', [AutomationCentreController::class, 'pack'])->whereUuid('uuid');
            Route::post('/projects/{uuid}/automation-pack', [AutomationCentreController::class, 'completePack'])->whereUuid('uuid');
            Route::post('/projects/{uuid}/automation-pack/activate', [AutomationCentreController::class, 'activatePack'])->whereUuid('uuid');
            Route::post('/projects/{uuid}/automation-pack/pause', [AutomationCentreController::class, 'pausePack'])->whereUuid('uuid');
            Route::patch('/projects/{uuid}/scoring', [AutomationCentreController::class, 'updateScoring'])->whereUuid('uuid');
            Route::get('/automation/rules', [AutomationRuleController::class, 'index']);
            Route::post('/automation/rules', [AutomationRuleController::class, 'store']);
            Route::patch('/automation/rules/{uuid}', [AutomationRuleController::class, 'update']);
            Route::delete('/automation/rules/{uuid}', [AutomationRuleController::class, 'destroy']);
            Route::get('/automation/rules/{uuid}/preview', [AutomationRuleController::class, 'preview']);
            Route::get('/automation/rules/{uuid}/simulate', [AutomationRuleController::class, 'simulate']);
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
            Route::get('/appointments/{uuid}/ics', [CalendarController::class, 'appointment']);
            Route::post('/appointments/{uuid}/transition', [AppointmentController::class, 'transition']);
            Route::post('/appointments/{uuid}/outcome', [AppointmentController::class, 'outcome']);
            Route::get('/reservations', [ReservationController::class, 'index']);
            Route::post('/reservations', [ReservationController::class, 'store']);
            Route::post('/reservations/{uuid}/{action}', [ReservationController::class, 'act'])->whereUuid('uuid')->whereIn('action', ['confirm', 'cancel', 'convert']);

            // Projects
            Route::apiResource('projects', ProjectController::class)
                ->parameters(['projects' => 'uuid']);

            // Tracking links, and who gets the credit for the leads they bring.
            Route::get('/tracking/links', [TrackingLinkController::class, 'index']);
            Route::post('/tracking/links', [TrackingLinkController::class, 'store']);
            Route::get('/tracking/links/{uuid}', [TrackingLinkController::class, 'show'])->whereUuid('uuid');
            Route::get('/tracking/links/{uuid}/qr', [TrackingLinkController::class, 'qr'])->whereUuid('uuid');
            Route::get('/tracking/links/{uuid}/analytics', [TrackingLinkController::class, 'analytics'])->whereUuid('uuid');
            Route::get('/partners', [PartnerController::class, 'index']);
            Route::post('/partners', [PartnerController::class, 'store']);
            Route::patch('/partners/{uuid}', [PartnerController::class, 'update'])->whereUuid('uuid');
            Route::get('/partners/{uuid}/statement', [PartnerController::class, 'statement'])->whereUuid('uuid');
            Route::post('/partners/{uuid}/commission-payments', [PartnerController::class, 'pay'])->whereUuid('uuid');
            Route::delete('/partners/{uuid}/commission-payments/{payment}', [PartnerController::class, 'voidPayment'])->whereUuid('uuid');
            Route::post('/partners/{uuid}/statement-link', [PartnerController::class, 'statementLink'])->whereUuid('uuid');
            Route::delete('/partners/{uuid}', [PartnerController::class, 'destroy'])->whereUuid('uuid');
            // Acquisition: inside a project, where its customers come from and whether the tracking can be trusted.
            Route::get('/projects/{uuid}/acquisition', ProjectAcquisitionController::class)->whereUuid('uuid');
            // Where events can go: GA4, Meta, TikTok, a GTM container, a webhook… configuration and readiness only.
            Route::get('/tracking/destinations/catalogue', [TrackingDestinationController::class, 'catalogue']);
            Route::get('/tracking/destinations', [TrackingDestinationController::class, 'index']);
            Route::post('/tracking/destinations', [TrackingDestinationController::class, 'store']);
            Route::get('/tracking/destinations/{uuid}', [TrackingDestinationController::class, 'show'])->whereUuid('uuid');
            Route::patch('/tracking/destinations/{uuid}', [TrackingDestinationController::class, 'update'])->whereUuid('uuid');
            Route::delete('/tracking/destinations/{uuid}', [TrackingDestinationController::class, 'destroy'])->whereUuid('uuid');
            // The landing pages a link can open, and the form on each.
            // Everything that brings a project its leads, on one list.
            Route::get('/projects/{uuid}/lead-sources', [ProjectLeadSourceController::class, 'index'])->whereUuid('uuid');
            Route::get('/projects/{uuid}/lead-sources/compare', [ProjectLeadSourceController::class, 'compare'])->whereUuid('uuid');
            Route::get('/projects/{uuid}/lead-sources/mappings', [ProjectLeadSourceController::class, 'mappings'])->whereUuid('uuid');
            Route::post('/projects/{uuid}/lead-sources/mappings', [ProjectLeadSourceController::class, 'storeMapping'])->whereUuid('uuid');
            Route::patch('/projects/{uuid}/lead-sources/mappings/{mapping}', [ProjectLeadSourceController::class, 'updateMapping'])->whereUuid(['uuid', 'mapping']);
            Route::delete('/projects/{uuid}/lead-sources/mappings/{mapping}', [ProjectLeadSourceController::class, 'destroyMapping'])->whereUuid(['uuid', 'mapping']);
            Route::patch('/projects/{uuid}/lead-sources/logo', [ProjectLeadSourceController::class, 'logo'])->whereUuid('uuid');

            Route::get('/tracking/forms/presets', [LandingFormController::class, 'presets']);
            Route::get('/tracking/forms/templates', [LandingFormController::class, 'templates']);
            Route::post('/tracking/forms/slug', [LandingFormController::class, 'slug']);
            Route::post('/tracking/forms/assets', [FormAssetController::class, 'store']);
            Route::post('/tracking/forms/{uuid}/publish', [LandingFormController::class, 'publish'])->whereUuid('uuid');
            Route::get('/tracking/forms/{uuid}/analytics', [LandingFormController::class, 'analytics'])->whereUuid('uuid');
            Route::get('/tracking/forms/{uuid}/versions', [LandingFormController::class, 'versions'])->whereUuid('uuid');
            Route::post('/tracking/forms/{uuid}/duplicate', [LandingFormController::class, 'duplicate'])->whereUuid('uuid');
            Route::post('/tracking/forms/{uuid}/links', [LandingFormController::class, 'addLink'])->whereUuid('uuid');
            Route::get('/tracking/forms', [LandingFormController::class, 'index']);
            Route::post('/tracking/forms', [LandingFormController::class, 'store']);
            Route::get('/tracking/forms/{uuid}', [LandingFormController::class, 'show'])->whereUuid('uuid');
            Route::patch('/tracking/forms/{uuid}', [LandingFormController::class, 'update'])->whereUuid('uuid');
            Route::delete('/tracking/forms/{uuid}', [LandingFormController::class, 'destroy'])->whereUuid('uuid');
            Route::patch('/tracking/links/{uuid}', [TrackingLinkController::class, 'update'])->whereUuid('uuid');
            Route::delete('/tracking/links/{uuid}', [TrackingLinkController::class, 'destroy'])->whereUuid('uuid');
            Route::get('/tracking/attribution', AttributionController::class);
            Route::get('/tracking/attribution/compare', [AttributionController::class, 'compare']);
            Route::get('/tracking/attribution/options', [AttributionController::class, 'options']);
            Route::get('/tracking/health', TrackingHealthController::class);

            // The units a project sells or lets.
            Route::get('/properties', [PropertyController::class, 'index']);
            Route::get('/projects/{uuid}/properties', [PropertyController::class, 'forProject'])->whereUuid('uuid');
            Route::post('/projects/{uuid}/properties', [PropertyController::class, 'store'])->whereUuid('uuid');
            Route::patch('/properties/{uuid}', [PropertyController::class, 'update'])->whereUuid('uuid');
            Route::delete('/properties/{uuid}', [PropertyController::class, 'destroy'])->whereUuid('uuid');

            // Which repository a project is built in, and what has happened in
            // it. Nested under the project because that is whose question it is.
            // The files a project sells with, and which one suits a lead.
            Route::get('/projects/{uuid}/files', [ProjectFileController::class, 'index']);
            Route::post('/projects/{uuid}/files', [ProjectFileController::class, 'store']);
            Route::patch('/projects/{uuid}/files/{file}', [ProjectFileController::class, 'update']);
            Route::delete('/projects/{uuid}/files/{file}', [ProjectFileController::class, 'destroy']);
            Route::get('/projects/{uuid}/files/{file}/link', [ProjectFileController::class, 'link']);
            Route::get('/leads/{uuid}/files', [ProjectFileController::class, 'forLead']);
            Route::post('/leads/{uuid}/files/share', [ProjectFileController::class, 'share']);
            Route::post('/leads/{uuid}/files/send', [ProjectFileController::class, 'send']);
            Route::get('/leads/{uuid}/shares', [ProjectFileController::class, 'sharesFor']);
            Route::post('/leads/{uuid}/shares/{share}/revoke', [ProjectFileController::class, 'revokeShare'])->whereUuid('share');

            Route::get('/projects/{uuid}/activity', ProjectActivityController::class);
            Route::get('/projects/{uuid}/development', ProjectDevelopmentController::class);
            Route::get('/projects/{uuid}/repository', [GitHubController::class, 'forProject']);
            Route::post('/projects/{uuid}/repository', [GitHubController::class, 'link']);
            Route::delete('/projects/{uuid}/repository', [GitHubController::class, 'unlink']);

            // Users
            Route::get('/users/assignable', [UserController::class, 'assignable']);
            Route::get('/roles', [UserController::class, 'roles']);
            Route::get('/access', [AccessController::class, 'show']);
            Route::post('/access/roles', [AccessController::class, 'storeRole']);
            Route::patch('/access/roles/{key}', [AccessController::class, 'updateRole']);
            Route::delete('/access/roles/{uuid}', [AccessController::class, 'destroyRole']);
            Route::put('/access/rules', [AccessController::class, 'putRules']);
            Route::get('/access/users/{uuid}', [AccessController::class, 'effective']);
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            // Bringing people in: an invitation by email; nobody sets a password for anyone else.
            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::post('/invitations/{uuid}/resend', [InvitationController::class, 'resend']);
            Route::delete('/invitations/{uuid}', [InvitationController::class, 'destroy']);
            Route::post('/users/{uuid}/password-link', [UserController::class, 'passwordLink']);
            Route::patch('/users/{uuid}', [UserController::class, 'update']);
            Route::delete('/users/{uuid}', [UserController::class, 'destroy']);

            // Reports
            Route::get('/reports', [ReportController::class, 'index']);
            Route::get('/reports/insights', [ReportController::class, 'insights']);
            Route::get('/report-shares', [ReportShareController::class, 'index']);
            Route::post('/report-shares', [ReportShareController::class, 'store']);
            Route::patch('/report-shares/{uuid}', [ReportShareController::class, 'update']);
            Route::get('/report-shares/{uuid}/preview', [ReportShareController::class, 'preview']);
            Route::get('/report-shares/{uuid}/analytics', [ReportShareController::class, 'analytics']);
            Route::post('/report-shares/{uuid}/email', [ReportShareController::class, 'email']);
            Route::get('/report-shares/{uuid}/schedules', [ReportScheduleController::class, 'index']);
            Route::post('/report-shares/{uuid}/schedules', [ReportScheduleController::class, 'store']);
            Route::patch('/report-schedules/{uuid}', [ReportScheduleController::class, 'update']);
            Route::delete('/report-schedules/{uuid}', [ReportScheduleController::class, 'destroy']);
            Route::get('/email-deliveries', [EmailDeliveryController::class, 'index']);
            Route::post('/email-deliveries/{uuid}/retry', [EmailDeliveryController::class, 'retry']);
            Route::post('/report-shares/{uuid}/revoke', [ReportShareController::class, 'revoke']);
            Route::post('/report-shares/{uuid}/regenerate', [ReportShareController::class, 'regenerate']);
            Route::delete('/report-shares/{uuid}', [ReportShareController::class, 'destroy']);
            Route::get('/reports/drivers', [ReportController::class, 'drivers']);
            Route::get('/campaigns', [CampaignController::class, 'index']);
            Route::get('/campaigns/platforms', [CampaignController::class, 'platforms']);
            Route::get('/campaigns/revenue', [CampaignController::class, 'revenue']);
            Route::get('/campaigns/operations', [CampaignController::class, 'operations']);
            Route::get('/ad-connections/{platform}', [AdConnectionController::class, 'show']);
            Route::put('/ad-connections/{platform}', [AdConnectionController::class, 'update']);
            Route::post('/ad-connections/{platform}/test', [AdConnectionController::class, 'test']);
            Route::delete('/ad-connections/{platform}', [AdConnectionController::class, 'destroy']);
            Route::get('/ad-connections/{platform}/lead-forms', [AdConnectionController::class, 'leadForms']);
            Route::post('/campaigns/sync', [CampaignController::class, 'sync']);
            Route::get('/campaigns/{uuid}', [CampaignController::class, 'show'])->whereUuid('uuid');
            Route::post('/campaigns/{uuid}/operate', [CampaignController::class, 'operate'])->whereUuid('uuid');
            Route::get('/campaigns/{uuid}/tracking-health', [CampaignController::class, 'trackingHealth'])->whereUuid('uuid');

            // Nothing reaches an ad account except through here.
            Route::get('/action-proposals', [ActionProposalController::class, 'index']);
            Route::post('/action-proposals', [ActionProposalController::class, 'store']);
            Route::patch('/action-proposals/{uuid}', [ActionProposalController::class, 'update'])->whereUuid('uuid');
            Route::post('/action-proposals/{uuid}/approve', [ActionProposalController::class, 'approve'])->whereUuid('uuid');
            Route::post('/action-proposals/{uuid}/reject', [ActionProposalController::class, 'reject'])->whereUuid('uuid');
            Route::get('/agents', [AgentController::class, 'index']);
            Route::post('/agents/routines/{routine}/run', [AgentController::class, 'run']);
            Route::get('/agents/threads/{uuid}', [AgentController::class, 'thread'])->whereUuid('uuid');
            Route::get('/agents/conversations', [AgentController::class, 'conversations']);
            Route::post('/agents/conversations', [AgentController::class, 'start'])->middleware('throttle:30,1');
            Route::post('/agents/conversations/{uuid}/messages', [AgentController::class, 'say'])->whereUuid('uuid')->middleware('throttle:30,1');
            Route::get('/agents/ai', [AgentController::class, 'ai']);
            Route::put('/agents/ai', [AgentController::class, 'setKey']);
            Route::delete('/agents/ai', [AgentController::class, 'forgetKey']);
            Route::get('/creatives', [CreativeController::class, 'index']);
            Route::get('/creatives/{uuid}/drafts', [CreativeController::class, 'drafts'])->whereUuid('uuid');
            Route::post('/creatives/{uuid}/drafts', [CreativeController::class, 'store'])->whereUuid('uuid');
            Route::post('/creatives/{uuid}/generate', [CreativeController::class, 'generate'])->whereUuid('uuid')->middleware('throttle:20,1');
            Route::patch('/creative-drafts/{uuid}', [CreativeController::class, 'updateDraft'])->whereUuid('uuid');
            Route::get('/agent-reports', [AgentController::class, 'reports']);
            Route::get('/agent-reports/{uuid}', [AgentController::class, 'report'])->whereUuid('uuid');
            Route::get('/reports/marketing-sales', [CampaignController::class, 'marketingSales']);
            Route::get('/reports/quality', [CampaignController::class, 'quality']);
            Route::get('/conversions/health', [CampaignController::class, 'conversionHealth']);
            Route::get('/client-rules', [ClientRuleController::class, 'show']);
            Route::put('/client-rules', [ClientRuleController::class, 'update']);

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
            Route::get('/reports/sales-performance', RepPerformanceController::class);
            Route::get('/reports/sales-team', [SalesPerformanceController::class, 'salesTeam']);

            // Integrations (admin only, enforced by policy)
            Route::get('/integrations', [IntegrationController::class, 'index']);
            Route::get('/integrations/ingestion', [IntegrationController::class, 'ingestion']);
            // Which integrations have ever actually worked, as opposed to which
            // ones are written. Computed from evidence, never asserted.
            Route::get('/integrations/health', [IntegrationController::class, 'health']);
            Route::post('/integrations/sync', [IntegrationController::class, 'syncAll']);
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
            /*
             * Starting and ending a Google grant. The middle of it happens on
             * Google's own screens, and the way back is a public web route.
             */
            // Is this deployment live, and what is it waiting for — and the
            // button that uses a credential once, against the real provider.
            Route::get('/readiness', [ReadinessController::class, 'workspace']);
            // SPF / DKIM / DMARC for the sending domain, read from live DNS (never assumed).
            Route::get('/readiness/email-dns', fn (EmailDnsReadiness $dns) => ApiResponse::success($dns->check()))->middleware('throttle:10,1');
            Route::post('/readiness/{key}/commission', [ReadinessController::class, 'commission'])
                ->middleware('throttle:10,1');

            // The workspace's own telephony account.
            Route::get('/integrations/calls', [CallSettingsController::class, 'show']);
            Route::put('/integrations/calls', [CallSettingsController::class, 'update']);
            Route::post('/integrations/calls/test', [CallSettingsController::class, 'test']);
            Route::delete('/integrations/calls', [CallSettingsController::class, 'destroy']);

            Route::post('/integrations/google/authorize', [GoogleOAuthController::class, 'start']);
            Route::post('/integrations/google/picker', [GoogleOAuthController::class, 'picker']);
            Route::post('/integrations/google/disconnect', [GoogleOAuthController::class, 'disconnect']);

            Route::get('/integrations/sheets', [SheetSourceController::class, 'index']);
            Route::post('/integrations/sheets', [SheetSourceController::class, 'store']);
            Route::get('/integrations/sheets/tabs', [SheetSourceController::class, 'tabs']);
            Route::post('/integrations/sheets/preview', [SheetSourceController::class, 'preview']);
            Route::patch('/integrations/sheets/{uuid}', [SheetSourceController::class, 'update']);
            Route::delete('/integrations/sheets/{uuid}', [SheetSourceController::class, 'destroy']);
            Route::post('/integrations/sheets/{uuid}/sync', [SheetSourceController::class, 'sync']);
            Route::patch('/integrations/sheets/{uuid}/mapping', [SheetSourceController::class, 'remap']);

            Route::get('/integrations/whatsapp', [WhatsappSettingsController::class, 'show']);
            Route::patch('/integrations/whatsapp', [WhatsappSettingsController::class, 'update']);
            Route::post('/integrations/whatsapp/test', [WhatsappSettingsController::class, 'test']);
            Route::post('/integrations/whatsapp/rotate-verify-token', [WhatsappSettingsController::class, 'rotateVerifyToken']);
            Route::delete('/integrations/whatsapp', [WhatsappSettingsController::class, 'disconnect']);
            Route::get('/integrations/email', [EmailSettingsController::class, 'show']);
            Route::patch('/integrations/email', [EmailSettingsController::class, 'update']);
            Route::post('/integrations/email/test', [EmailSettingsController::class, 'test']);
            Route::delete('/integrations/email', [EmailSettingsController::class, 'disconnect']);

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
            Route::get('/api-keys', [ApiKeyController::class, 'index']);
            Route::post('/api-keys', [ApiKeyController::class, 'store']);
            Route::patch('/api-keys/{uuid}', [ApiKeyController::class, 'update'])->whereUuid('uuid');
            Route::post('/api-keys/{uuid}/rotate', [ApiKeyController::class, 'rotate'])->whereUuid('uuid');
            Route::delete('/api-keys/{uuid}', [ApiKeyController::class, 'destroy'])->whereUuid('uuid');
            Route::get('/webhook-subscriptions', [WebhookSubscriptionController::class, 'index']);
            Route::post('/webhook-subscriptions', [WebhookSubscriptionController::class, 'store']);
            Route::patch('/webhook-subscriptions/{uuid}', [WebhookSubscriptionController::class, 'update'])->whereUuid('uuid');
            Route::delete('/webhook-subscriptions/{uuid}', [WebhookSubscriptionController::class, 'destroy'])->whereUuid('uuid');
            Route::post('/webhook-subscriptions/{uuid}/rotate', [WebhookSubscriptionController::class, 'rotate'])->whereUuid('uuid');
            Route::post('/webhook-subscriptions/{uuid}/test', [WebhookSubscriptionController::class, 'test'])->whereUuid('uuid');
            Route::get('/webhook-subscriptions/{uuid}/deliveries', [WebhookSubscriptionController::class, 'deliveries'])->whereUuid('uuid');
            Route::post('/webhook-deliveries/{uuid}/replay', [WebhookSubscriptionController::class, 'replay'])->whereUuid('uuid');
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
            Route::get('/readiness', [ReadinessController::class, 'platform']);
            Route::get('/stats', [TenantController::class, 'stats']);
            Route::get('/tenants', [TenantController::class, 'index']);
            Route::post('/tenants', [TenantController::class, 'store']);
            Route::get('/tenants/{uuid}', [TenantController::class, 'show']);
            Route::patch('/tenants/{uuid}', [TenantController::class, 'update']);
            Route::post('/tenants/{uuid}/admins', [TenantController::class, 'createAdmin']);

            // Support access: explicit start with a reason, short-lived, written to both trails.
            Route::get('/impersonations', [ImpersonationController::class, 'index']);
            Route::post('/tenants/{uuid}/impersonate', [ImpersonationController::class, 'start']);
            Route::post('/impersonations/{uuid}/revoke', [ImpersonationController::class, 'revoke']);
            Route::post('/tenants/{uuid}/owner', [TenantController::class, 'transferOwner']);
            Route::post('/tenants/{uuid}/owner/setup-link', [TenantController::class, 'ownerSetupLink']);

            // The SaaS business: catalogue, subscriptions, invoices, the owner's dashboard and trail.
            Route::get('/dashboard', [PlatformController::class, 'dashboard']);
            Route::get('/audit', [PlatformController::class, 'audit']);
            Route::get('/plans', [PlanController::class, 'index']);
            Route::post('/plans', [PlanController::class, 'store']);
            Route::patch('/plans/{uuid}', [PlanController::class, 'update']);
            Route::get('/tenants/{uuid}/subscription', [SubscriptionController::class, 'show']);
            Route::patch('/tenants/{uuid}/subscription', [SubscriptionController::class, 'update']);
            Route::post('/tenants/{uuid}/subscription/{action}', [SubscriptionController::class, 'transition'])
                ->whereIn('action', ['activate', 'extend', 'past_due', 'suspend', 'reactivate', 'cancel']);
            Route::post('/tenants/{uuid}/invoices', [InvoiceController::class, 'store']);
            Route::post('/invoices/{uuid}/{action}', [InvoiceController::class, 'transition'])->whereIn('action', ['paid', 'void', 'issued']);
        });
    });
});

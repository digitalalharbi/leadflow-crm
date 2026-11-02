<?php

use App\Http\Middleware\AuditExports;
use App\Http\Middleware\EnforceEntitlements;
use App\Http\Middleware\EnforcePermissions;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\GuardSupportSession;
use App\Http\Middleware\HideRestrictedFields;
use App\Http\Middleware\ResolveCompanyContext;
use App\Http\Middleware\ResolveProjectScope;
use App\Http\Middleware\ResolveTenantContext;
use App\Support\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    /*
     * Discovery off. The lead listeners are registered by hand in AppServiceProvider.
     *
     * `configure()` switches on automatic discovery of app/Listeners, which binds
     * each listener to whatever event its `handle()` is typed against — exactly
     * the binding AppServiceProvider already makes explicitly. Both were in
     * force, so every listener sat on `LeadDomainEvent` twice and every lead
     * event ran every automation rule twice: two identical notes, two WhatsApp
     * messages to the same customer, every score change applied twice. Nothing
     * in the engine deduplicates, and the trace showed two runs with different
     * correlation ids, so it read as two genuine events rather than a bug.
     *
     * Keeping the explicit registration and dropping discovery, not the reverse:
     * discovery is invisible at the call site, it stops silently if the
     * `handle()` parameter type is ever narrowed, and left on it would re-arm
     * the same trap for the next listener anybody adds.
     */
    ->withEvents(discover: false)
    /*
     * The live feed's authorisation endpoint, on the API's own terms.
     *
     * Laravel's default puts it on the `web` stack, which means session and
     * CSRF — and this SPA authenticates with a Bearer token from another
     * origin, so every subscription would be refused with a 403 that says
     * nothing. Mounted under /api with `auth:sanctum`, it answers to exactly
     * the credential the rest of the client already holds.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Deliberately NOT $middleware->statefulApi().
         *
         * The SPA authenticates with a Bearer token and is served from a
         * different origin to the API in every deployed environment. Enabling
         * Sanctum's cookie mode makes any request whose Origin matches a
         * "stateful domain" go through session + CSRF instead — which answers
         * 419 to every write unless the client first fetches a CSRF cookie.
         * Nothing here uses cookie auth, so the safest configuration is to not
         * offer it at all.
         */

        // Without this the `api` rate limiter defined in AppServiceProvider is
        // never attached, and every authenticated endpoint is unthrottled.
        $middleware->throttleApi();

        // The approval page posts with its own single-use token, from a phone with
        // no session; that token is the credential, so there is no CSRF to check.
        // A mail client's one-click unsubscribe is the same: no session, and the signed token decides.
        $middleware->validateCsrfTokens(except: ['approve/*', 'e/u/*']);

        // The tracking visitor id is an opaque random token a landing page may read back; encrypting
        // it would make it unreadable to the page and add nothing — it carries no information.
        $middleware->encryptCookies(except: ['mt_vid', 'mt_sid']);

        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenantContext::class,
            'company' => ResolveCompanyContext::class,
            'tenant.active' => EnsureTenantIsActive::class,
            'entitlements' => EnforceEntitlements::class,
            'support.guard' => GuardSupportSession::class,
            'audit.exports' => AuditExports::class,
            'project.scope' => ResolveProjectScope::class,
            'permission' => EnforcePermissions::class,
            'hide.fields' => HideRestrictedFields::class,
        ]);

        // Trusting every proxy would make X-Forwarded-For caller-controlled, which
        // lets the login limiter be bypassed and forges the IP in the audit trail.
        // Set TRUSTED_PROXIES to the load balancer's address or CIDR in production.
        // '*' is dropped rather than honoured: a deployment that wants every
        // proxy trusted must name the network, so the decision is visible.
        $middleware->trustProxies(at: array_values(array_filter(
            array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
            fn (string $proxy) => $proxy !== '' && $proxy !== '*' && $proxy !== '**',
        )));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reports to Sentry when SENTRY_LARAVEL_DSN is set; does nothing while it is empty.
        Integration::handles($exceptions);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiExceptionRenderer::render($e, $request);
        });
    })->create();

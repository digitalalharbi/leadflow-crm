<?php

use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\ResolveTenantContext;
use App\Support\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
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

        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenantContext::class,
            'tenant.active' => EnsureTenantIsActive::class,
        ]);

        // Trusting every proxy would make X-Forwarded-For caller-controlled, which
        // lets the login limiter be bypassed and forges the IP in the audit trail.
        // Set TRUSTED_PROXIES to the load balancer's address or CIDR in production.
        $middleware->trustProxies(at: array_values(array_filter(
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiExceptionRenderer::render($e, $request);
        });
    })->create();

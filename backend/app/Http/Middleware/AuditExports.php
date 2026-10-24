<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every export that leaves the building is on the company's trail: who, which export, with which filters. One place,
 * so an export added tomorrow is recorded without anyone remembering to.
 */
class AuditExports
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && str_ends_with(rtrim($request->path(), '/'), '/export') && $response->isSuccessful() && $request->user()?->tenant_id) {
            $this->audit->log('data.exported', $request->user(), null, [
                'export' => preg_replace('#^api/v1/#', '', $request->path()),
                'filters' => array_keys($request->query()),
            ]);
        }

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which companies this person may see and which one they chose.
 *
 * Permission comes from membership, never from the request: a user who belongs
 * to companies is limited to them, an admin is limited to none, and a user who
 * belongs to none sees what their role has always let them see. The switcher's
 * header only ever *narrows* inside that — asking for a company outside the
 * permitted set is a 403, not a silent widening.
 *
 * Runs after the tenant is bound, because the company lookup is scoped by it:
 * naming another workspace's company finds nothing.
 */
class ResolveCompanyContext
{
    public const HEADER = 'X-Company-Id';

    public function __construct(private CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $this->context->clear();

        if ($user === null || $user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->isAdmin()) {
            $memberOf = $user->companies()->pluck('companies.id')->all();

            if ($memberOf !== []) {
                $this->context->restrictTo($memberOf);
            }
        }

        $asked = $request->header(self::HEADER);

        if (filled($asked)) {
            $company = Company::where('uuid', $asked)->where('is_active', true)->first();

            // Not found, or not theirs to look at: the same answer, so the
            // header cannot be used to learn which companies exist.
            if (! $company || ($this->context->allowed() !== null && ! in_array($company->id, $this->context->allowed(), true))) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'That company is not available to you.',
                    'errors' => null,
                ], 403);
            }

            $this->context->select($company->id);
        }

        return $next($request);
    }

    /**
     * Cleared when the response has been sent, not when the handler returns.
     *
     * A streamed export builds its rows after the handler is done; clearing
     * earlier would let a download made with a company selected come out
     * unfiltered — the one place a leak would be a file, not a screen.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }
}

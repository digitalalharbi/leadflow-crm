<?php

namespace App\Domain\Exports;

use App\Http\Middleware\ResolveCompanyContext;
use App\Http\Middleware\ResolveProjectScope;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\ProjectContext;
use Illuminate\Http\Request;

/**
 * Runs work in a queue job with the same company and project limits the person had when they asked — by running the
 * very middleware that set them in the request, so a background export can never see more than the screen did.
 */
class ActingScope
{
    public function __construct(private ResolveCompanyContext $companies, private ResolveProjectScope $projects, private CompanyContext $companyContext, private ProjectContext $projectContext) {}

    public function run(User $user, ?string $companyUuid, callable $work): mixed
    {
        $request = Request::create('/api/v1/exports', 'POST');
        $request->setUserResolver(fn () => $user);

        if (filled($companyUuid)) {
            $request->headers->set(ResolveCompanyContext::HEADER, $companyUuid);
        }

        $result = null;
        $ran = false;

        try {
            // Full closures, not arrow functions: an arrow function captures by value and the inner by-reference
            // would bind to its copy.
            $this->companies->handle($request, function () use ($request, $work, &$result, &$ran) {
                return $this->projects->handle($request, function () use ($work, &$result, &$ran) {
                    $result = $work();
                    $ran = true;

                    return response('');
                });
            });
        } finally {
            $this->companyContext->clear();
            $this->projectContext->clear();
        }

        // The company middleware answers 403 for a company the person no longer reaches: nothing is exported then.
        abort_unless($ran, 403, 'That company is not available to you.');

        return $result;
    }
}

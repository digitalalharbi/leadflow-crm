<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Lead;
use App\Support\ProjectContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Binds the projects a scoped role may see (the ones they were given) for the length of the request. */
class ResolveProjectScope
{
    public function __construct(private ProjectContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();
        $user = $request->user();

        if ($user !== null && $user->isProjectScoped()) {
            // No projects given means nothing to see, not everything: an empty list is a real restriction.
            $this->context->restrictTo($user->projects()->pluck('projects.id')->map(fn ($id) => (int) $id)->all());
        }

        // A rep whose account says so opens only the projects they work on; earlier accounts keep seeing every project.
        if ($user !== null && $user->role === Role::Sales && $user->all_projects === false && ! $user->isProjectScoped()) {
            $fromLeads = Lead::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->where('assigned_to', $user->id)
                ->whereNotNull('project_id')->distinct()->pluck('project_id');
            $this->context->restrictProjectListTo($user->projects()->pluck('projects.id')->concat($fromLeads)->map(fn ($id) => (int) $id)->unique()->values()->all());
        }

        // A broker is limited to what their own partner brought — and to nothing until they are linked to one.
        if ($user !== null && $user->role === Role::BrokerViewer) {
            $this->context->restrictToPartner($user->partner_id ?? -1);
        }

        return $next($request);
    }

    /** Cleared when the response has gone, so a streamed export keeps its scope until its last row. */
    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }
}

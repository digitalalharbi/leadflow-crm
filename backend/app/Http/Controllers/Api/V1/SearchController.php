<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\Leads\LeadQueryBuilder;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * One box, everything findable.
 *
 * A rep with a customer on the phone has the number and nothing else. Making
 * them pick a screen first, then a filter, then type, is three decisions
 * between a ringing phone and a name — and what they do instead is answer
 * without knowing who it is.
 *
 * Leads are searched through the same builder the leads list uses, not a second
 * query that happens to look similar. Two definitions of "search" that disagree
 * about what a phone number matches is the kind of thing somebody discovers
 * while a customer is waiting.
 */
class SearchController extends Controller
{
    /** Enough to recognise the right one; not a replacement for the list. */
    private const PER_GROUP = 5;

    public function __construct(private readonly LeadQueryBuilder $leads) {}

    public function __invoke(Request $request)
    {
        $term = trim($request->string('q')->toString());

        // Two characters matches half the database and tells nobody anything.
        if (mb_strlen($term) < 2) {
            return ApiResponse::success(['leads' => [], 'projects' => [], 'people' => []]);
        }

        return ApiResponse::success([
            'leads' => $this->leads($request, $term),
            'projects' => $this->projects($term),
            'people' => $this->people($request, $term),
        ]);
    }

    private function leads(Request $request, string $term): array
    {
        $query = Lead::query()->visibleTo($request->user());

        // Same rules as the list: a rep finds their own leads here and nobody
        // else's, exactly as they would on the leads screen.
        $this->leads->applySearch($query, $term);

        return $query
            ->with('assignee:id,uuid,name')
            ->latest('id')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Lead $lead) => [
                'id' => $lead->uuid,
                'name' => $lead->full_name,
                'phone' => $lead->phone,
                'status' => $lead->status?->value,
                'assignee' => $lead->assignee?->name,
            ])
            ->all();
    }

    private function projects(string $term): array
    {
        return Project::query()
            ->where('name', 'ilike', '%'.$term.'%')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Project $project) => ['id' => $project->uuid, 'name' => $project->name])
            ->all();
    }

    /**
     * Colleagues, for the manager asking "what is نورة carrying?".
     *
     * Only for people who can see the whole workspace: a rep has no business
     * enumerating the team through a search box, and an email address is the
     * sort of thing that leaks one query at a time.
     */
    private function people(Request $request, string $term): array
    {
        if (! $request->user()?->seesAllLeads()) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'ilike', '%'.$term.'%')
                ->orWhere('email', 'ilike', '%'.$term.'%'))
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->uuid,
                'name' => $user->name,
                'role' => $user->role?->value,
            ])
            ->all();
    }
}

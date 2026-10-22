<?php

namespace App\Http\Middleware;

use App\Domain\Access\Permissions;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes out of a response what the person may not have — whichever endpoint it came from.
 *
 * A role without `contacts.view` never receives a phone number, an email address or a WhatsApp
 * number; one without `spend.view` never receives what the ads cost; one without `revenue.view`
 * never receives what the sales brought. Done once, on the way out, by key name — so a new
 * endpoint that happens to include a phone number cannot leak it to a client viewer by being new.
 * The specialised roles are scrubbed by what their table withholds; a long-standing role only by
 * what its workspace took away.
 */
class HideRestrictedFields
{
    /** @var array<string,list<string>> */
    private const KEYS = [
        'contacts' => ['phone', 'phone_normalized', 'whatsapp', 'lead_phone', 'email', 'lead_email'],
        'spend' => ['spend', 'spend_source', 'cpl', 'cpql', 'cpa', 'cac', 'roas', 'daily_budget', 'cost', 'cost_per_appointment', 'cost_per_interested', 'cost_per_visit', 'cost_per_reservation', 'costPer', 'budget_spent'],
        'revenue' => ['revenue', 'revenue_today', 'commission', 'revenue_per_lead', 'deal_value', 'deal_value_minor', 'amount', 'won_value', 'ledger', 'commission_fixed', 'commission_fixed_minor', 'commission_payments'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();

        if ($user === null || ! $response instanceof JsonResponse || str_starts_with($request->path(), 'api/v1/auth/')) {
            return $response;
        }

        $hidden = Permissions::hiddenData($user);
        $keys = array_merge([], ...array_map(fn (string $kind) => self::KEYS[$kind], $hidden), ...$this->elementKeys($request, $user));

        if ($keys === []) {
            return $response;
        }

        $response->setData($this->scrub($response->getData(true), array_flip($keys)));

        return $response;
    }

    /**
     * The keys of the elements this person has switched off, for the path asked — hiding a field in
     * the interface is the API's act too.
     *
     * @return list<list<string>>
     */
    private function elementKeys(Request $request, User $user): array
    {
        $path = trim(preg_replace('#^api/v1/#', '', $request->path()), '/');
        $out = [];

        foreach (Permissions::hiddenElements($user) as $key) {
            $scrub = Permissions::element($key)['scrub'] ?? null;

            if ($scrub && Str::is($scrub['paths'] ?? [], $path)) {
                $out[] = $scrub['keys'];
            }
        }

        return $out;
    }

    /** @param  array<string,int>  $drop */
    private function scrub(mixed $value, array $drop): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && isset($drop[$key])) {
                continue;
            }

            $out[$key] = $this->scrub($item, $drop);
        }

        return $out;
    }
}

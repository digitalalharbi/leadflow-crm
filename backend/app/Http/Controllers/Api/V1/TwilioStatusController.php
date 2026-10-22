<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Calls\Providers\TwilioCallProvider;
use App\Domain\Leads\OutcomeRecorder;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * What Twilio says happened to a call.
 *
 * The only place a call's status moves without a person saying so, which makes
 * it the only place a duration can be measured rather than guessed. So it is
 * strict about what it believes: the signature is checked before the body is
 * read, a status never moves backwards, and a terminal state is final.
 */
class TwilioStatusController extends Controller
{
    /** How far along a call is, so a late "ringing" cannot undo a "completed". */
    private const RANK = [
        Call::INITIATED => 0,
        Call::RINGING => 1,
        Call::ANSWERED => 2,
        Call::COMPLETED => 3,
        Call::NO_ANSWER => 3,
        Call::FAILED => 3,
    ];

    public function __construct(
        private readonly TwilioCallProvider $twilio,
        private readonly TenantContext $tenants,
        private readonly OutcomeRecorder $outcomes,
    ) {}

    public function __invoke(Request $request, string $integrationUuid)
    {
        // Without scopes: nothing about this request says which workspace it is
        // for until the integration has been found. The URL names one, and the
        // signature below is what makes the name believable.
        $integration = Integration::withoutGlobalScopes()
            ->where('uuid', $integrationUuid)
            ->where('provider', 'twilio_voice')
            ->first();

        if (! $integration) {
            return response('', 404);
        }

        $params = $request->post();

        if (! $this->twilio->validSignature($integration, $params, $request->header('X-Twilio-Signature'))) {
            return response('', 403);
        }

        $status = TwilioCallProvider::mapStatus((string) ($params['CallStatus'] ?? ''));
        $sid = (string) ($params['CallSid'] ?? '');

        if ($status === null || $sid === '') {
            // Acknowledged: an event we have no word for is not worth Twilio
            // retrying, and retrying would not make us understand it.
            return response('', 204);
        }

        $this->tenants->withTenant($integration->tenant, function () use ($sid, $status, $params, $integration) {
            $call = Call::where('external_id', $sid)->where('provider', 'twilio')->first();

            if (! $call) {
                return;
            }

            // A finished call is final, and the rest only moves forward. Twilio
            // does not promise order, and a "ringing" that arrives after
            // "completed" must not reopen a call that is over.
            $current = self::RANK[$call->status] ?? 0;

            if ($current >= 3 || (self::RANK[$status] ?? 0) < $current) {
                return;
            }

            $terminal = (self::RANK[$status] ?? 0) >= 3;

            $call->forceFill(array_filter([
                'status' => $status,
                'ended_at' => $terminal ? now() : null,
                // Measured by Twilio, so it is the one duration in this product
                // that `wasMeasured()` is entitled to vouch for.
                'duration_seconds' => isset($params['CallDuration']) ? (int) $params['CallDuration'] : null,
            ], fn ($value) => $value !== null))->save();

            if ($status === Call::NO_ANSWER) {
                $this->followUp($call);
            }

            $integration->forceFill([
                'status' => Integration::CONNECTED,
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();
        });

        return response('', 204);
    }

    /**
     * An unanswered call is the one outcome the provider can state outright, and
     * it is also the one a rep most often forgets to record. So it is recorded,
     * with the same follow-up a rep pressing the button would have got.
     *
     * Only this one. A call that connected tells us nothing about how it went —
     * that is what the rep is for — and a failed one is a fault, not an outcome.
     */
    private function followUp(Call $call): void
    {
        $lead = Lead::find($call->lead_id);
        $user = $call->user_id ? User::find($call->user_id) : null;

        if (! $lead || ! $user) {
            return;
        }

        $call->forceFill(['outcome' => 'no_answer'])->save();

        $this->outcomes->record($lead, 'no_answer', $user, null, 'telephony');
    }
}

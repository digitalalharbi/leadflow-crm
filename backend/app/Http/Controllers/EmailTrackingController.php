<?php

namespace App\Http\Controllers;

use App\Domain\Privacy\SuppressionList;
use App\Enums\ActivityType;
use App\Enums\MessageStatus;
use App\Events\LeadSignal;
use App\Models\Lead;
use App\Models\LeadEngagement;
use App\Models\LeadMessage;
use App\Models\Tenant;
use App\Services\Email\TrackedLinks;
use App\Services\Leads\ActivityLogger;
use App\Services\Leads\LeadScoringService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What comes back from an email: it was opened, a link was pressed, or the
 * customer asked us to stop.
 *
 * Public and unauthenticated, because the caller is a customer's mail client.
 * The only thing it trusts is the token it was given, and every handler ends by
 * serving what the customer came for whatever our tables are doing: a link
 * that does not redirect because we failed to count it is a customer who never
 * reaches the brochure.
 */
class EmailTrackingController extends Controller
{
    /** The smallest valid GIF. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private TenantContext $context,
        private TrackedLinks $links,
        private LeadScoringService $scoring,
        private ActivityLogger $activities,
    ) {}

    public function open(string $token)
    {
        $this->record($token, LeadEngagement::EMAIL_OPENED);

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            // A cached pixel is an open that is never counted twice, and one
            // that is never counted at all if the client keeps it.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function click(Request $request, string $token)
    {
        $url = $this->links->decode((string) $request->query('u'));

        // A link we did not write does not redirect.
        if ($url === null || ! $this->links->verify($token, $url, (string) $request->query('s'))) {
            abort(404);
        }

        $this->record($token, LeadEngagement::LINK_CLICKED, ['url' => $url]);

        return redirect()->away($url);
    }

    /** GET for a person in a browser, POST for a mail client's one-click header. */
    public function unsubscribe(Request $request, string $token)
    {
        $message = LeadMessage::withoutGlobalScopes()->where('tracking_token', $token)->first();

        if ($message) {
            $lead = Lead::withoutGlobalScopes()->find($message->lead_id);
            $tenant = $lead ? Tenant::withoutGlobalScopes()->find($lead->tenant_id) : null;

            if ($lead && $tenant && $lead->email_opt_out_at === null) {
                $this->context->withTenant($tenant, function () use ($lead) {
                    $lead->forceFill(['email_opt_out_at' => now()])->saveQuietly();
                    $this->activities->log($lead, ActivityType::EmailOptedOut, null, 'ألغى الاشتراك من رسائل البريد');

                    if ($lead->email) {
                        app(SuppressionList::class)->add($lead->tenant_id, 'email', $lead->email, 'opt_out', null, 'unsubscribe_link');
                    }
                });
            }
        }

        if ($request->isMethod('post')) {
            return response('', 200);
        }

        // The same page whether the token was real or not: it must not say which.
        return new Response(
            '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>تم إلغاء الاشتراك</title><body style="font-family:sans-serif;text-align:center;padding:4rem 1rem">'
            .'<h1 style="font-size:1.25rem">تم إلغاء اشتراكك</h1><p>لن تصلك رسائل بريد أخرى من هذا المرسل.</p></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    /**
     * Writes the engagement and moves the lead's score.
     *
     * Never throws: see the class docblock.
     *
     * @param  array<string,mixed>  $meta
     */
    private function record(string $token, string $type, array $meta = []): void
    {
        try {
            $message = LeadMessage::withoutGlobalScopes()->where('tracking_token', $token)->first();
            $lead = $message ? Lead::withoutGlobalScopes()->find($message->lead_id) : null;
            $tenant = $lead ? Tenant::withoutGlobalScopes()->find($lead->tenant_id) : null;

            if (! $message || ! $lead || ! $tenant) {
                return;
            }

            $this->context->withTenant($tenant, function () use ($message, $lead, $type, $meta) {
                $message->forceFill([
                    'read_at' => $message->read_at ?? now(),
                    'status' => in_array($message->status, [MessageStatus::Sent, MessageStatus::Delivered], true)
                        ? MessageStatus::Read
                        : $message->status,
                ])->save();

                /*
                 * One open per message per ten minutes. A mail client that
                 * prefetches, a preview pane and a person reading are one
                 * event to a salesperson, and counting each would score a lead
                 * as keen because their phone is.
                 */
                $already = $type === LeadEngagement::EMAIL_OPENED && LeadEngagement::where('lead_id', $lead->id)
                    ->where('type', $type)
                    ->where('happened_at', '>=', now()->subMinutes(10))
                    ->where('meta->message', $message->uuid)
                    ->exists();

                if ($already) {
                    return;
                }

                LeadEngagement::create([
                    'lead_id' => $lead->id,
                    'type' => $type,
                    'meta' => ['message' => $message->uuid] + $meta,
                    'happened_at' => now(),
                ]);

                $this->scoring->refresh($lead, 'engagement');

                LeadSignal::dispatch($lead->fresh(), $type === LeadEngagement::EMAIL_OPENED ? 'lead.email_opened' : 'lead.email_clicked', ['message' => $message->uuid]);
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

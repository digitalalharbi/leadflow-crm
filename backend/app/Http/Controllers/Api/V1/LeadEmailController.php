<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Privacy\SuppressionList;
use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadMessageResource;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Suppression;
use App\Services\AuditLogger;
use App\Services\Email\EmailSender;
use App\Services\Leads\ActivityLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** An email from a rep to a lead, written by hand or from a saved template. */
class LeadEmailController extends Controller
{
    public function __construct(
        private EmailSender $sender,
        private ActivityLogger $activities,
        private AuditLogger $audit,
    ) {}

    public function store(Request $request, string $uuid)
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $lead);

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:250'],
            'body' => ['nullable', 'string', 'max:20000'],
            'template_uuid' => ['nullable', 'uuid'],
        ]);

        $template = null;

        if (filled($data['template_uuid'] ?? null)) {
            $template = MessageTemplate::where('uuid', $data['template_uuid'])
                ->where('channel', 'email')->active()->first();

            if (! $template) {
                return ApiResponse::error('That email template is no longer available.', null, 422);
            }
        }

        try {
            $message = $template
                ? $this->sender->sendSaved($lead, $template, $request->user(), $data['subject'] ?? null, $data['body'] ?? null)
                : $this->sender->send($lead, (string) ($data['subject'] ?? ''), (string) ($data['body'] ?? ''), $request->user());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }

        return ApiResponse::created(
            ['message' => new LeadMessageResource($message->load('user:id,uuid,name'))],
            'Email queued.',
        );
    }

    /** Stop emailing this lead. Re-enabling is an admin's call, as it is for WhatsApp. */
    public function optOut(Request $request, string $uuid)
    {
        $lead = Lead::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $lead);

        $data = $request->validate(['opted_out' => ['required', 'boolean']]);

        if ($data['opted_out'] === false && ! $request->user()->isAdmin()) {
            return ApiResponse::error('Only an admin can re-enable email for a lead.', null, 403);
        }

        $lead->forceFill(['email_opt_out_at' => $data['opted_out'] ? now() : null])->saveQuietly();

        // The address, not just this record: another lead with it must stop too, and an admin's yes must not be undone by the list.
        if ($lead->email && $data['opted_out']) {
            app(SuppressionList::class)->add($lead->tenant_id, 'email', $lead->email, 'opt_out', $request->user()->id, 'manual_toggle');
        } elseif ($lead->email) {
            Suppression::query()->where('channel', 'email')->where('value', mb_strtolower($lead->email))->delete();
        }

        $this->activities->log(
            $lead,
            ActivityType::EmailOptedOut,
            $request->user(),
            $data['opted_out'] ? 'أوقف استقبال رسائل البريد' : 'أعاد تفعيل رسائل البريد',
        );

        $this->audit->log('lead.email_opt_out', $request->user(), $lead, ['opted_out' => $data['opted_out']]);

        return ApiResponse::success(['email_opt_out_at' => $lead->email_opt_out_at?->toIso8601String()]);
    }
}

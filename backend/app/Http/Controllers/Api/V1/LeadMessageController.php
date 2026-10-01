<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadMessageResource;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Services\AuditLogger;
use App\Services\Leads\ActivityLogger;
use App\Services\Whatsapp\TemplateRenderer;
use App\Services\Whatsapp\WhatsappManager;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The conversation on a lead: reading it, and adding to it.
 *
 * Deliberately not an inbox. There is no cross-lead view, no unread counter and
 * no assignment from a conversation — a rep works a lead, and the conversation
 * is part of that lead.
 */
class LeadMessageController extends Controller
{
    public function __construct(
        private WhatsappSender $sender,
        private WhatsappManager $manager,
        private TemplateRenderer $renderer,
        private ActivityLogger $activities,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request, string $uuid)
    {
        $lead = $this->lead($uuid);
        $this->authorize('view', $lead);

        // Paginated from the newest end: a long conversation must not make the
        // lead screen slower to open than a short one.
        $messages = $lead->messages()
            ->with('user:id,uuid,name')
            ->latest('id')
            // Floored as well as capped: the query builder silently ignores a
            // negative limit, so `per_page=-1` emitted no LIMIT at all and
            // returned a year of conversation in one response.
            ->paginate(max(1, min((int) $request->input('per_page', 30), 100)));

        return ApiResponse::paginated($messages, LeadMessageResource::class);
    }

    public function store(Request $request, string $uuid)
    {
        $lead = $this->lead($uuid);
        $this->authorize('update', $lead);

        $data = $request->validate([
            'body' => ['required_without:template_uuid', 'nullable', 'string', 'max:'.config('services.whatsapp.max_body_length')],
            'template_uuid' => ['nullable', 'uuid'],
        ]);

        $template = null;

        if (filled($data['template_uuid'] ?? null)) {
            $template = MessageTemplate::where('uuid', $data['template_uuid'])->active()->first();

            if (! $template) {
                return ApiResponse::error('That template is no longer available.', null, 422);
            }
        }

        // A rep may edit a template before sending; what they typed wins.
        $body = filled($data['body'] ?? null)
            ? $data['body']
            : $this->renderer->render($template->body, $lead, $request->user());

        if (trim($body) === '') {
            return ApiResponse::error('The message is empty.', null, 422);
        }

        try {
            $message = $this->sender->send($lead, $body, $request->user(), $template?->name);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }

        return ApiResponse::created(
            ['message' => new LeadMessageResource($message->load('user:id,uuid,name'))],
            $message->status->value === 'failed' ? 'WhatsApp could not deliver this message.' : 'Message sent.',
        );
    }

    /** Preview a template filled in for this lead, before anyone sends it. */
    public function preview(Request $request, string $uuid)
    {
        $lead = $this->lead($uuid);
        $this->authorize('view', $lead);

        $data = $request->validate(['template_uuid' => ['required', 'uuid']]);

        $template = MessageTemplate::where('uuid', $data['template_uuid'])->active()->firstOrFail();

        return ApiResponse::success([
            'body' => $this->renderer->render($template->body, $lead, $request->user()),
        ]);
    }

    /**
     * Stop messaging this lead. Reversible by an admin, because a rep ticking
     * the wrong row should not cost the customer their contact permanently.
     */
    public function optOut(Request $request, string $uuid)
    {
        $lead = $this->lead($uuid);
        $this->authorize('update', $lead);

        $data = $request->validate(['opted_out' => ['required', 'boolean']]);

        if ($data['opted_out'] === false && ! $request->user()->isAdmin()) {
            return ApiResponse::error('Only an admin can re-enable WhatsApp for a lead.', null, 403);
        }

        $lead->forceFill([
            'whatsapp_opt_out_at' => $data['opted_out'] ? now() : null,
        ])->saveQuietly();

        $this->activities->log(
            $lead,
            ActivityType::WhatsappOptedOut,
            $request->user(),
            $data['opted_out'] ? 'أوقف استقبال رسائل واتساب' : 'أعاد تفعيل رسائل واتساب',
        );

        $this->audit->log('lead.whatsapp_opt_out', $request->user(), $lead, ['opted_out' => $data['opted_out']]);

        return ApiResponse::success([
            'whatsapp_opt_out_at' => $lead->whatsapp_opt_out_at?->toIso8601String(),
        ]);
    }

    private function lead(string $uuid): Lead
    {
        return Lead::where('uuid', $uuid)->firstOrFail();
    }
}

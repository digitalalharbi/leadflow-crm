<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MessageChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageTemplateResource;
use App\Models\MessageTemplate;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A workspace's saved messages. Reps read them; admins write them.
 */
class MessageTemplateController extends Controller
{
    public function index(Request $request)
    {
        // Every rep reads these; only a manager sees the deactivated ones.
        $includeInactive = $request->boolean('all')
            && $request->user()->can('viewInactive', MessageTemplate::class);

        $templates = MessageTemplate::query()
            ->when(! $includeInactive, fn ($q) => $q->active())
            // WhatsApp unless asked otherwise: this endpoint was WhatsApp's alone
            // for a long time, and a caller that never heard of email must not
            // start receiving email templates in its list.
            ->where('channel', $request->input('channel') === 'email' ? 'email' : 'whatsapp')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'templates' => MessageTemplateResource::collection($templates),
            'variables' => MessageTemplate::VARIABLES,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', MessageTemplate::class);

        $data = $this->validated($request);

        $channel = ($data['channel'] ?? null) === 'email' ? MessageChannel::Email : MessageChannel::Whatsapp;
        unset($data['channel']);

        $template = MessageTemplate::create($data + ['channel' => $channel->value]);

        return ApiResponse::created(['template' => new MessageTemplateResource($template)]);
    }

    public function update(Request $request, string $uuid)
    {
        $template = MessageTemplate::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $template);

        $data = $this->validated($request, $template);
        // A template does not change channel: its words were written for one.
        unset($data['channel']);

        $template->update($data);

        return ApiResponse::success(['template' => new MessageTemplateResource($template->refresh())]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $template = MessageTemplate::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $template);

        $template->delete();

        return ApiResponse::noContent();
    }

    private function validated(Request $request, ?MessageTemplate $existing = null): array
    {
        return $request->validate([
            'name' => [
                $existing ? 'sometimes' : 'required', 'string', 'max:120',
                Rule::unique('message_templates', 'name')
                    ->where('tenant_id', $existing?->tenant_id ?? app(TenantContext::class)->id())
                    ->ignore($existing?->id),
            ],
            'body' => [$existing ? 'sometimes' : 'required', 'string', 'max:'.config('services.whatsapp.max_body_length')],
            'is_active' => ['nullable', 'boolean'],
            'channel' => ['sometimes', Rule::in(['whatsapp', 'email'])],
            'subject' => ['sometimes', 'nullable', 'string', 'max:250'],

            /*
             * The Meta-approved twin of this message.
             *
             * Meta's template names are lower-case letters, digits and
             * underscores and nothing else — a name with a space or a capital
             * is a template that does not exist, and the failure arrives as a
             * bare error code on the first real send. Refused here, at the
             * point it is typed.
             */
            'meta_template_name' => ['sometimes', 'nullable', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'meta_language' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            // The lead fields that fill {{1}}, {{2}}, … in order. A closed list,
            // so a template cannot ask for a value the renderer cannot supply.
            'meta_parameters' => ['sometimes', 'nullable', 'array', 'max:10'],
            'meta_parameters.*' => ['string', Rule::in(MessageTemplate::VARIABLES)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
    }
}

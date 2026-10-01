<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MessageChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageTemplateResource;
use App\Models\Integration;
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
        $templates = MessageTemplate::query()
            ->when(! $request->boolean('all'), fn ($q) => $q->active())
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
        $this->authorize('create', Integration::class);

        $data = $this->validated($request);

        $template = MessageTemplate::create($data + ['channel' => MessageChannel::Whatsapp->value]);

        return ApiResponse::created(['template' => new MessageTemplateResource($template)]);
    }

    public function update(Request $request, string $uuid)
    {
        $this->authorize('create', Integration::class);

        $template = MessageTemplate::where('uuid', $uuid)->firstOrFail();
        $template->update($this->validated($request, $template));

        return ApiResponse::success(['template' => new MessageTemplateResource($template->refresh())]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->authorize('create', Integration::class);

        MessageTemplate::where('uuid', $uuid)->firstOrFail()->delete();

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
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
    }
}

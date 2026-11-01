<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Privacy\SuppressionList;
use App\Http\Controllers\Controller;
use App\Models\Suppression;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The company's do-not-contact list: who is on it, why, and adding or removing an entry. */
class SuppressionController extends Controller
{
    public function __construct(private SuppressionList $list, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->guard($request);
        $request->validate(['q' => ['nullable', 'string', 'max:191']]);

        $rows = Suppression::query()
            ->when($request->input('q'), fn ($q, $term) => $q->where('value', 'like', '%'.mb_strtolower($term).'%'))
            ->orderByDesc('id')->paginate(50);

        return ApiResponse::success([
            'data' => collect($rows->items())->map(fn (Suppression $s) => [
                'id' => $s->uuid, 'channel' => $s->channel, 'value' => $s->value, 'reason' => $s->reason, 'note' => $s->note,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->values(),
            'total' => $rows->total(),
        ]);
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $data = $request->validate([
            'channel' => ['required', Rule::in(Suppression::CHANNELS)],
            'value' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $entry = $this->list->add($request->user()->tenant_id, $data['channel'], $data['value'], 'manual', $request->user()->id, $data['note'] ?? null);

        if ($entry === null) {
            return ApiResponse::error('Invalid value.', ['value' => ['Not a valid '.($data['channel'] === 'email' ? 'email address' : 'phone number').'.']], 422);
        }

        $this->audit->log('suppression.added', $request->user(), $entry, ['channel' => $entry->channel]);

        return ApiResponse::created(['id' => $entry->uuid, 'channel' => $entry->channel, 'value' => $entry->value, 'reason' => $entry->reason]);
    }

    /** Off the list: automated messages are allowed again for new leads. Existing opt-outs stay — consent is the person's to give. */
    public function destroy(Request $request, string $uuid)
    {
        $this->guard($request);
        $entry = Suppression::query()->where('uuid', $uuid)->firstOrFail();

        $this->audit->log('suppression.removed', $request->user(), $entry, ['channel' => $entry->channel, 'reason' => $entry->reason]);
        $entry->delete();

        return ApiResponse::noContent();
    }

    private function guard(Request $request): void
    {
        $user = $request->user();

        abort_unless($user->usesMatrix() || $user->isAdmin() || $user->isManager(), 403, 'Your role does not include this.');
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ApiKeys;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A company's API keys: list, issue, revoke. The full key appears once, in the answer to `store`, and never again —
 * not in the list, not in the audit trail.
 */
class ApiKeyController extends Controller
{
    public function __construct(private ApiKeys $keys, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->guard($request);
        $keys = ApiKey::query()->with('creator:id,name')->orderByDesc('id')->get();

        return ApiResponse::success($keys->map(fn (ApiKey $key) => $this->present($key))->values());
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiKey::SCOPES)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $expires = isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null;
        ['key' => $key, 'plain' => $plain] = $this->keys->issue($request->user(), $data['name'], $data['scopes'], $expires);

        $this->audit->log('api_key.created', $request->user(), $key, ['name' => $key->name, 'scopes' => $key->scopes, 'prefix' => $key->prefix]);

        return ApiResponse::created(['key' => $this->present($key->load('creator:id,name')), 'plain' => $plain]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->guard($request);
        $key = ApiKey::query()->where('uuid', $uuid)->firstOrFail();

        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => now()])->save();
            $this->audit->log('api_key.revoked', $request->user(), $key, ['name' => $key->name, 'prefix' => $key->prefix]);
        }

        return ApiResponse::success($this->present($key));
    }

    /**
     * A key is the company's data in someone's hands. A role on the permission matrix has already been checked for
     * `integrations` by EnforcePermissions; a built-in role must be an administrator, as for integrations themselves.
     */
    private function guard(Request $request): void
    {
        $user = $request->user();

        abort_unless($user->usesMatrix() || $user->isAdmin(), 403, 'Your role does not include this.');
    }

    private function present(ApiKey $key): array
    {
        return [
            'id' => $key->uuid,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes,
            'expires_at' => $key->expires_at?->toIso8601String(),
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'created_by' => $key->creator?->name,
            'created_at' => $key->created_at?->toIso8601String(),
            'status' => $key->revoked_at ? 'revoked' : ($key->isUsable() ? 'active' : 'expired'),
        ];
    }
}

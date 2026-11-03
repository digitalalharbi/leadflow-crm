<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\ApiKeys;
use App\Domain\Mail\AccountEmail;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A company's API keys: list, issue, revoke. The full key appears once, in the answer to `store`, and never again —
 * not in the list, not in the audit trail.
 */
class ApiKeyController extends Controller
{
    /** An address or a CIDR range, IPv4 or IPv6; at most twenty. */
    private const IP_RULES = [
        'allowed_ips' => ['sometimes', 'nullable', 'array', 'max:20'],
        'allowed_ips.*' => ['string', 'max:64', 'regex:/^[0-9a-fA-F:.]+(\/\d{1,3})?$/'],
        'rate_limit_per_minute' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:1000'],
    ];

    /** @return array<int,string>|null */
    private static function ips(?array $ips): ?array
    {
        $clean = collect($ips ?? [])->map(fn ($ip) => trim((string) $ip))->filter()->unique()->values();

        foreach ($clean as $i => $ip) {
            [$address, $bits] = array_pad(explode('/', $ip, 2), 2, null);
            $v6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

            if (filter_var($address, FILTER_VALIDATE_IP) === false || ($bits !== null && ((int) $bits < 0 || (int) $bits > ($v6 ? 128 : 32)))) {
                throw ValidationException::withMessages(["allowed_ips.{$i}" => ['Not an IP address or range.']]);
            }
        }

        return $clean->isEmpty() ? null : $clean->all();
    }

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
            ...self::IP_RULES,
        ]);

        $expires = isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null;
        ['key' => $key, 'plain' => $plain] = $this->keys->issue($request->user(), $data['name'], $data['scopes'], $expires);
        $key->forceFill(['allowed_ips' => self::ips($data['allowed_ips'] ?? null), 'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? null])->save();

        $this->audit->log('api_key.created', $request->user(), $key, ['name' => $key->name, 'scopes' => $key->scopes, 'prefix' => $key->prefix]);

        app(AccountEmail::class)->securityNotice($request->user(), 'api_key_created');

        return ApiResponse::created(['key' => $this->present($key->load('creator:id,name')), 'plain' => $plain]);
    }

    /** Which addresses may use the key (an empty list lifts the limit), and how often. */
    public function update(Request $request, string $uuid)
    {
        $this->guard($request);
        $key = ApiKey::query()->where('uuid', $uuid)->whereNull('revoked_at')->firstOrFail();
        $data = $request->validate(['allowed_ips' => ['sometimes', 'nullable', 'array', 'max:20']] + self::IP_RULES);

        $key->forceFill([
            'allowed_ips' => array_key_exists('allowed_ips', $data) ? self::ips($data['allowed_ips']) : $key->allowed_ips,
            'rate_limit_per_minute' => array_key_exists('rate_limit_per_minute', $data) ? $data['rate_limit_per_minute'] : $key->rate_limit_per_minute,
        ])->save();
        Cache::forget('api-key-rate:'.$key->key_hash);
        $this->audit->log('api_key.ips_changed', $request->user(), $key, ['prefix' => $key->prefix, 'allowed_ips' => $key->allowed_ips]);

        return ApiResponse::success($this->present($key->load('creator:id,name')));
    }

    /**
     * A new secret for the same key — name, scopes, expiry and addresses kept — and the old secret revoked in the
     * same step, so a key that may have leaked is replaced without rebuilding the integration's settings.
     */
    public function rotate(Request $request, string $uuid)
    {
        $this->guard($request);
        $old = ApiKey::query()->where('uuid', $uuid)->whereNull('revoked_at')->firstOrFail();

        ['key' => $key, 'plain' => $plain] = $this->keys->issue($request->user(), $old->name, $old->scopes, $old->expires_at);
        $key->forceFill(['allowed_ips' => $old->allowed_ips, 'rate_limit_per_minute' => $old->rate_limit_per_minute])->save();
        $old->forceFill(['revoked_at' => now()])->save();

        $this->audit->log('api_key.rotated', $request->user(), $key, ['name' => $key->name, 'old_prefix' => $old->prefix, 'prefix' => $key->prefix]);

        app(AccountEmail::class)->securityNotice($request->user(), 'api_key_rotated');

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
        // These reach the whole company, past any project limit.
        abort_if($user->isProjectScoped(), 403, 'Your role does not include this.');
    }

    private function present(ApiKey $key): array
    {
        return [
            'id' => $key->uuid,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes,
            'allowed_ips' => $key->allowed_ips ?? [],
            'rate_limit_per_minute' => $key->rate_limit_per_minute ?? ApiKey::DEFAULT_RATE,
            'expires_at' => $key->expires_at?->toIso8601String(),
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'created_by' => $key->creator?->name,
            'created_at' => $key->created_at?->toIso8601String(),
            'status' => $key->revoked_at ? 'revoked' : ($key->isUsable() ? 'active' : 'expired'),
        ];
    }
}

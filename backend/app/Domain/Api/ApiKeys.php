<?php

namespace App\Domain\Api;

use App\Models\ApiKey;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Issues and recognises a company's API keys.
 *
 * A key reads `mht_<prefix>_<secret>`. Only its SHA-256 is stored — a fast hash is right here, unlike a password: the
 * key is 40 random characters, so there is nothing to guess, and every API call has to look it up. The full key is
 * returned exactly once, by `issue()`.
 */
class ApiKeys
{
    public const MARK = 'mht_';

    public function __construct(private TenantContext $context) {}

    /**
     * @param  list<string>  $scopes
     * @return array{key: ApiKey, plain: string}
     */
    public function issue(User $by, string $name, array $scopes, ?\DateTimeInterface $expiresAt = null): array
    {
        $prefix = Str::lower(Str::random(8));
        $plain = self::MARK.$prefix.'_'.Str::random(40);

        $key = ApiKey::create([
            'name' => $name,
            'prefix' => self::MARK.$prefix,
            'key_hash' => self::hash($plain),
            'scopes' => array_values(array_intersect(ApiKey::SCOPES, $scopes)),
            'expires_at' => $expiresAt,
            'created_by' => $by->id,
        ]);

        return ['key' => $key, 'plain' => $plain];
    }

    /** The key this bearer value names, if it is one of ours and still usable; across companies (the key says which). */
    public function resolve(?string $plain): ?ApiKey
    {
        if (! is_string($plain) || ! str_starts_with($plain, self::MARK) || strlen($plain) > 80) {
            return null;
        }

        $key = $this->context->withoutScope(fn () => ApiKey::query()->where('key_hash', self::hash($plain))->first());

        return $key?->isUsable() ? $key : null;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}

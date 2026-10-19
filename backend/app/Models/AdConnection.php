<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * The credentials and evidence for managing campaigns on one ad platform.
 *
 * Credentials are encrypted at rest and never serialised.
 */
class AdConnection extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = ['platform', 'credentials', 'config', 'mode', 'is_active', 'last_test_ok_at', 'last_sync_ok_at', 'last_error'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'config' => 'array',
            'is_active' => 'boolean',
            'last_test_ok_at' => 'datetime',
            'last_sync_ok_at' => 'datetime',
        ];
    }

    public function credential(string $key): mixed
    {
        return data_get($this->credentials, $key);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function isSandbox(): bool
    {
        return $this->mode === 'sandbox';
    }

    /**
     * The workspace's connection for a platform.
     *
     * For Meta, a lead-source integration that already holds a Marketing API
     * token stands in until a dedicated connection is made — so a workspace that
     * connected Meta once does not have to paste the same token twice. That
     * stand-in is never saved.
     */
    public static function for(string $platform): ?self
    {
        $own = static::query()->where('platform', $platform)->where('is_active', true)->first();

        if ($own !== null || $platform !== 'meta') {
            return $own;
        }

        $integration = Integration::query()->where('provider', 'meta')->where('is_active', true)->first();
        $token = $integration ? data_get($integration->credentials, 'access_token') : null;

        if (! $token) {
            return null;
        }

        $standIn = new static(['platform' => 'meta', 'credentials' => ['access_token' => $token], 'config' => [], 'mode' => 'live', 'is_active' => true]);
        $standIn->exists = false;

        return $standIn;
    }

    /** @return list<self> every connection the sync should visit, stand-ins included */
    public static function active(): array
    {
        $rows = static::query()->where('is_active', true)->get()->keyBy('platform');

        if (! $rows->has('meta') && ($meta = static::for('meta'))) {
            $rows->put('meta', $meta);
        }

        return $rows->values()->all();
    }
}

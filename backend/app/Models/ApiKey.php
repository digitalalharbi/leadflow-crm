<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A key a company issued to reach its own data through `/api/v1/public`. The key itself is never stored; see ApiKeys.
 */
class ApiKey extends Model
{
    use BelongsToTenant, HasUuid;

    /** What a key may be allowed to do. Read scopes never include a write. */
    public const SCOPES = [
        'leads:read', 'leads:write', 'projects:read', 'visits:read', 'reservations:read', 'revenue:read',
    ];

    protected $fillable = ['name', 'prefix', 'key_hash', 'scopes', 'expires_at', 'last_used_at', 'last_used_ip', 'revoked_at', 'created_by'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}

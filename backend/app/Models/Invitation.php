<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An invitation to join one company. The token is never stored — only its hash — and it expires; resending issues a
 * new one and the old link stops working. A pending invitation holds a seat.
 */
class Invitation extends Model
{
    use BelongsToTenant, HasUuid;

    public const DAYS = 7;

    protected $fillable = [
        'tenant_id', 'email', 'name', 'role', 'access_role_id', 'project_ids', 'all_projects', 'phone', 'token_hash',
        'invited_by', 'expires_at', 'last_sent_at', 'send_count', 'accepted_at', 'accepted_user_id', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'project_ids' => 'array',
            'all_projects' => 'boolean',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** A fresh token: stored as its hash, returned once to be put in the link. */
    public function issueToken(): string
    {
        $token = Str::random(48);
        $this->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::DAYS),
        ]);

        return $token;
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public static function findByToken(string $token): ?self
    {
        return static::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
    }

    public function url(string $token): string
    {
        return ShortLink::urlFor('invitation', $token);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** A live report behind a link: what it shows, who may open it, until when, and how often it was opened. */
class ReportShare extends Model
{
    use BelongsToTenant, HasUuid;

    protected $fillable = ['name', 'template', 'sections', 'days', 'project_id', 'partner_id', 'expires_at', 'created_by'];

    protected $hidden = ['token_hash', 'token_enc', 'password_hash'];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'days' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A new link: the old one stops working the moment this is saved. */
    public function rotateToken(): string
    {
        $token = Str::random(40);
        $this->forceFill(['token_hash' => self::hash($token), 'token_enc' => Crypt::encryptString($token)]);

        return $token;
    }

    public function token(): string
    {
        return Crypt::decryptString($this->token_enc);
    }

    public function url(): string
    {
        return rtrim((string) config('integrations.app_url'), '/').'/reports/share/'.$this->token();
    }

    public function setPassword(?string $password): void
    {
        $this->password_hash = filled($password) ? Hash::make($password) : null;
    }

    public function hasPassword(): bool
    {
        return $this->password_hash !== null;
    }

    public function passwordMatches(?string $password): bool
    {
        return ! $this->hasPassword() || (filled($password) && Hash::check($password, $this->password_hash));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function status(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            default => 'active',
        };
    }
}

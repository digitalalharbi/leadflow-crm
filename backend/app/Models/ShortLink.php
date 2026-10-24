<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * An address Mohtam hands to somebody outside the workspace: `/r/<token>` for a report, and the same shape for the rest.
 *
 * The token is the credential. It is long enough not to be guessed, says nothing about the workspace, the project or any
 * row, and is stored only as a hash (and encrypted, so the person who made the link can copy it again). Everything a link
 * needs — expiry, a password, a switch, a count of how often it was opened — lives here once, for every kind of link.
 */
class ShortLink extends Model
{
    use BelongsToTenant, HasUuid;

    public const TYPES = ['report', 'form', 'tracking', 'invitation', 'share'];

    protected $fillable = [
        'type', 'resource_id', 'project_id', 'created_by', 'label', 'client_name', 'client_email', 'is_active', 'expires_at',
        'require_otp', 'allowed_emails', 'allowed_domains', 'allow_export',
    ];

    protected $hidden = ['token_hash', 'token_enc', 'password_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
            'require_otp' => 'boolean',
            'allowed_emails' => 'array',
            'allowed_domains' => 'array',
            'allow_export' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::random((int) config('links.token_length'));
    }

    /** Gives it a fresh token and returns it; the old one stops working at once. */
    public function rotateToken(): string
    {
        $token = self::newToken();
        $this->forceFill(['token_hash' => self::hash($token), 'token_enc' => Crypt::encryptString($token)]);

        return $token;
    }

    public function token(): ?string
    {
        return $this->token_enc === null ? null : Crypt::decryptString($this->token_enc);
    }

    /** The address to hand out, on the short domain once that is switched on, otherwise on the public one. */
    public function url(): string
    {
        return self::urlFor($this->type, (string) $this->token());
    }

    public static function urlFor(string $type, string $token): string
    {
        $short = (bool) config('links.short_enabled');
        $base = $short ? config('links.short_base') : (config('links.public_base') ?: config('integrations.app_url'));

        return rtrim((string) $base, '/').'/'.config("links.prefixes.{$type}").'/'.$token;
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

    /** active · inactive · expired · revoked — what the owner sees, and what decides whether the link opens. */
    public function status(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            ! $this->is_active => 'inactive',
            default => 'active',
        };
    }

    public function opens(): bool
    {
        return $this->status() === 'active';
    }

    /** Whether an email may open a restricted link: listed itself, or from a listed domain. No list = anybody. */
    public function allowsEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        $emails = array_map('strtolower', (array) $this->allowed_emails);
        $domains = array_map('strtolower', (array) $this->allowed_domains);

        if ($emails === [] && $domains === []) {
            return true;
        }

        return in_array($email, $emails, true) || in_array(Str::after($email, '@'), $domains, true);
    }

    public function isRestrictedToPeople(): bool
    {
        return $this->require_otp || ! empty($this->allowed_emails) || ! empty($this->allowed_domains);
    }
}

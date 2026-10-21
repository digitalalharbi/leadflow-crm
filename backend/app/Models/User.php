<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use RuntimeException;

/**
 * Users are deliberately NOT scoped by BelongsToTenant: the login lookup has to
 * run before a tenant context exists. Tenant filtering for user listings is done
 * explicitly in the controller/policy layer.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuid, Notifiable, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'password', 'role',
        'phone', 'avatar_url', 'is_active', 'locale', 'away_until',
        'covers_cities', 'assignment_weight', 'speaks', 'handles', 'notification_prefs', 'partner_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'away_until' => 'datetime',
            'covers_cities' => 'array',
            'speaks' => 'array',
            'assignment_weight' => 'integer',
            'handles' => 'array',
            'notification_prefs' => 'array',
            'role' => Role::class,
        ];
    }

    /** The projects this rep works, if the workspace has said. */
    /** The companies this person works in. None means all, which is how every workspace starts. */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class)->withTrashed();
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    /**
     * Whether this rep sells in that city.
     *
     * An empty list means every city, which is what every rep starts as. A lead
     * with no city at all is nobody's to refuse — the field is optional on most
     * ad platform forms, and a lead with a blank city must not become a lead
     * nobody covers.
     *
     * Matched loosely because the value is whatever the customer typed into a
     * form: "الرياض" and " الرياض " are the same place, and so are "Riyadh" and
     * "riyadh". Anything beyond that — spelling variants, transliteration — is
     * a normalising problem this is deliberately not trying to solve, because
     * guessing wrong routes a lead to the wrong person silently.
     */
    public function coversCity(?string $city): bool
    {
        $covers = $this->covers_cities ?? [];

        if ($covers === [] || blank($city)) {
            return true;
        }

        $wanted = $this->normaliseCity($city);

        foreach ($covers as $covered) {
            if ($this->normaliseCity((string) $covered) === $wanted) {
                return true;
            }
        }

        return false;
    }

    private function normaliseCity(string $city): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $city) ?? $city));
    }

    /**
     * Whether this rep can sell in that language.
     *
     * An empty list means every language, which is what every rep starts as —
     * so this narrows nothing until somebody says what their team speaks. A
     * lead whose number says nothing is nobody's exception either: with no
     * language to match, everyone matches.
     */
    public function speaksLanguage(?string $language): bool
    {
        $spoken = $this->speaks ?? [];

        if ($spoken === [] || blank($language)) {
            return true;
        }

        foreach ($spoken as $claim) {
            if ($this->normaliseLanguage((string) $claim) === $this->normaliseLanguage($language)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `ar-SA`, `AR` and ` ar ` are the same claim. The region is dropped
     * deliberately: somebody who speaks Egyptian Arabic can talk to a customer
     * in Jeddah, and a routing rule that disagreed would be wrong about people.
     */
    private function normaliseLanguage(string $language): string
    {
        return mb_strtolower(trim(explode('-', str_replace('_', '-', $language))[0]));
    }

    /**
     * Whether this rep takes that kind of deal (`buy`, `rent`). An empty list means every kind,
     * and a lead that has not said what it wants is nobody's exception.
     */
    public function handlesType(?string $type): bool
    {
        $handles = $this->handles ?? [];

        return $handles === [] || blank($type) || in_array(mb_strtolower(trim($type)), array_map(fn ($h) => mb_strtolower(trim((string) $h)), $handles), true);
    }

    /** How many leads this rep carries for every one an ordinary rep takes. */
    public function assignmentWeight(): int
    {
        return max(1, (int) ($this->assignment_weight ?? 1));
    }

    /**
     * Can be given a lead right now.
     *
     * `is_active` is an account state — set when somebody leaves the company.
     * Being away until Sunday is a different thing, and assignment had no way
     * to say it.
     */
    public function isAvailable(): bool
    {
        return $this->is_active && ($this->away_until === null || $this->away_until->isPast());
    }

    /** @param  Builder<self>  $query */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('away_until')->orWhere('away_until', '<=', now()));
    }

    public static function booted(): void
    {
        // A tenant-less user is, by definition, the platform super admin.
        // Anything else without a tenant would be an orphan account that can
        // neither sign in nor be governed by a policy, so refuse to create it.
        // Match the guard BelongsToTenant gives every other tenant-keyed model.
        static::updating(function (self $user) {
            if ($user->isDirty('tenant_id')) {
                throw new RuntimeException('tenant_id is immutable.');
            }
        });

        static::saving(function (self $user) {
            if ($user->tenant_id === null && $user->role !== Role::SuperAdmin) {
                throw new RuntimeException(
                    'Only a super admin may exist without a tenant; got role '
                    .($user->role?->value ?? 'null').'.'
                );
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'assigned_to');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isManager(): bool
    {
        return $this->role === Role::Manager;
    }

    public function isSales(): bool
    {
        return $this->role === Role::Sales;
    }

    public function seesAllLeads(): bool
    {
        return $this->role->seesAllLeads();
    }

    /** Super admins belong to no tenant; everyone else must. */
    public function belongsToTenant(?int $tenantId): bool
    {
        return $tenantId !== null && $this->tenant_id === $tenantId;
    }
}

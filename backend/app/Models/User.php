<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'phone', 'avatar_url', 'is_active', 'locale',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'role' => Role::class,
        ];
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

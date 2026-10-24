<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * Records security-relevant actions. Deliberately narrow: logins, user and
 * permission changes, integration changes, tenant settings, deletions.
 * Routine CRUD lives in lead_activities instead.
 */
class AuditLogger
{
    public function log(string $action, ?User $actor = null, ?Model $subject = null, array $changes = []): void
    {
        AuditLog::create([
            'tenant_id' => $actor?->tenant_id,
            'user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'changes' => $this->redact($changes) ?: null,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512),
        ]);
    }

    /** Secrets must never land in the audit trail. */
    /**
     * A row in one company's own trail written by something outside it — the platform, a support session — so the
     * company sees that it happened. `$actor` is the company's person it happened as, if any.
     */
    public function logForTenant(int $tenantId, string $action, ?User $actor = null, ?Model $subject = null, array $changes = []): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'changes' => $this->redact($changes) ?: null,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512),
        ]);
    }

    private function redact(array $changes): array
    {
        $sensitive = ['password', 'secret', 'token', 'credentials', 'access_token', 'client_secret', 'api_key'];

        foreach ($changes as $key => $value) {
            foreach ($sensitive as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    $changes[$key] = '[redacted]';

                    continue 2;
                }
            }

            if (is_array($value)) {
                $changes[$key] = $this->redact($value);
            }
        }

        return $changes;
    }
}

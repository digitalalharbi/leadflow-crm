<?php

namespace App\Domain\Platform;

use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * The platform owner's trail. Every console action that changes a company — its plan, its status, its seats, an
 * invoice, entering it as support — writes one row here. Secrets never reach it: the same redaction as a company's own
 * trail applies.
 */
class PlatformAudit
{
    private const SENSITIVE = ['password', 'secret', 'token', 'credentials', 'access_token', 'client_secret', 'api_key', 'mfa'];

    public function log(string $action, ?User $actor, ?Tenant $tenant = null, ?Model $subject = null, array $changes = []): PlatformAuditLog
    {
        return PlatformAuditLog::create([
            'actor_id' => $actor?->id,
            'tenant_id' => $tenant?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $this->redact($changes) ?: null,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512),
        ]);
    }

    private function redact(array $changes): array
    {
        foreach ($changes as $key => $value) {
            foreach (self::SENSITIVE as $needle) {
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

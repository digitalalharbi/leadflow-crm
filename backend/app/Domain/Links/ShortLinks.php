<?php

namespace App\Domain\Links;

use App\Models\ReportShareView;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * The one place short links are made, found, switched off and counted. A report uses it today; a form, an invitation or
 * a shared file uses the same calls.
 */
class ShortLinks
{
    public function __construct(private TenantContext $context, private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  label, client_name, client_email, expires_at, require_otp, allowed_emails …
     * @return array{0: ShortLink, 1: string} the link and its token, the only time it is handed back in clear
     */
    public function issue(string $type, ?int $resourceId, ?int $projectId, ?User $by, array $attributes = [], ?string $password = null): array
    {
        $link = new ShortLink($attributes + ['type' => $type, 'resource_id' => $resourceId, 'project_id' => $projectId, 'created_by' => $by?->id]);
        $link->setPassword($password);
        $token = $link->rotateToken();
        $link->save();

        return [$link, $token];
    }

    /**
     * Who holds this token — found without a workspace, because the visitor has none; the link carries it.
     */
    public function find(string $token, ?string $type = null): ?ShortLink
    {
        return $this->context->withoutScope(fn () => ShortLink::withoutGlobalScopes()
            ->where('token_hash', ShortLink::hash($token))
            ->when($type, fn ($q, $t) => $q->where('type', $t))
            ->first());
    }

    public function revoke(ShortLink $link, ?User $by): void
    {
        $link->forceFill(['revoked_at' => now()])->save();
        $this->audit->log('short_link.revoked', $by, $link, ['type' => $link->type]);
    }

    public function regenerate(ShortLink $link, ?User $by): string
    {
        $token = $link->rotateToken();
        $link->revoked_at = null;
        $link->save();
        $this->audit->log('short_link.regenerated', $by, $link, ['type' => $link->type]);

        return $token;
    }

    /** A real opening: counted once, and — for a report — remembered with the device it came from. */
    public function recordView(ShortLink $link, Request $request): void
    {
        ShortLink::withoutGlobalScopes()->whereKey($link->id)->update(['view_count' => $link->view_count + 1, 'last_viewed_at' => now()]);

        if ($link->type !== 'report') {
            return;
        }

        $agent = strtolower((string) $request->userAgent());

        ReportShareView::withoutGlobalScopes()->create([
            'tenant_id' => $link->tenant_id,
            'report_share_id' => $link->resource_id,
            'short_link_id' => $link->id,
            'viewer_hash' => hash('sha256', $request->ip().'|'.$agent.'|'.$link->id),
            'device' => match (true) {
                str_contains($agent, 'ipad') || str_contains($agent, 'tablet') => 'tablet',
                str_contains($agent, 'mobile') || str_contains($agent, 'android') || str_contains($agent, 'iphone') => 'mobile',
                $agent !== '' => 'desktop',
                default => 'other',
            },
            'viewed_at' => now(),
        ]);
    }
}

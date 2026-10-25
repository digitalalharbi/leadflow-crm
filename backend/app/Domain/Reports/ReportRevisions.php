<?php

namespace App\Domain\Reports;

use App\Models\ReportRevision;
use App\Models\ReportShare;
use App\Models\User;

/**
 * The history of how a report is set up — which sections, which projects, which attribution model, who may see what,
 * the branding, the notes, the sharing — and nothing of the numbers: a live report shows today's data for its period,
 * so there is no snapshot of analytics to keep. One row per significant change, with the keys that changed.
 */
class ReportRevisions
{
    /** What counts as significant. */
    public const TRACKED = ['name', 'client_name', 'template', 'sections', 'layout', 'project_ids', 'attribution_model', 'currency', 'period', 'period_from', 'period_to', 'compare', 'compare_mode', 'compare_from', 'compare_to', 'filters', 'branding', 'options', 'partner_id', 'state', 'notes', 'share_settings'];

    /** @return array<string,mixed> */
    public static function configuration(ReportShare $share): array
    {
        $out = [];
        foreach (self::TRACKED as $key) {
            $out[$key] = match ($key) {
                'project_ids' => $share->projectIds(),
                'period_from', 'period_to', 'compare_from', 'compare_to' => $share->{$key}?->toDateString(),
                'notes' => $share->notes()->get(['kind', 'body'])->map(fn ($n) => ['kind' => $n->kind, 'body' => $n->body])->all(),
                // How each link is protected — never the password or the token, only that there is one.
                'share_settings' => $share->links()->get()->map(fn ($l) => [
                    'link' => $l->uuid, 'client' => $l->client_name, 'active' => (bool) $l->is_active, 'revoked' => $l->revoked_at !== null,
                    'expires_at' => $l->expires_at?->toDateString(), 'password' => $l->hasPassword(), 'otp' => (bool) $l->require_otp,
                    'emails' => count((array) $l->allowed_emails), 'domains' => count((array) $l->allowed_domains), 'export' => (bool) $l->allow_export,
                ])->all(),
                default => $share->{$key},
            };
        }

        return $out;
    }

    /**
     * Writes a revision when something tracked changed from `$before`. Returns the new revision number, or null if nothing did.
     *
     * @param  array<string,mixed>  $before  the configuration before the change
     */
    public static function capture(ReportShare $share, ?User $by, array $before, ?array $only = null): ?int
    {
        $after = self::configuration($share);
        $changed = array_values(array_filter($only ?? self::TRACKED, fn ($k) => ($before[$k] ?? null) != ($after[$k] ?? null)));

        if ($changed === []) {
            return null;
        }

        $revision = (int) $share->revision + 1;
        $share->forceFill(['revision' => $revision])->saveQuietly();

        ReportRevision::withoutGlobalScopes()->create([
            'tenant_id' => $share->tenant_id,
            'report_share_id' => $share->id,
            'revision' => $revision,
            'configuration' => $after,
            'changed' => $changed,
            'changed_by' => $by?->id,
        ]);

        return $revision;
    }
}

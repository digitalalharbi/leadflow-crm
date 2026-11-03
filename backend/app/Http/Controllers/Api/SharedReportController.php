<?php

namespace App\Http\Controllers\Api;

use App\Domain\Reports\LiveReport;
use App\Http\Controllers\Controller;
use App\Models\ReportShare;
use App\Models\ReportShareView;
use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `/api/v1/shared/reports/{token}` — the live report behind a link.
 *
 * Public: the token is the credential, and an optional password is a second one. Unknown and
 * revoked links look the same (404); an expired one says so (410) because "this ended" is
 * something a reader can act on, and a missing one is not. Failed passwords count against a
 * tight throttle, and a response never says whether a link exists to anyone without it.
 */
class SharedReportController extends Controller
{
    public function __construct(private TenantContext $context, private LiveReport $report) {}

    public function __invoke(Request $request, string $token)
    {
        $share = $this->context->withoutScope(fn () => ReportShare::withoutGlobalScopes()->where('token_hash', ReportShare::hash($token))->first());

        abort_if($share === null || $share->isRevoked() || $share->archived_at !== null, 404);

        // Paused says the same as expired to whoever holds the link: not now, and nothing about why.
        if ($share->isExpired() || $share->paused_at !== null) {
            return ApiResponse::error('This report has expired.', null, 410);
        }

        $tenant = $this->context->withoutScope(fn () => Tenant::with('branding', 'settings')->find($share->tenant_id));
        $brand = ['name' => $tenant?->branding?->company_name ?? $tenant?->name, 'color' => $tenant?->branding?->primary_color ?? '#2563eb', 'logo_url' => $tenant?->branding?->logo_url];

        // Holding the link does not buy unlimited guesses at its password: five wrong tries a quarter hour, per link.
        $guesses = 'share-password:'.$share->id;

        if ($share->hasPassword() && $request->hasHeader('X-Share-Password') && RateLimiter::tooManyAttempts($guesses, 5)) {
            return response()->json(['success' => false, 'message' => 'Too many tries. Wait a while and try again.', 'data' => null], 429);
        }

        if (! $share->passwordMatches($request->header('X-Share-Password'))) {
            if ($request->hasHeader('X-Share-Password')) {
                RateLimiter::hit($guesses, 900);
            }

            // Enough to draw the password screen, nothing about the numbers.
            return response()->json(['success' => false, 'message' => 'A password is required.', 'data' => ['password_required' => true, 'workspace' => $brand, 'name' => $share->name]], 401);
        }

        $data = $share->isSnapshot() ? $share->frozen_payload + ['snapshot' => $share->frozen_meta] : $this->context->withTenant($tenant, fn () => $this->report->build($share->setRelation('tenant', $tenant)));

        // An open page asking again for fresh numbers is the same view, not another one.
        if (! $request->hasHeader('X-Share-Refresh')) {
            $this->countView($request, $share);
        }

        return ApiResponse::success($data + ['workspace' => $brand]);
    }

    /**
     * One more opening: counted without touching `updated_at` (opening a report is not editing it),
     * and written down with a hash that tells visitors apart without naming anybody.
     */
    private function countView(Request $request, ReportShare $share): void
    {
        ReportShare::withoutGlobalScopes()->whereKey($share->id)->update(['view_count' => $share->view_count + 1, 'last_viewed_at' => now()]);

        $agent = strtolower((string) $request->userAgent());
        ReportShareView::withoutGlobalScopes()->create([
            'tenant_id' => $share->tenant_id,
            'report_share_id' => $share->id,
            'viewer_hash' => hash('sha256', $request->ip().'|'.$agent.'|'.$share->id),
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

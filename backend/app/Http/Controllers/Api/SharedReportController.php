<?php

namespace App\Http\Controllers\Api;

use App\Domain\Reports\LiveReport;
use App\Http\Controllers\Controller;
use App\Models\ReportShare;
use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

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

        abort_if($share === null || $share->isRevoked(), 404);

        if ($share->isExpired()) {
            return ApiResponse::error('This report has expired.', null, 410);
        }

        $tenant = $this->context->withoutScope(fn () => Tenant::with('branding', 'settings')->find($share->tenant_id));
        $brand = ['name' => $tenant?->branding?->company_name ?? $tenant?->name, 'color' => $tenant?->branding?->primary_color ?? '#2563eb', 'logo_url' => $tenant?->branding?->logo_url];

        if (! $share->passwordMatches($request->header('X-Share-Password'))) {
            // Enough to draw the password screen, nothing about the numbers.
            return response()->json(['success' => false, 'message' => 'A password is required.', 'data' => ['password_required' => true, 'workspace' => $brand, 'name' => $share->name]], 401);
        }

        $data = $this->context->withTenant($tenant, fn () => $this->report->build($share->setRelation('tenant', $tenant)));

        // Counted without touching `updated_at`: opening a report is not editing it.
        ReportShare::withoutGlobalScopes()->whereKey($share->id)->update(['view_count' => $share->view_count + 1, 'last_viewed_at' => now()]);

        return ApiResponse::success($data + ['workspace' => $brand]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\Entitlements;
use App\Domain\Links\AccessPass;
use App\Domain\Links\ShortLinks;
use App\Domain\Mail\AccountEmail;
use App\Domain\Reports\LiveReport;
use App\Domain\Reports\ReportActivities;
use App\Domain\Reports\ReportBranding;
use App\Domain\Reports\ReportCache;
use App\Domain\Reports\ReportExporter;
use App\Domain\Reports\ReportTemplates;
use App\Http\Controllers\Controller;
use App\Models\ReportShare;
use App\Models\ShortLink;
use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * `/r/<token>` — the live report a client opens with one link. The token is the credential; a password and an emailed
 * code may be the others. It answers with exactly the sections the owner turned on and no more (the rest are never
 * built), carries no contact detail of any lead, and says the same "not found" for a link that never existed, was
 * revoked, is switched off, or belongs to a report that is not live — so it cannot be used to find tokens.
 */
class SharedReportController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private LiveReport $report,
        private ShortLinks $links,
        private AccessPass $pass,
        private ReportCache $cache,
    ) {}

    public function __invoke(Request $request, string $token)
    {
        [$link, $share, $tenant] = $this->resolve($token);
        $brand = ReportBranding::workspace($share, $tenant);

        if ($denied = $this->gate($request, $link, $share, $brand)) {
            return $denied;
        }

        $part = in_array($request->query('part'), ['primary', 'secondary'], true) ? $request->query('part') : null;

        // Everything that reads the workspace's rows — the partner a report is about included — is read inside the workspace.
        $data = $this->context->withTenant($tenant, function () use ($share, $tenant, $part) {
            $share->setRelation('tenant', $tenant)->load('partner');
            $sections = array_column(ReportTemplates::plan($share, 'public'), 'key');
            $only = match ($part) {
                'primary' => array_values(array_intersect($sections, ReportTemplates::PRIMARY)),
                'secondary' => array_values(array_diff($sections, ReportTemplates::PRIMARY)),
                default => null,
            };

            return $this->cache->remember($share, 'public', $only ?? $sections, $this->cacheSeconds($share), fn () => $this->report->build($share, 'public', null, $only))
                + ['part' => $part ?? 'all'];
        });

        // An open page asking again for fresh numbers — or for the second half of what it has already opened — is the same view, not another one.
        if (! $request->hasHeader('X-Share-Refresh') && $part !== 'secondary') {
            $this->context->withTenant($tenant, function () use ($request, $link, $share) {
                $this->links->recordView($link, $request);
                ReportActivities::viewed($share, $request);
            });
        }

        return ApiResponse::success($data + ['workspace' => $brand, 'link' => ['allow_export' => (bool) $link->allow_export, 'exports' => $this->exportsOffered($tenant, $link)]]);
    }

    /** The visitor asks for a code at their email. The answer is the same whether or not the email is allowed. */
    public function requestCode(Request $request, string $token)
    {
        [$link, $share, $tenant] = $this->resolve($token);
        $email = strtolower((string) $request->validate(['email' => ['required', 'email:rfc', 'max:190']])['email']);

        $code = $this->pass->request($link, $email);

        if ($code !== null) {
            $this->context->withTenant($tenant, fn () => app(AccountEmail::class)->reportCode($email, $code, $share->name, $tenant));
        }

        return ApiResponse::success(['sent' => true]);
    }

    public function verifyCode(Request $request, string $token)
    {
        [$link] = $this->resolve($token);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190'], 'code' => ['required', 'string', 'max:12']]);

        $pass = $this->pass->verify($link, $data['email'], $data['code']);

        if ($pass === null) {
            throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
        }

        return ApiResponse::success(['access' => $pass]);
    }

    /** The report as a file, for a link whose owner allowed it. Same gates as the page; same sections; audited. */
    public function export(Request $request, string $token)
    {
        [$link, $share, $tenant] = $this->resolve($token);
        $brand = ReportBranding::workspace($share, $tenant);

        if ($denied = $this->gate($request, $link, $share, $brand)) {
            return $denied;
        }

        abort_unless($link->allow_export, 403, 'This link does not allow downloads.');
        $format = $request->validate(['format' => ['required', 'in:csv,xlsx,pdf']])['format'];
        abort_unless($this->exportsOffered($tenant, $link)[$format] ?? false, 403, 'Your plan does not include this format.');

        return $this->context->withTenant($tenant, function () use ($request, $share, $tenant, $format, $link) {
            $data = $this->report->build($share->setRelation('tenant', $tenant));
            ReportActivities::exported($share, null, $format, 'client_link', ['link' => $link->uuid]);

            return app(ReportExporter::class)->download($share, $data, $format, $request);
        });
    }

    /**
     * @return array{0: ShortLink, 1: ReportShare, 2: Tenant}
     */
    private function resolve(string $token): array
    {
        $link = $this->links->find($token, 'report');
        abort_if($link === null || $link->isRevoked() || ! $link->is_active, 404);

        $share = $this->context->withoutScope(fn () => ReportShare::withoutGlobalScopes()->find($link->resource_id));
        abort_if($share === null || $share->tenant_id !== $link->tenant_id || ($share->state ?? 'live') !== 'live', 404);

        $tenant = $this->context->withoutScope(fn () => Tenant::with('branding', 'settings')->find($share->tenant_id));
        // A closed subscription, or a plan without reports, closes the links too — the data stays, the doors do not open.
        abort_if($tenant === null || ! $tenant->isSubscriptionUsable() || ! Entitlements::for($tenant)->has('advanced_reports'), 404);

        return [$link, $share, $tenant];
    }

    /** The password and the emailed code. Null means the visitor may go on. */
    private function gate(Request $request, ShortLink $link, ReportShare $share, array $brand): ?JsonResponse
    {
        if ($link->isExpired()) {
            return ApiResponse::error('This report has expired.', null, 410);
        }

        $attempts = 'report-password:'.$link->id.':'.$request->ip();
        $max = (int) config('links.password_attempts_per_minute', 10);

        if (filled($request->header('X-Share-Password')) && RateLimiter::tooManyAttempts($attempts, $max)) {
            return ApiResponse::error('Too many attempts.', null, 429);
        }

        if (! $link->passwordMatches($request->header('X-Share-Password'))) {
            // A wrong guess costs one of the few a minute that an address gets; asking for the password screen costs nothing.
            if (filled($request->header('X-Share-Password'))) {
                RateLimiter::hit($attempts, 60);
            }

            // Enough to draw the password screen, nothing about the numbers.
            return response()->json(['success' => false, 'message' => 'A password is required.', 'data' => ['password_required' => true, 'workspace' => $brand, 'name' => $share->name]], 401);
        }

        if ($link->isRestrictedToPeople() && ! $this->pass->valid($link, $request->header('X-Share-Access'))) {
            return response()->json(['success' => false, 'message' => 'A verification code is required.', 'data' => ['otp_required' => true, 'workspace' => $brand, 'name' => $share->name]], 401);
        }

        return null;
    }

    /** @return array{csv: bool, xlsx: bool, pdf: bool} */
    private function exportsOffered(Tenant $tenant, ShortLink $link): array
    {
        $entitlements = Entitlements::for($tenant);

        return [
            'csv' => (bool) $link->allow_export,
            'xlsx' => (bool) $link->allow_export && $entitlements->has('report_excel'),
            'pdf' => (bool) $link->allow_export && $entitlements->has('report_pdf'),
        ];
    }

    private function cacheSeconds(ReportShare $share): int
    {
        return min((int) config('links.report_cache_seconds', 0), max(0, (int) ($share->refresh_seconds ?: 60)));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Readiness\Commissioning;
use App\Domain\Readiness\ProductionReadiness;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * One screen for "is this deployment live, and if not, what is it waiting for".
 *
 * Two audiences, one model. A workspace admin sees all six rows for their own
 * workspace. The platform owner has no workspace, so sees the three rows that
 * are about the platform and a note that the other three are each customer's own.
 */
class ReadinessController extends Controller
{
    public function __construct(
        private readonly ProductionReadiness $readiness,
        private readonly Commissioning $commissioning,
        private readonly AuditLogger $audit,
    ) {}

    public function workspace(Request $request)
    {
        $this->authorize('create', Integration::class);

        return ApiResponse::success($this->readiness->report($request, true));
    }

    public function platform(Request $request)
    {
        return ApiResponse::success($this->readiness->report($request, false));
    }

    /**
     * Uses the credentials once, against the real provider.
     *
     * A POST because it has an effect — it spends a call against somebody's
     * quota and, for the ad platforms, ingests whatever leads are waiting — and
     * a GET that does that is one a link prefetcher can trigger.
     */
    public function commission(Request $request, string $key)
    {
        $this->authorize('create', Integration::class);

        if (! in_array($key, Commissioning::KEYS, true)) {
            return ApiResponse::error('Unknown integration.', null, 404);
        }

        $result = $this->commissioning->run($key, $request->user()->tenant, $request->user());

        $this->audit->log('integration.commissioned', $request->user(), null, [
            'key' => $key,
            'ok' => $result['ok'],
        ]);

        return ApiResponse::success([
            'result' => $result,
            'readiness' => $this->readiness->report($request, true),
        ]);
    }
}

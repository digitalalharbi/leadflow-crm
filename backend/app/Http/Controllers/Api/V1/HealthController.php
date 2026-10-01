<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Deployment health gate. Reports component status only — no versions, no
 * configuration, nothing that helps an attacker fingerprint the stack.
 */
class HealthController extends Controller
{
    public function __invoke()
    {
        $checks = [
            'application' => 'ok',
            'database' => $this->check(fn () => DB::connection()->getPdo() !== null),
            'redis' => $this->check(fn () => Redis::connection()->ping() !== false),
        ];

        $healthy = ! in_array('error', $checks, true);

        return response()->json([
            'success' => $healthy,
            'data' => ['status' => $healthy ? 'healthy' : 'degraded', 'checks' => $checks],
            'message' => null,
            'errors' => null,
        ], $healthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            return $probe() ? 'ok' : 'error';
        } catch (\Throwable) {
            return 'error';
        }
    }
}

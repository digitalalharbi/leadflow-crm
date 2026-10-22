<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Deployment health gate. Reports component status only — no versions, no
 * configuration, nothing that helps an attacker fingerprint the stack.
 *
 * The exception is `release`, which a deploy needs in order to prove that the
 * code answering this request is the code it just shipped: a container that
 * failed to restart answers every other check perfectly while serving the
 * previous build. It is returned only to a caller holding the deploy secret.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request)
    {
        $checks = [
            'application' => 'ok',
            'database' => $this->check(fn () => DB::connection()->getPdo() !== null),
            'redis' => $this->check(fn () => Redis::connection()->ping() !== false),
            // A deploy that leaves the workers on the old image looks healthy
            // from every angle except this one. Resolved from config rather
            // than named outright: there is no connection called "queue", and
            // hard-coding one would report every deploy as broken.
            'queue' => $this->check(fn () => Redis::connection(
                config('queue.connections.'.config('queue.default').'.connection', 'default')
            )->ping() !== false),
        ];

        $healthy = ! in_array('error', $checks, true);

        $data = ['status' => $healthy ? 'healthy' : 'degraded', 'checks' => $checks];

        if ($this->authorisedForRelease($request)) {
            $data['release'] = $this->release();
        }

        return response()->json([
            'success' => $healthy,
            'data' => $data,
            'message' => null,
            'errors' => null,
        ], $healthy ? 200 : 503);
    }

    /**
     * Constant-time comparison against the deploy secret. An absent or empty
     * secret authorises nobody — the field simply never appears.
     */
    private function authorisedForRelease(Request $request): bool
    {
        $expected = (string) config('app.deploy_secret');
        $given = (string) $request->header('X-Deploy-Secret', '');

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    /** The commit the deploy wrote, or null before the first deploy. */
    private function release(): ?string
    {
        $path = base_path('.release');

        if (! is_readable($path)) {
            return null;
        }

        $sha = trim((string) file_get_contents($path));

        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
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

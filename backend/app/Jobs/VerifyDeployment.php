<?php

namespace App\Jobs;

use App\Domain\Git\Verification\PublicUrl;
use App\Models\RepositoryDeployment;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asking the application whether it is actually there.
 *
 * The distinction this job exists for: GitHub's `success` means the job that
 * deploys finished without error. It does not mean DNS resolves, the
 * certificate is valid, the proxy is passing requests through, or the
 * containers came up. The last deployment pipeline reported green while
 * nothing had shipped, and every screen agreed with it.
 *
 * So one request, from outside, the way a customer arrives. Anything less is
 * the deployer's own word for it.
 */
class VerifyDeployment implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Long enough for a container that is still starting, short enough to matter. */
    public array $backoff = [30, 120];

    /**
     * How a hostname becomes addresses.
     *
     * Only ever replaced by a test, which needs the address rules exercised
     * without depending on what DNS says about example.com today. Production
     * leaves it null and the guard resolves for real.
     *
     * @var null|callable(string):array<int,string>
     */
    public static $resolver = null;

    public function __construct(private readonly int $deploymentId) {}

    public function handle(TenantContext $context): void
    {
        $deployment = RepositoryDeployment::withoutGlobalScopes()->find($this->deploymentId);

        if (! $deployment || ! $deployment->reportedLive()) {
            return;
        }

        $guard = PublicUrl::check($deployment->environment_url, self::$resolver);

        if (! $guard['ok']) {
            /*
             * Not a failure of the deployment — a failure to check it. A
             * repository that publishes no environment URL has nothing to ask,
             * and saying "failed" would make a working deploy look broken.
             */
            $deployment->forceFill([
                'verification_status' => 'unverifiable',
            ])->save();

            return;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mahtam-Deployment-Check'])
                // Redirects are not followed: a 302 is the standard way to walk
                // a checked URL into an unchecked one.
                ->withoutRedirecting()
                ->get((string) $deployment->environment_url);
        } catch (\Throwable $e) {
            Log::info('github.deployment_unreachable', [
                'deployment' => $deployment->id,
                'exception' => $e::class,
            ]);

            $deployment->forceFill(['verification_status' => 'failed', 'verified_at' => null])->save();

            return;
        }

        /*
         * Any answer at all from the public address is the thing being checked.
         * A 401 from an application behind a login is a running application;
         * insisting on 200 would mark every protected environment as broken.
         * A 5xx is not, and neither is a redirect to somewhere we did not look.
         */
        $alive = $response->status() > 0
            && $response->status() < 500
            && ! $response->redirect();

        $deployment->forceFill($alive
            ? ['verification_status' => 'verified', 'verified_at' => now()]
            : ['verification_status' => 'failed', 'verified_at' => null])->save();
    }
}

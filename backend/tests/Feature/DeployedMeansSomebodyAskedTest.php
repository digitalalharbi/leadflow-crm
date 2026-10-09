<?php

namespace Tests\Feature;

use App\Domain\Git\Verification\PublicUrl;
use App\Jobs\VerifyDeployment;
use App\Models\Integration;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\RepositoryDeployment;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Deployed" means somebody asked the application, and it answered.
 *
 * GitHub's `success` means the job that deploys finished without error. It
 * does not mean DNS resolves, the certificate is valid, the proxy passes
 * requests through, or the containers came up — and the last pipeline reported
 * green while nothing had shipped, with every screen agreeing.
 *
 * The other half of this file is the guard. The URL being checked comes from
 * whoever can create a deployment in the customer's repository, and a server
 * that fetches any URL it is handed will eventually be asked to fetch the
 * cloud metadata endpoint.
 */
class DeployedMeansSomebodyAskedTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private ProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The address rules are what is being tested, not what DNS says about
         * example.com this morning. A test that resolves for real is a test
         * that fails on a train.
         */
        VerifyDeployment::$resolver = fn (string $host) => ['93.184.216.34'];

        $this->t = $this->provisionTenant('Deploy Co');

        $connection = $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'github', 'name' => 'GitHub', 'status' => Integration::CONNECTED,
            'credentials' => ['access_token' => 't'], 'is_active' => true,
        ]));
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'بوابة']));

        $this->repository = $this->asTenant($this->t['tenant'], fn () => ProjectRepository::create([
            'project_id' => $project->id, 'integration_id' => $connection->id, 'provider' => 'github',
            'repository_id' => 1, 'repository_full_name' => 'acme/portal', 'default_branch' => 'main',
            'production_branch' => 'main', 'webhook_key' => (string) Str::uuid(),
            'webhook_secret' => 'secret', 'webhook_status' => 'ok',
        ]));
    }

    private function deployment(array $attributes = []): RepositoryDeployment
    {
        return $this->asTenant($this->t['tenant'], fn () => RepositoryDeployment::create(array_merge([
            'project_repository_id' => $this->repository->id,
            'deployment_id' => random_int(1, 1_000_000),
            'environment' => 'production',
            'state' => 'success',
            'environment_url' => 'https://app.example.com',
        ], $attributes)));
    }

    protected function tearDown(): void
    {
        VerifyDeployment::$resolver = null;
        parent::tearDown();
    }

    private function verify(RepositoryDeployment $deployment): RepositoryDeployment
    {
        app(VerifyDeployment::class, ['deploymentId' => $deployment->id])
            ->handle(app(TenantContext::class));

        return $deployment->fresh();
    }

    // --------------------------------------------------------- the question

    public function test_an_answering_application_is_verified(): void
    {
        Http::fake(['app.example.com/*' => Http::response('ok', 200), 'app.example.com' => Http::response('ok', 200)]);

        $deployment = $this->verify($this->deployment());

        $this->assertSame('verified', $deployment->verification_status);
        $this->assertNotNull($deployment->verified_at);
        $this->assertSame('verified', $deployment->truth());
    }

    /** An application behind a login is a running application. */
    public function test_a_login_wall_counts_as_answering(): void
    {
        Http::fake(['app.example.com' => Http::response('', 401)]);

        $this->assertSame('verified', $this->verify($this->deployment())->verification_status);
    }

    public function test_a_server_error_is_not_a_deployment(): void
    {
        Http::fake(['app.example.com' => Http::response('', 502)]);

        $deployment = $this->verify($this->deployment());

        $this->assertSame('failed', $deployment->verification_status);
        $this->assertNull($deployment->verified_at);
    }

    public function test_a_host_that_does_not_answer_is_not_a_deployment(): void
    {
        Http::fake(fn () => throw new ConnectionException('no route'));

        $this->assertSame('failed', $this->verify($this->deployment())->verification_status);
    }

    /**
     * A deployment with no public URL is not a failed deployment. Saying
     * "failed" would make a working deploy look broken, which is the opposite
     * of the mistake this whole feature exists to stop.
     */
    public function test_nothing_to_ask_is_not_a_failure(): void
    {
        Http::fake();

        $deployment = $this->verify($this->deployment(['environment_url' => null]));

        $this->assertSame('unverifiable', $deployment->verification_status);
        $this->assertSame('reported', $deployment->truth());
        Http::assertNothingSent();
    }

    public function test_a_deployment_nobody_claimed_succeeded_is_never_asked(): void
    {
        Http::fake();

        $this->verify($this->deployment(['state' => 'failure']));

        Http::assertNothingSent();
    }

    /** The request is to somebody else's server; a webhook is not where it belongs. */
    public function test_the_check_happens_on_a_queue(): void
    {
        // Only this job: faking the whole queue would stop the delivery being
        // processed at all, and the test would be asserting about nothing.
        Queue::fake([VerifyDeployment::class]);

        $body = json_encode([
            'deployment' => ['id' => 42, 'environment' => 'production', 'ref' => 'main'],
            'deployment_status' => ['state' => 'success', 'environment' => 'production', 'environment_url' => 'https://app.example.com'],
        ]);

        $this->call('POST', "/api/v1/integrations/github/webhook/{$this->repository->webhook_key}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'deployment_status',
            'HTTP_X_GITHUB_DELIVERY' => (string) Str::uuid(),
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'secret'),
        ], $body)->assertOk();

        Queue::assertPushed(VerifyDeployment::class);
    }

    // ------------------------------------------------------------ the guard

    /**
     * The one that matters. This URL comes from whoever can create a deployment
     * in the customer's repository, and a server that fetches whatever it is
     * handed will be asked for the cloud metadata endpoint.
     */
    public function test_it_refuses_to_ask_the_metadata_service(): void
    {
        $result = PublicUrl::check('https://169.254.169.254/latest/meta-data/');

        $this->assertFalse($result['ok']);
        $this->assertSame('private_address', $result['reason']);
    }

    public function test_it_refuses_loopback_and_private_addresses(): void
    {
        foreach ([
            'https://127.0.0.1/health',
            'https://10.0.0.5/health',
            'https://192.168.1.1/',
            'https://[::1]/',
        ] as $url) {
            $this->assertFalse(PublicUrl::check($url)['ok'], "{$url} should be refused");
        }
    }

    public function test_it_refuses_localhost_by_name(): void
    {
        $this->assertFalse(PublicUrl::check('https://localhost/')['ok']);
        $this->assertFalse(PublicUrl::check('https://app.localhost/')['ok']);
    }

    /** Plain http from our servers to a customer's puts the answer on the wire. */
    public function test_it_refuses_anything_that_is_not_https(): void
    {
        $this->assertSame('not_https', PublicUrl::check('http://app.example.com/')['reason']);
        $this->assertSame('not_https', PublicUrl::check('file:///etc/passwd')['reason']);
        $this->assertSame('not_https', PublicUrl::check('gopher://x/')['reason']);
    }

    /** `https://user:pass@host` would send credentials we were never given. */
    public function test_it_refuses_a_url_carrying_credentials(): void
    {
        $this->assertSame('has_credentials', PublicUrl::check('https://user:pass@app.example.com/')['reason']);
    }

    public function test_it_refuses_an_unusual_port(): void
    {
        $this->assertSame('unusual_port', PublicUrl::check('https://app.example.com:2375/')['reason']);
    }

    public function test_it_refuses_nothing_at_all(): void
    {
        $this->assertSame('no_url', PublicUrl::check(null)['reason']);
        $this->assertSame('no_url', PublicUrl::check('')['reason']);
    }

    /**
     * A redirect is the standard way to walk a checked URL into an unchecked
     * one, so the check does not follow them.
     */
    public function test_a_redirect_is_not_an_answer(): void
    {
        Http::fake(['app.example.com' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

        $this->assertSame('failed', $this->verify($this->deployment())->verification_status);
    }
}

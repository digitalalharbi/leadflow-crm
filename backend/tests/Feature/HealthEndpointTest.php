<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The health endpoint is the only thing a deploy trusts, so what it says has to
 * be true in both directions: healthy means the components answered, and the
 * release it reports is the code actually serving the request.
 *
 * It is also unauthenticated and reachable from the internet, which is why the
 * release is behind a secret — a commit SHA tells an attacker exactly which
 * published advisories apply.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_every_component_without_authentication(): void
    {
        $body = $this->getJson('/api/health')->assertOk()->json('data');

        $this->assertSame('healthy', $body['status']);

        foreach (['application', 'database', 'redis', 'queue', 'storage'] as $component) {
            $this->assertArrayHasKey($component, $body['checks'], "'{$component}' is not checked.");
            $this->assertSame('ok', $body['checks'][$component]);
        }
    }

    public function test_realtime_is_reported_but_does_not_fail_the_gate(): void
    {
        config(['broadcasting.default' => 'reverb', 'reverb.servers.reverb.host' => '127.0.0.1', 'reverb.servers.reverb.port' => 1]);

        $body = $this->getJson('/api/health')->assertOk()->json('data');

        $this->assertSame('healthy', $body['status']);
        $this->assertSame('error', $body['checks']['realtime']);

        config(['broadcasting.default' => 'null']);
        $this->assertSame('off', $this->getJson('/api/health')->json('data.checks.realtime'));
    }

    public function test_it_never_leaks_the_release_to_an_anonymous_caller(): void
    {
        config(['app.deploy_secret' => 'the-deploy-secret']);
        file_put_contents(base_path('.release'), str_repeat('a', 40));

        try {
            $body = $this->getJson('/api/health')->assertOk()->json('data');

            $this->assertArrayNotHasKey('release', $body);

            $wrong = $this->withHeader('X-Deploy-Secret', 'not-the-secret')
                ->getJson('/api/health')->assertOk()->json('data');

            $this->assertArrayNotHasKey('release', $wrong);
        } finally {
            @unlink(base_path('.release'));
        }
    }

    public function test_the_deploy_secret_unlocks_the_commit_that_is_serving(): void
    {
        $sha = str_repeat('b', 40);
        config(['app.deploy_secret' => 'the-deploy-secret']);
        file_put_contents(base_path('.release'), $sha."\n");

        try {
            $body = $this->withHeader('X-Deploy-Secret', 'the-deploy-secret')
                ->getJson('/api/health')->assertOk()->json('data');

            $this->assertSame($sha, $body['release']);
        } finally {
            @unlink(base_path('.release'));
        }
    }

    /** An unset secret must authorise nobody, rather than everybody. */
    public function test_an_empty_secret_does_not_authorise_an_empty_header(): void
    {
        config(['app.deploy_secret' => '']);
        file_put_contents(base_path('.release'), str_repeat('c', 40));

        try {
            $body = $this->withHeader('X-Deploy-Secret', '')
                ->getJson('/api/health')->assertOk()->json('data');

            $this->assertArrayNotHasKey('release', $body);
        } finally {
            @unlink(base_path('.release'));
        }
    }

    /** Garbage in the file is reported as "unknown", never echoed back. */
    public function test_a_malformed_release_file_is_not_reflected(): void
    {
        config(['app.deploy_secret' => 'the-deploy-secret']);
        file_put_contents(base_path('.release'), '<script>alert(1)</script>');

        try {
            $body = $this->withHeader('X-Deploy-Secret', 'the-deploy-secret')
                ->getJson('/api/health')->assertOk()->json('data');

            $this->assertNull($body['release']);
        } finally {
            @unlink(base_path('.release'));
        }
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Api\V1\DemoController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The sales-demo endpoint fabricates leads on request. That is exactly what a
 * demo needs and exactly what production must never offer: a route that writes
 * invented customers into a client's real pipeline.
 */
class DemoEndpointTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Demo Co');
    }

    public function test_it_creates_a_believable_campaign_lead_outside_production(): void
    {
        $response = $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/demo/simulate-lead', ['platform' => 'snapchat']);

        $response->assertSuccessful();

        $this->assertDatabaseHas('leads', [
            'tenant_id' => $this->t['tenant']->id,
            'platform' => 'snapchat',
        ]);
    }

    public function test_the_route_is_not_registered_in_production(): void
    {
        // routes/api.php is evaluated at boot, so the environment has to be set
        // before the application starts for this to mean anything.
        $this->app = $this->createApplicationWithEnvironment('production');

        $this->assertFalse(
            collect($this->app['router']->getRoutes())->contains(
                fn ($route) => str_contains($route->uri(), 'demo/simulate-lead')
            ),
            'The demo lead generator is reachable in production.',
        );
    }

    public function test_the_controller_refuses_production_even_if_it_is_reached(): void
    {
        // Second line of defence: a stray route file, a cached route table from
        // another environment, or a future refactor must not be enough.
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(NotFoundHttpException::class);

        $this->actingAs($this->t['admin']);
        app(DemoController::class)->simulateLead(request());
    }

    public function test_a_sales_rep_may_not_fabricate_leads(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)
            ->postJson('/api/v1/demo/simulate-lead')
            ->assertStatus(403);
    }

    private function createApplicationWithEnvironment(string $environment): Application
    {
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $environment;

        try {
            return $this->createApplication();
        } finally {
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        }
    }
}

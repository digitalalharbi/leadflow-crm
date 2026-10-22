<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A malformed identifier is a client mistake, not a server fault.
 *
 * Every public id in this API is a UUID stored in a Postgres `uuid` column, so a
 * value that is not one reaches the driver and raises SQLSTATE 22P02. Unhandled,
 * that is a 500 any authenticated user can trigger at will — and with debug on,
 * the response carries the SQL and its bindings.
 */
class MalformedInputTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Malformed Co');
    }

    public static function routesWithAnIdentifier(): array
    {
        return [
            'lead' => ['GET', '/api/v1/leads/not-a-uuid'],
            'lead update' => ['PATCH', '/api/v1/leads/not-a-uuid'],
            'lead delete' => ['DELETE', '/api/v1/leads/not-a-uuid'],
            'lead status' => ['POST', '/api/v1/leads/not-a-uuid/status'],
            'lead assign' => ['POST', '/api/v1/leads/not-a-uuid/assign'],
            'lead note' => ['POST', '/api/v1/leads/not-a-uuid/notes'],
            'lead contact' => ['POST', '/api/v1/leads/not-a-uuid/contact'],
            'project' => ['GET', '/api/v1/projects/not-a-uuid'],
            'project update' => ['PATCH', '/api/v1/projects/not-a-uuid'],
            'follow-up update' => ['PATCH', '/api/v1/follow-ups/not-a-uuid'],
            'follow-up complete' => ['POST', '/api/v1/follow-ups/not-a-uuid/complete'],
            'follow-up delete' => ['DELETE', '/api/v1/follow-ups/not-a-uuid'],
            'user update' => ['PATCH', '/api/v1/users/not-a-uuid'],
            'integration update' => ['PATCH', '/api/v1/integrations/not-a-uuid'],
            'import' => ['GET', '/api/v1/imports/not-a-uuid'],
            'notification read' => ['POST', '/api/v1/notifications/not-a-uuid/read'],
        ];
    }

    #[DataProvider('routesWithAnIdentifier')]
    public function test_a_malformed_identifier_is_a_404_not_a_500(string $method, string $uri): void
    {
        $response = $this->actingAs($this->t['admin'])->json($method, $uri);

        $this->assertSame(
            404,
            $response->status(),
            "{$method} {$uri} answered {$response->status()} for a malformed id.",
        );
    }

    public static function filtersTakingAnIdentifier(): array
    {
        return [
            'leads project' => ['/api/v1/leads?project_id=not-a-uuid'],
            'leads assignee' => ['/api/v1/leads?assigned_to=not-a-uuid'],
            'dashboard project' => ['/api/v1/dashboard?project_id=not-a-uuid'],
            'dashboard assignee' => ['/api/v1/dashboard?assigned_to=not-a-uuid'],
        ];
    }

    #[DataProvider('filtersTakingAnIdentifier')]
    public function test_a_malformed_filter_value_is_rejected_not_fatal(string $uri): void
    {
        $response = $this->actingAs($this->t['admin'])->getJson($uri);

        $this->assertContains(
            $response->status(),
            [200, 422],
            "{$uri} answered {$response->status()} for a malformed filter.",
        );
    }

    public function test_a_database_error_never_leaks_sql_to_the_client(): void
    {
        // Debug detail is already gated to local/testing, but a query error must
        // not carry bindings — those are customer phone numbers and names.
        config(['app.debug' => true]);

        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads/not-a-uuid');
        $body = $response->getContent();

        $this->assertStringNotContainsString('select', strtolower($body));
        $this->assertStringNotContainsString('22P02', $body);
    }
}

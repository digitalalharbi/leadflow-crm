<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Api\V1\Platform\ImpersonationController;
use App\Models\Project;
use App\Models\ReportShare;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What the third security round found: reads wider than a person's projects, what a support session can reach, and guessing. */
class SecurityRound3Test extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private Project $mine;

    private Project $other;

    private User $limited;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeMailer();
        $this->a = $this->provisionTenant('Alpha Realty');
        [$this->mine, $this->other] = $this->asTenant($this->a['tenant'], fn () => [
            Project::create(['name' => 'Mine']),
            Project::create(['name' => 'Other']),
        ]);
        $this->limited = $this->makeUser($this->a['tenant'], Role::Manager, ['all_projects' => false]);
        $this->limited->projects()->sync([$this->mine->id]);
    }

    private function share(array $body = []): array
    {
        return $this->actingAs($this->a['admin'])->postJson('/api/v1/report-shares', ['name' => 'R', 'template' => 'executive', ...$body])->assertCreated()->json('data.share');
    }

    public function test_someone_limited_to_a_project_cannot_make_or_read_a_company_wide_report_link(): void
    {
        $this->actingAs($this->limited)->postJson('/api/v1/report-shares', ['name' => 'All of it', 'template' => 'executive'])->assertStatus(422);
        $this->actingAs($this->limited)->postJson('/api/v1/report-shares', ['name' => 'Theirs', 'template' => 'executive', 'project_id' => $this->other->uuid])->assertNotFound();
        $this->actingAs($this->limited)->postJson('/api/v1/report-shares', ['name' => 'Mine', 'template' => 'executive', 'project_id' => $this->mine->uuid])->assertCreated();

        $wide = $this->share();
        $foreign = $this->share(['project_id' => $this->other->uuid]);

        $names = collect($this->actingAs($this->limited)->getJson('/api/v1/report-shares')->assertOk()->json('data.shares'))->pluck('id');
        $this->assertNotContains($wide['id'], $names);
        $this->assertNotContains($foreign['id'], $names);
        $this->assertCount(1, $names);

        $this->actingAs($this->limited)->patchJson("/api/v1/report-shares/{$foreign['id']}", ['name' => 'Taken'])->assertNotFound();
        $this->actingAs($this->limited)->deleteJson("/api/v1/report-shares/{$wide['id']}")->assertNotFound();

        // The whole company still sees them all.
        $this->assertCount(3, $this->actingAs($this->a['admin'])->getJson('/api/v1/report-shares')->json('data.shares'));
    }

    public function test_company_wide_agent_reports_and_the_audit_trail_are_not_for_someone_limited_to_a_project(): void
    {
        $this->actingAs($this->limited)->getJson('/api/v1/agent-reports')->assertForbidden();
        $this->actingAs($this->limited)->getJson('/api/v1/audit')->assertForbidden();
        $this->actingAs($this->a['admin'])->getJson('/api/v1/agent-reports')->assertOk();
    }

    public function test_a_support_session_cannot_reach_what_would_outlive_it(): void
    {
        $person = $this->makeUser($this->a['tenant'], Role::Sales);
        $token = $this->a['admin']->createToken(ImpersonationController::TOKEN_NAME)->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        foreach ([
            ['PATCH', "/api/v1/users/{$person->uuid}", ['email' => 'moved@example.test']],
            ['POST', "/api/v1/users/{$person->uuid}/password-link", []],
            ['POST', '/api/v1/api-keys', ['name' => 'Kept']],
            ['POST', '/api/v1/webhook-subscriptions', ['url' => 'https://example.com/x', 'events' => ['lead.created']]],
            ['PUT', '/api/v1/access/rules', []],
            ['POST', '/api/v1/auth/security/sessions/end-others', []],
            ['POST', '/api/v1/auth/security/mfa/disable', []],
        ] as [$method, $path, $body]) {
            $this->app['auth']->forgetGuards();
            $this->withHeaders($headers)->json($method, $path, $body)->assertForbidden()->assertJsonPath('errors.support_session.0', 'refused');
        }
    }

    public function test_one_click_unsubscribe_gets_past_the_csrf_check(): void
    {
        $this->assertContains('e/u/*', app(ValidateCsrfToken::class)->getExcludedPaths());
    }

    public function test_a_shared_reports_password_cannot_be_guessed_at_leisure(): void
    {
        $share = $this->share(['password' => 'open-sesame']);
        $token = ReportShare::withoutGlobalScopes()->where('uuid', $share['id'])->first()->token();
        $this->app['auth']->forgetGuards();

        foreach (range(1, 5) as $i) {
            $this->withHeaders(['X-Share-Password' => "guess-{$i}"])->getJson("/api/v1/shared/reports/{$token}")->assertUnauthorized();
        }

        $this->withHeaders(['X-Share-Password' => 'open-sesame'])->getJson("/api/v1/shared/reports/{$token}")->assertStatus(429);
    }
}

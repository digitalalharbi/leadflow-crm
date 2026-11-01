<?php

namespace Tests\Feature;

use App\Domain\Files\ShareFile;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\FileShare;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A key limited to its integration's addresses is useless anywhere else; a leaked key is replaced in one step; a
 * brochure link stops opening after its window or the moment somebody revokes it.
 */
class ApiAndFileHardeningTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha');
    }

    private function issue(array $extra = []): array
    {
        return $this->actingAs($this->a['admin'])->postJson('/api/v1/api-keys', ['name' => 'Sync', 'scopes' => ['leads:read']] + $extra)->assertCreated()->json('data');
    }

    private function fromAddress(string $key, string $ip = '127.0.0.1')
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();

        return $this->withHeader('Authorization', 'Bearer '.$key)->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/api/v1/public/leads');
    }

    public function test_an_ip_limited_key_answers_only_its_addresses(): void
    {
        $issued = $this->issue(['allowed_ips' => ['203.0.113.0/24', '2001:db8::1']]);
        $this->assertSame(['203.0.113.0/24', '2001:db8::1'], $issued['key']['allowed_ips']);

        $this->fromAddress($issued['plain'], '203.0.113.9')->assertOk();
        $this->fromAddress($issued['plain'], '2001:db8::1')->assertOk();
        $this->fromAddress($issued['plain'], '198.51.100.7')->assertForbidden();

        // Lifting the limit opens it everywhere again.
        $this->actingAs($this->a['admin'])->patchJson("/api/v1/api-keys/{$issued['key']['id']}", ['allowed_ips' => []])->assertOk()->assertJsonPath('data.allowed_ips', []);
        $this->fromAddress($issued['plain'], '198.51.100.7')->assertOk();
    }

    public function test_a_key_can_have_its_own_rate(): void
    {
        $issued = $this->issue(['rate_limit_per_minute' => 10]);
        $this->assertSame(10, $issued['key']['rate_limit_per_minute']);

        for ($i = 0; $i < 10; $i++) {
            $this->fromAddress($issued['plain'])->assertOk();
        }
        $this->fromAddress($issued['plain'])->assertStatus(429);

        $this->actingAs($this->a['admin'])->postJson('/api/v1/api-keys', ['name' => 'x', 'scopes' => ['leads:read'], 'rate_limit_per_minute' => 5000])->assertUnprocessable();
        $this->assertSame(120, $this->issue()['key']['rate_limit_per_minute']);
    }

    public function test_bad_addresses_are_refused(): void
    {
        foreach ([['not-an-ip'], ['10.0.0.0/33'], ['999.1.1.1']] as $bad) {
            $this->actingAs($this->a['admin'])->postJson('/api/v1/api-keys', ['name' => 'x', 'scopes' => ['leads:read'], 'allowed_ips' => $bad])->assertUnprocessable();
        }
    }

    public function test_rotation_replaces_the_secret_and_keeps_the_settings(): void
    {
        $issued = $this->issue(['allowed_ips' => ['127.0.0.1'], 'expires_in_days' => 30]);

        $rotated = $this->actingAs($this->a['admin'])->postJson("/api/v1/api-keys/{$issued['key']['id']}/rotate")->assertCreated()->json('data');

        $this->assertNotSame($issued['plain'], $rotated['plain']);
        $this->assertSame(['leads:read'], $rotated['key']['scopes']);
        $this->assertSame(['127.0.0.1'], $rotated['key']['allowed_ips']);
        $this->assertSame(substr($issued['key']['expires_at'], 0, 10), substr($rotated['key']['expires_at'], 0, 10));

        $this->fromAddress($issued['plain'])->assertUnauthorized();
        $this->fromAddress($rotated['plain'])->assertOk();

        // A revoked key cannot be rotated back to life, and a manager cannot rotate at all.
        $this->actingAs($this->a['admin'])->postJson("/api/v1/api-keys/{$issued['key']['id']}/rotate")->assertNotFound();
        $this->actingAs($this->makeUser($this->a['tenant'], Role::Manager))->postJson("/api/v1/api-keys/{$rotated['key']['id']}/rotate")->assertForbidden();
        $this->assertSame(2, ApiKey::withoutGlobalScopes()->count());
    }

    private function share(): array
    {
        Storage::fake('local');

        return $this->asTenant($this->a['tenant'], function () {
            $project = Project::create(['name' => 'Tower']);
            Storage::disk('local')->put('tenant/'.$this->a['tenant']->id.'/b.pdf', '%PDF-1.4');
            $file = ProjectFile::create(['project_id' => $project->id, 'name' => 'Brochure.pdf', 'kind' => 'brochure', 'path' => 'tenant/'.$this->a['tenant']->id.'/b.pdf', 'mime' => 'application/pdf', 'size_bytes' => 8, 'is_active' => true]);
            $lead = Lead::create(['full_name' => 'Sara', 'phone' => '0551234567']);

            return [$lead, $file, app(ShareFile::class)->forLead($file, $lead, $this->a['admin'])];
        });
    }

    public function test_a_brochure_link_expires_and_can_be_revoked_and_resending_makes_a_new_one(): void
    {
        [$lead, $file, $share] = $this->share();
        $token = FileShare::withoutGlobalScopes()->find($share->id)->token;

        $this->get('/f/'.$token)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->a['admin'])->postJson("/api/v1/leads/{$lead->uuid}/shares/{$share->uuid}/revoke")->assertOk();
        $this->get('/f/'.$token)->assertNotFound();

        // Sent again: a new link, never the revoked one.
        $again = $this->asTenant($this->a['tenant'], fn () => app(ShareFile::class)->forLead($file, $lead, $this->a['admin']));
        $fresh = FileShare::withoutGlobalScopes()->find($again->id);
        $this->assertSame($share->id, $fresh->id);
        $this->assertNotSame($token, $fresh->token);
        $this->get('/f/'.$fresh->token)->assertOk();

        $this->travel(FileShare::LIFETIME_DAYS + 1)->days();
        $this->get('/f/'.$fresh->token)->assertNotFound();
    }

    public function test_only_who_may_work_the_lead_revokes_its_links(): void
    {
        [$lead, , $share] = $this->share();
        $other = $this->provisionTenant('Beta');

        $this->actingAs($this->makeUser($this->a['tenant'], Role::Sales))->postJson("/api/v1/leads/{$lead->uuid}/shares/{$share->uuid}/revoke")->assertForbidden();
        $this->actingAs($other['admin'])->postJson("/api/v1/leads/{$lead->uuid}/shares/{$share->uuid}/revoke")->assertNotFound();
        $this->assertNull(FileShare::withoutGlobalScopes()->find($share->id)->revoked_at);
    }
}

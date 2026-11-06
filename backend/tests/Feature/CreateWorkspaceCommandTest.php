<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\Integration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/** The shell command that creates a company workspace: complete, owned by a real address, and safe to run twice. */
class CreateWorkspaceCommandTest extends TestCase
{
    use RefreshDatabase;

    private function run_(array $over = []): PendingCommand
    {
        return $this->artisan('mohtam:create-workspace', $over + [
            '--company' => 'شركة مهتم', '--slug' => 'mohtam-co', '--admin-name' => 'Owner', '--admin-email' => 'owner@mohtam.pro',
        ]);
    }

    public function test_it_creates_a_complete_workspace_owned_by_the_address_and_emails_a_setup_link(): void
    {
        $this->run_()->expectsOutputToContain('Workspace created: mohtam-co')->assertSuccessful();

        $tenant = Tenant::where('slug', 'mohtam-co')->firstOrFail();
        $owner = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame('owner@mohtam.pro', $owner->email);
        $this->assertSame($owner->id, $tenant->owner_user_id);
        $this->assertTrue($tenant->is_active);
        $this->assertSame(1, Integration::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(), 'a working inbound endpoint');
        $this->assertSame(1, EmailDelivery::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('to_email', 'owner@mohtam.pro')->count(), 'the setup link is emailed to the owner');
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->run_()->assertSuccessful();
        $this->run_()->expectsOutputToContain('already exists. Nothing changed.')->assertSuccessful();

        $this->assertSame(1, Tenant::where('slug', 'mohtam-co')->count());
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'owner@mohtam.pro')->count());
    }

    public function test_it_refuses_an_address_that_can_never_receive_the_link(): void
    {
        $this->run_(['--admin-email' => 'owner@leadflow-demo.test'])->assertFailed();

        $this->assertSame(0, Tenant::where('slug', 'mohtam-co')->count());
    }

    public function test_it_refuses_a_reserved_or_malformed_slug(): void
    {
        $this->run_(['--slug' => 'Bad Slug'])->assertFailed();
        $this->run_(['--slug' => (string) config('tenancy.reserved_slugs.0')])->assertFailed();

        $this->assertSame(0, Tenant::where('slug', 'bad slug')->count());
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Agents\Orchestrator;
use App\Domain\Automation\Recovery;
use App\Domain\Sla\InterestSlaSweeper;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/**
 * Users are the one model that is not tenant-scoped (the sign-in lookup runs before a tenant exists),
 * so every query for "the managers" or "the sellers" has to name its workspace itself. Forgetting to
 * meant another company's managers were told about this one's leads, and this one's setup checklist
 * counted somebody else's team.
 */
class WorkspacePeopleNeverLeakTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Home Co');
        $this->rival = $this->provisionTenant('Rival Co');
    }

    private function heardAnything(User $user): int
    {
        return DatabaseNotification::where('notifiable_id', $user->id)->count();
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل خاص', 'phone' => '0551234567', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'new',
        ], $attributes)));
    }

    public function test_searching_for_people_finds_only_this_workspaces_users(): void
    {
        $this->makeUser($this->rival['tenant'], Role::Sales, ['name' => 'رنا الغريبة']);
        $mine = $this->makeUser($this->t['tenant'], Role::Sales, ['name' => 'رنا الزميلة']);

        $names = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/search?'.http_build_query(['q' => 'رنا']))->assertOk()->json('data.people'))->pluck('name');

        $this->assertTrue($names->contains($mine->name));
        $this->assertFalse($names->contains('رنا الغريبة'));
    }

    public function test_the_attribution_filter_lists_only_this_workspaces_reps(): void
    {
        $this->makeUser($this->rival['tenant'], Role::Sales, ['name' => 'مندوب الغريب']);

        $reps = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/tracking/attribution/options')->assertOk()->json('data.reps'))->pluck('name');

        $this->assertFalse($reps->contains('مندوب الغريب'));
        $this->assertTrue($reps->contains($this->t['admin']->name));
    }

    public function test_the_setup_checklist_does_not_count_another_workspaces_sellers(): void
    {
        // Only the rival has a rep. This workspace has an admin, and the admin is not a seller.
        $this->makeUser($this->rival['tenant'], Role::Sales);
        $this->t['admin']->forceFill(['role' => Role::Admin])->save();
        User::where('tenant_id', $this->t['tenant']->id)->where('role', Role::Manager->value)->delete();

        $steps = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/onboarding/checklist')->assertOk()->json('data.steps'));

        $this->assertFalse((bool) $steps->firstWhere('key', 'team')['done']);
    }

    public function test_an_interest_escalation_goes_to_this_workspaces_managers_only(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $lead = $this->lead(['assigned_to' => $rep->id]);
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['interest_level' => 'hot', 'interest_level_changed_at' => now()->subHours(5)])->saveQuietly());

        $this->asTenant($this->t['tenant'], fn () => app(InterestSlaSweeper::class)->sweep($this->t['tenant']));

        $this->assertGreaterThan(0, $this->heardAnything($this->t['admin']), 'its own managers are told');
        $this->assertSame(0, $this->heardAnything($this->rival['admin']), 'another workspace is not');
    }

    public function test_an_agent_finding_is_announced_to_this_workspaces_managers_only(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $alert = new \ReflectionMethod(Orchestrator::class, 'alert');
            $alert->invoke(app(Orchestrator::class), 'budget', 'حملة تستنزف الميزانية', 'تفاصيل', '/campaigns');
        });

        $this->assertGreaterThan(0, $this->heardAnything($this->t['admin']));
        $this->assertSame(0, $this->heardAnything($this->rival['admin']));
    }

    public function test_a_lead_nothing_can_reach_and_nobody_owns_alerts_this_workspaces_managers_only(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $handOff = new \ReflectionMethod(Recovery::class, 'handOff');
            $handOff->invoke(app(Recovery::class), $lead, 'whatsapp', 'email');
        });

        $this->assertGreaterThan(0, $this->heardAnything($this->t['admin']));
        $this->assertSame(0, $this->heardAnything($this->rival['admin']));
    }
}

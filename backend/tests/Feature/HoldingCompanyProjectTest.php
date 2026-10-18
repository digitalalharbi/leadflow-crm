<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Project;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Holding → Company → Project.
 *
 * The workspace is the holding. Companies are optional businesses inside it, a
 * project belongs to at most one, and a lead's company is its project's. What
 * must hold: nothing changes for a workspace with no companies; choosing a
 * company narrows *every* screen the same way; permission comes from
 * membership and is never widened by what a client asks for; and none of it
 * crosses a workspace.
 */
class HoldingCompanyProjectTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Company $alpha;

    private Company $beta;

    private Project $alphaProject;

    private Project $betaProject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Holding Co');

        $this->asTenant($this->t['tenant'], function () {
            $this->alpha = Company::create(['name' => 'الشركة الأولى', 'is_active' => true]);
            $this->beta = Company::create(['name' => 'الشركة الثانية', 'is_active' => true]);

            $this->alphaProject = Project::create(['name' => 'مشروع أ', 'company_id' => $this->alpha->id]);
            $this->betaProject = Project::create(['name' => 'مشروع ب', 'company_id' => $this->beta->id]);
        });
    }

    private function lead(?Project $project, array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل '.random_int(100, 999),
            'phone_normalized' => '+9665'.random_int(10000000, 99999999),
            'status' => 'new',
            'project_id' => $project?->id,
            'assigned_to' => $this->t['admin']->id,
        ], $attributes)));
    }

    private function as($user, ?Company $company = null)
    {
        // Headers set on the test case persist across calls; a call that does not
        // choose a company must not inherit the last one's choice.
        $this->flushHeaders();
        $headers = $company ? ['X-Company-Id' => $company->uuid] : [];

        return $this->actingAs($user)->withHeaders($headers);
    }

    private function leadIds($response): array
    {
        return collect($response->json('data'))->pluck('full_name')->sort()->values()->all();
    }

    // -------------------------------------------------------- the structure

    public function test_an_admin_shapes_the_holding_and_nobody_else_does(): void
    {
        $this->as($this->t['admin'])->postJson('/api/v1/companies', ['name' => 'شركة جديدة', 'color' => '#336699'])
            ->assertCreated()->assertJsonPath('data.company.name', 'شركة جديدة');

        foreach ([Role::Manager, Role::Sales] as $role) {
            $user = $this->makeUser($this->t['tenant'], $role);
            $this->as($user)->postJson('/api/v1/companies', ['name' => 'x'])->assertForbidden();
            $this->as($user)->patchJson('/api/v1/companies/'.$this->alpha->uuid, ['name' => 'x'])->assertForbidden();
            $this->as($user)->deleteJson('/api/v1/companies/'.$this->alpha->uuid)->assertForbidden();
        }
    }

    public function test_a_company_with_projects_cannot_be_deleted(): void
    {
        $this->as($this->t['admin'])->deleteJson('/api/v1/companies/'.$this->alpha->uuid)->assertStatus(422);

        $this->assertNotNull(Company::withoutGlobalScopes()->find($this->alpha->id));
    }

    public function test_an_empty_company_can_be_deleted(): void
    {
        $empty = $this->asTenant($this->t['tenant'], fn () => Company::create(['name' => 'فارغة', 'is_active' => true]));

        $this->as($this->t['admin'])->deleteJson('/api/v1/companies/'.$empty->uuid)->assertOk();
    }

    public function test_a_project_is_assigned_to_a_company_by_uuid(): void
    {
        $this->as($this->t['admin'])->patchJson('/api/v1/projects/'.$this->alphaProject->uuid, [
            'company_id' => $this->beta->uuid,
        ])->assertOk()->assertJsonPath('data.project.company.name', 'الشركة الثانية');

        $this->assertSame($this->beta->id, $this->alphaProject->fresh()->company_id);
    }

    public function test_a_project_can_go_back_to_the_holding(): void
    {
        $this->as($this->t['admin'])->patchJson('/api/v1/projects/'.$this->alphaProject->uuid, ['company_id' => null])
            ->assertOk();

        $this->assertNull($this->alphaProject->fresh()->company_id);
    }

    public function test_an_unknown_company_is_an_error_not_a_silent_null(): void
    {
        $this->as($this->t['admin'])->patchJson('/api/v1/projects/'.$this->alphaProject->uuid, [
            'company_id' => '11111111-1111-4111-8111-111111111111',
        ])->assertStatus(422);

        $this->assertSame($this->alpha->id, $this->alphaProject->fresh()->company_id);
    }

    public function test_another_workspaces_company_cannot_be_assigned(): void
    {
        $other = $this->provisionTenant('Elsewhere');
        $foreign = $this->asTenant($other['tenant'], fn () => Company::create(['name' => 'غريبة', 'is_active' => true]));

        $this->as($this->t['admin'])->patchJson('/api/v1/projects/'.$this->alphaProject->uuid, [
            'company_id' => $foreign->uuid,
        ])->assertStatus(422);
    }

    // ------------------------------------------------- nothing else changes

    public function test_a_workspace_that_never_chose_a_company_sees_everything(): void
    {
        $this->lead($this->alphaProject, ['full_name' => 'أ']);
        $this->lead($this->betaProject, ['full_name' => 'ب']);
        $this->lead(null, ['full_name' => 'بلا مشروع']);

        $this->assertSame(['أ', 'ب', 'بلا مشروع'], $this->leadIds($this->as($this->t['admin'])->getJson('/api/v1/leads?per_page=50')));
    }

    // ------------------------------------------------- choosing a company

    public function test_choosing_a_company_narrows_the_lead_list(): void
    {
        $this->lead($this->alphaProject, ['full_name' => 'أ']);
        $this->lead($this->betaProject, ['full_name' => 'ب']);
        $this->lead(null, ['full_name' => 'بلا مشروع']);

        $this->assertSame(['أ'], $this->leadIds($this->as($this->t['admin'], $this->alpha)->getJson('/api/v1/leads?per_page=50')));
        $this->assertSame(['ب'], $this->leadIds($this->as($this->t['admin'], $this->beta)->getJson('/api/v1/leads?per_page=50')));
    }

    public function test_the_dashboard_follows_the_choice(): void
    {
        $this->lead($this->alphaProject);
        $this->lead($this->alphaProject);
        $this->lead($this->betaProject);

        $this->assertSame(3, $this->as($this->t['admin'])->getJson('/api/v1/dashboard')->json('data.summary.total_leads'));
        $this->assertSame(2, $this->as($this->t['admin'], $this->alpha)->getJson('/api/v1/dashboard')->json('data.summary.total_leads'));
        $this->assertSame(1, $this->as($this->t['admin'], $this->beta)->getJson('/api/v1/dashboard')->json('data.summary.total_leads'));
    }

    public function test_the_command_center_and_the_work_queue_follow_the_choice(): void
    {
        $mine = $this->lead($this->alphaProject, ['score' => 10]);
        $this->lead($this->betaProject, ['score' => 90]);

        $queue = $this->as($this->t['admin'], $this->alpha)->getJson('/api/v1/work/next')->json('data.lead.id');
        $this->assertSame($mine->uuid, $queue);

        $this->assertSame(1, $this->as($this->t['admin'], $this->alpha)->getJson('/api/v1/dashboard/command')->json('data.open_leads'));
    }

    public function test_the_project_list_follows_the_choice(): void
    {
        $names = collect($this->as($this->t['admin'], $this->alpha)->getJson('/api/v1/projects')->json('data.projects'))->pluck('name')->all();

        $this->assertSame(['مشروع أ'], $names);
    }

    public function test_appointments_follow_the_choice(): void
    {
        $inAlpha = $this->lead($this->alphaProject);
        $inBeta = $this->lead($this->betaProject);

        $this->asTenant($this->t['tenant'], function () use ($inAlpha, $inBeta) {
            foreach ([$inAlpha, $inBeta] as $lead) {
                Appointment::create([
                    'lead_id' => $lead->id, 'assigned_to' => $this->t['admin']->id,
                    'scheduled_at' => now()->addDay(), 'status' => 'scheduled',
                ]);
            }
        });

        $this->assertCount(1, $this->as($this->t['admin'], $this->alpha)->getJson('/api/v1/appointments')->json('data'));
        $this->assertCount(2, $this->as($this->t['admin'])->getJson('/api/v1/appointments')->json('data'));
    }

    public function test_the_choice_does_not_outlive_the_request(): void
    {
        $this->lead($this->alphaProject);
        $this->lead($this->betaProject);

        $this->as($this->t['admin'], $this->alpha)->getJson('/api/v1/leads')->assertOk();

        // A job, a command or the next request without a header sees it all.
        $this->assertNull(app(CompanyContext::class)->ids());
        $this->assertSame(2, $this->asTenant($this->t['tenant'], fn () => Lead::count()));
    }

    // ------------------------------------------------ permission, not choice

    public function test_a_member_is_held_to_their_companies_without_asking(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Manager);
        $this->alpha->members()->attach($rep->id);

        $this->lead($this->alphaProject, ['full_name' => 'أ']);
        $this->lead($this->betaProject, ['full_name' => 'ب']);

        $this->assertSame(['أ'], $this->leadIds($this->as($rep)->getJson('/api/v1/leads?per_page=50')));
        $this->assertSame(['مشروع أ'], collect($this->as($rep)->getJson('/api/v1/projects')->json('data.projects'))->pluck('name')->all());
    }

    public function test_a_member_cannot_look_into_a_company_they_are_not_in(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Manager);
        $this->alpha->members()->attach($rep->id);

        $this->as($rep, $this->beta)->getJson('/api/v1/leads')->assertForbidden();
    }

    public function test_the_header_never_widens_what_a_member_may_see(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Manager);
        $this->alpha->members()->attach($rep->id);
        $this->lead($this->betaProject, ['full_name' => 'ب']);

        $this->assertSame([], $this->leadIds($this->as($rep, $this->alpha)->getJson('/api/v1/leads')));
    }

    public function test_an_admin_is_never_restricted_by_membership(): void
    {
        $this->alpha->members()->attach($this->t['admin']->id);
        $this->lead($this->betaProject, ['full_name' => 'ب']);

        $this->assertSame(['ب'], $this->leadIds($this->as($this->t['admin'])->getJson('/api/v1/leads')));
    }

    public function test_a_user_in_no_company_sees_what_their_role_always_allowed(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $this->lead($this->alphaProject, ['full_name' => 'أ']);
        $this->lead($this->betaProject, ['full_name' => 'ب']);

        $this->assertSame(['أ', 'ب'], $this->leadIds($this->as($manager)->getJson('/api/v1/leads?per_page=50')));
    }

    public function test_the_overview_lists_only_what_a_member_may_see(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Manager);
        $this->alpha->members()->attach($rep->id);

        $response = $this->as($rep)->getJson('/api/v1/companies')->assertOk();

        $this->assertSame(['الشركة الأولى'], collect($response->json('data.companies'))->pluck('name')->all());
        $this->assertTrue($response->json('data.restricted'));
    }

    // ----------------------------------------------------------- the numbers

    public function test_the_overview_counts_each_companys_leads_through_its_projects(): void
    {
        $this->lead($this->alphaProject, ['status' => 'won']);
        $this->lead($this->alphaProject, ['temperature' => 'hot']);
        $this->lead($this->betaProject);

        // Even while looking into one, the overview shows them all.
        $metrics = collect($this->as($this->t['admin'], $this->alpha)->getJson('/api/v1/companies')->json('data.metrics'))
            ->keyBy('id');

        $this->assertSame(2, $metrics[$this->alpha->uuid]['leads']);
        $this->assertSame(1, $metrics[$this->alpha->uuid]['won']);
        $this->assertSame(1, $metrics[$this->alpha->uuid]['hot']);
        $this->assertEquals(50.0, $metrics[$this->alpha->uuid]['conversion_rate']);
        $this->assertSame(1, $metrics[$this->beta->uuid]['leads']);
    }

    public function test_drilling_into_a_company_lists_its_projects_with_numbers(): void
    {
        $this->lead($this->alphaProject, ['status' => 'won']);

        $response = $this->as($this->t['admin'])->getJson('/api/v1/companies/'.$this->alpha->uuid.'/projects')->assertOk();

        $this->assertSame('مشروع أ', $response->json('data.projects.0.name'));
        $this->assertSame(1, $response->json('data.projects.0.leads'));
        $this->assertSame(1, $response->json('data.projects.0.won'));
    }

    // ---------------------------------------------------------- the isolation

    public function test_one_workspace_never_sees_anothers_companies(): void
    {
        $other = $this->provisionTenant('Elsewhere');
        $this->asTenant($other['tenant'], fn () => Company::create(['name' => 'غريبة', 'is_active' => true]));

        $names = collect($this->as($this->t['admin'])->getJson('/api/v1/companies')->json('data.companies'))->pluck('name')->all();

        $this->assertNotContains('غريبة', $names);
        $this->assertCount(2, $names);
    }

    public function test_asking_for_another_workspaces_company_is_refused(): void
    {
        $other = $this->provisionTenant('Elsewhere');
        $foreign = $this->asTenant($other['tenant'], fn () => Company::create(['name' => 'غريبة', 'is_active' => true]));

        $this->actingAs($this->t['admin'])->withHeaders(['X-Company-Id' => $foreign->uuid])
            ->getJson('/api/v1/leads')->assertForbidden();
    }

    public function test_the_refusal_does_not_say_whether_the_company_exists(): void
    {
        $missing = $this->actingAs($this->t['admin'])->withHeaders(['X-Company-Id' => '11111111-1111-4111-8111-111111111111'])
            ->getJson('/api/v1/leads');

        $other = $this->provisionTenant('Elsewhere');
        $foreign = $this->asTenant($other['tenant'], fn () => Company::create(['name' => 'غريبة', 'is_active' => true]));
        $real = $this->actingAs($this->t['admin'])->withHeaders(['X-Company-Id' => $foreign->uuid])->getJson('/api/v1/leads');

        $this->assertSame($missing->status(), $real->status());
        $this->assertSame($missing->json('message'), $real->json('message'));
    }

    public function test_members_can_only_be_chosen_from_this_workspace(): void
    {
        $other = $this->provisionTenant('Elsewhere');

        $this->as($this->t['admin'])->putJson('/api/v1/companies/'.$this->alpha->uuid.'/members', [
            'user_ids' => [$other['admin']->uuid, $this->t['admin']->uuid],
        ])->assertOk();

        $this->assertSame([$this->t['admin']->id], $this->alpha->members()->pluck('users.id')->all());
    }
}

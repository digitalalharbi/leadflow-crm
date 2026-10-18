<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The row of counts above the leads table, and the cards on the projects page.
 *
 * A count is only worth showing if it is exactly the length of the list behind
 * it — so the one assertion that matters here is that the number and the list
 * agree, for every segment.
 */
class LeadSegmentsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Segments Co');
        $this->t['tenant']->settings->forceFill(['first_contact_target_minutes' => 30])->save();
    }

    private function lead(array $attributes = [], int $ageMinutes = 1): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($attributes, $ageMinutes) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل '.random_int(100, 999),
                'phone_normalized' => '+9665'.random_int(10000000, 99999999),
                'status' => 'contacted',
                'first_contact_at' => now(),
                'assigned_to' => $this->t['admin']->id,
            ], $attributes));

            $lead->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->saveQuietly();

            return $lead->fresh();
        });
    }

    private function segments($as = null)
    {
        return $this->actingAs($as ?? $this->t['admin'])->getJson('/api/v1/leads/segments')->assertOk()->json('data');
    }

    // ---------------------------------------------------------------- counts

    public function test_each_segment_counts_what_it_says(): void
    {
        $this->lead(['status' => 'new', 'first_contact_at' => null], ageMinutes: 90);        // awaiting contact
        $this->lead(['temperature' => 'hot']);                                                  // hot
        $this->lead(['next_follow_up_at' => now()->subDay()]);                                  // overdue
        $this->lead(['assigned_to' => null]);                                                   // unassigned
        $this->lead(['first_response_at' => now()]);                                            // replied
        $p = $this->lead(['temperature' => 'hot']);
        $this->asTenant($this->t['tenant'], fn () => $p->forceFill(['is_priority' => true])->saveQuietly());

        $s = $this->segments();

        $this->assertSame(6, $s['all']);
        $this->assertSame(2, $s['hot']);
        $this->assertSame(1, $s['priority']);
        $this->assertSame(1, $s['awaiting_contact']);
        $this->assertSame(1, $s['overdue']);
        $this->assertSame(1, $s['unassigned']);
        $this->assertSame(1, $s['replied']);
    }

    /** The point of the feature: a number and the list behind it have the same length. */
    public function test_every_count_is_the_length_of_its_list(): void
    {
        $this->lead(['status' => 'new', 'first_contact_at' => null], ageMinutes: 90);
        $this->lead(['status' => 'new', 'first_contact_at' => null], ageMinutes: 120);
        $this->lead(['temperature' => 'hot']);
        $this->lead(['next_follow_up_at' => now()->subDay()]);
        $this->lead(['assigned_to' => null]);
        $this->lead(['first_response_at' => now()]);
        $p = $this->lead(['temperature' => 'hot']);
        $this->asTenant($this->t['tenant'], fn () => $p->forceFill(['is_priority' => true])->saveQuietly());

        $s = $this->segments();

        $lists = [
            'priority' => '?priority=1',
            'hot' => '?temperature[]=hot',
            'awaiting_contact' => '?awaiting_contact=1',
            'overdue' => '?overdue=1',
            'unassigned' => '?unassigned=1',
            'replied' => '?replied=1',
        ];

        foreach ($lists as $key => $query) {
            $total = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads'.$query.'&per_page=100')->json('meta.total');
            $this->assertSame($s[$key], $total, "$key: the count and its list disagree");
        }
    }

    public function test_closed_leads_are_in_no_working_segment(): void
    {
        $this->lead(['status' => 'won', 'temperature' => 'hot', 'assigned_to' => null, 'next_follow_up_at' => now()->subDay()]);
        $this->lead(['status' => 'lost', 'first_response_at' => now()]);

        $s = $this->segments();

        $this->assertSame(2, $s['all']);
        foreach (['priority', 'hot', 'overdue', 'unassigned', 'replied'] as $key) {
            $this->assertSame(0, $s[$key], $key);
        }
    }

    public function test_a_rep_counts_only_their_own_leads_and_never_the_unassigned(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->lead(['assigned_to' => $rep->id, 'temperature' => 'hot']);
        $this->lead(['temperature' => 'hot']);
        $this->lead(['assigned_to' => null]);

        $s = $this->segments($rep);

        $this->assertSame(1, $s['all']);
        $this->assertSame(1, $s['hot']);
        $this->assertSame(0, $s['unassigned']);
    }

    public function test_it_follows_the_company_the_screen_is_looking_at(): void
    {
        [$a, $b, $pa, $pb] = $this->asTenant($this->t['tenant'], function () {
            $a = Company::create(['name' => 'أ', 'is_active' => true]);
            $b = Company::create(['name' => 'ب', 'is_active' => true]);

            return [$a, $b, Project::create(['name' => 'مشروع أ', 'company_id' => $a->id]), Project::create(['name' => 'مشروع ب', 'company_id' => $b->id])];
        });
        $this->lead(['project_id' => $pa->id, 'temperature' => 'hot']);
        $this->lead(['project_id' => $pb->id, 'temperature' => 'hot']);
        $this->lead(['project_id' => $pb->id]);

        $this->flushHeaders();
        $s = $this->actingAs($this->t['admin'])->withHeaders(['X-Company-Id' => $b->uuid])->getJson('/api/v1/leads/segments')->json('data');

        $this->assertSame(2, $s['all']);
        $this->assertSame(1, $s['hot']);
    }

    public function test_one_workspace_never_counts_anothers(): void
    {
        $other = $this->provisionTenant('Elsewhere');
        $this->asTenant($other['tenant'], fn () => Lead::create(['full_name' => 'غريب', 'phone_normalized' => '+966500000009', 'status' => 'new']));

        $this->assertSame(0, $this->segments()['all']);
    }

    // ---------------------------------------------------------- next action

    public function test_the_list_says_what_to_do_with_each_lead(): void
    {
        $this->lead(['status' => 'new', 'first_contact_at' => null], ageMinutes: 90);

        $row = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads')->json('data.0');

        $this->assertSame('call_now', $row['next_action']['action']);
        $this->assertSame('critical', $row['next_action']['urgency']);
        $this->assertNotEmpty($row['next_action']['reason']);
    }

    public function test_other_screens_that_reuse_the_row_are_not_made_to_compute_it(): void
    {
        $this->lead(['temperature' => 'hot']);

        $hot = $this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard')->json('data.hot_leads.0');

        $this->assertArrayNotHasKey('next_action', $hot);
    }

    public function test_a_priority_lead_is_flagged_in_the_row(): void
    {
        $lead = $this->lead(['temperature' => 'hot']);
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['is_priority' => true])->saveQuietly());

        $this->assertTrue($this->actingAs($this->t['admin'])->getJson('/api/v1/leads')->json('data.0.is_priority'));
    }

    // ------------------------------------------------------ the project cards

    public function test_a_project_card_says_who_is_waiting_and_what_it_has(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $this->lead(['project_id' => $project->id, 'status' => 'new', 'first_contact_at' => null], ageMinutes: 90);
        $this->lead(['project_id' => $project->id, 'temperature' => 'hot']);

        $this->asTenant($this->t['tenant'], function () use ($project) {
            ProjectFile::create(['project_id' => $project->id, 'name' => 'x', 'kind' => 'brochure', 'path' => 'p.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1, 'is_active' => true]);
            ProjectQuestion::create(['project_id' => $project->id, 'key' => 'q', 'label' => 'س', 'type' => 'boolean', 'is_active' => true]);
        });

        $m = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/projects')->json('data.metrics'))->firstWhere('id', $project->uuid);

        $this->assertSame(1, $m['awaiting_contact']);
        $this->assertSame(1, $m['hot']);
        $this->assertSame(1, $m['files']);
        $this->assertSame(1, $m['questions']);
        $this->assertCount(14, $m['trend']);
        $this->assertSame(2, array_sum($m['trend']));
    }

    public function test_a_project_with_nothing_has_zeros_and_a_flat_trend(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'فارغ']));

        $m = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/projects')->json('data.metrics'))->firstWhere('id', $project->uuid);

        $this->assertSame(0, $m['hot']);
        $this->assertSame(array_fill(0, 14, 0), $m['trend']);
    }
}

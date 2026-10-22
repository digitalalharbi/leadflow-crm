<?php

namespace Tests\Feature;

use App\Domain\Leads\LeadViews;
use App\Enums\ActivityType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The eight lists, the ones a person saves, and the numbers that say the promise is kept. */
class SlaAndSavedViewsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Sla Co');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lead(array $attributes = []): Lead
    {
        $guarded = array_intersect_key($attributes, array_flip(['automation_state', 'created_at', 'first_response_at', 'handed_off_at', 'qualification_status', 'last_contact_at', 'first_contact_at']));

        return $this->asTenant($this->t['tenant'], function () use ($attributes, $guarded) {
            $lead = Lead::create(array_merge([
                'full_name' => 'عميل '.random_int(100, 999), 'phone' => '0551234567',
                'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'new',
                'assigned_to' => $this->t['admin']->id,
            ], array_diff_key($attributes, $guarded)));

            if ($guarded) {
                $lead->forceFill($guarded)->saveQuietly();
            }

            return $lead;
        });
    }

    private function inView(string $key): array
    {
        return collect($this->actingAs($this->t['admin'])->getJson("/api/v1/leads?view={$key}&per_page=100")->assertOk()->json('data'))->pluck('id')->all();
    }

    // ------------------------------------------------------------ the 8 views

    public function test_each_view_lists_exactly_the_leads_its_count_says(): void
    {
        $hot = $this->lead(['temperature' => 'hot', 'status' => 'contacted']);
        $needsHuman = $this->lead(['status' => 'contacted', 'automation_state' => Lead::AUTOMATION_HANDED_OFF, 'handed_off_at' => now()->subHour()]);
        $noReply = $this->lead(['status' => 'contacted', 'first_contact_at' => now()->subDays(2), 'last_contact_at' => now()->subDays(2)]);
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس', 'starting_price' => 900000]));
        $highValue = $this->lead(['project_id' => $project->id, 'budget_max' => 1_200_000, 'status' => 'contacted']);
        $qualified = $this->lead(['status' => 'qualified', 'qualification_status' => 'qualified']);
        $stalled = $this->lead(['status' => 'contacted', 'last_contact_at' => now()->subDays(6), 'created_at' => now()->subDays(10)]);
        $fresh = $this->lead();

        $counts = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads/segments')->assertOk()->json('data');

        foreach (LeadViews::KEYS as $key) {
            $this->assertSame($counts[$key], count($this->inView($key)), "the {$key} chip and its list agree");
        }

        $this->assertContains($needsHuman->uuid, $this->inView('needs_human'));
        $this->assertContains($noReply->uuid, $this->inView('no_reply'));
        $this->assertContains($highValue->uuid, $this->inView('high_value'));
        $this->assertNotContains($fresh->uuid, $this->inView('high_value'));
        $this->assertContains($qualified->uuid, $this->inView('qualified'));
        $this->assertContains($stalled->uuid, $this->inView('stalled'));
        $this->assertContains($fresh->uuid, $this->inView('new_today'));
        $this->assertNotContains($stalled->uuid, $this->inView('new_today'));
    }

    public function test_a_lead_a_person_has_already_picked_up_does_not_need_a_human(): void
    {
        $lead = $this->lead(['status' => 'contacted', 'automation_state' => Lead::AUTOMATION_HANDED_OFF, 'handed_off_at' => now()->subHour()]);
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['last_contact_at' => now()])->saveQuietly());

        $this->assertNotContains($lead->uuid, $this->inView('needs_human'));
    }

    // ------------------------------------------------------------ saved views

    public function test_a_person_can_save_the_filters_they_set_and_get_them_back(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/leads/saved-views', [
            'name' => 'ساخنون من تيك توك',
            'filters' => ['temperature' => ['hot'], 'platform' => ['tiktok'], 'password' => 'nope'],
        ])->assertCreated();

        $views = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads/saved-views')->assertOk()->json('data.views');

        $this->assertCount(1, $views);
        $this->assertSame('ساخنون من تيك توك', $views[0]['name']);
        $this->assertSame(['temperature' => ['hot'], 'platform' => ['tiktok']], $views[0]['filters'], 'only filters the list understands are kept');
    }

    public function test_a_view_with_no_filters_is_refused(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/leads/saved-views', ['name' => 'فارغ', 'filters' => ['bogus' => 1]])->assertStatus(422);
    }

    public function test_views_are_personal(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/leads/saved-views', ['name' => 'لي', 'filters' => ['priority' => true]])->assertCreated();
        $other = $this->makeUser($this->t['tenant'], Role::Admin);

        $this->assertSame([], $this->actingAs($other)->getJson('/api/v1/leads/saved-views')->assertOk()->json('data.views'));
    }

    public function test_a_view_can_be_deleted_by_its_owner_only(): void
    {
        $id = $this->actingAs($this->t['admin'])->postJson('/api/v1/leads/saved-views', ['name' => 'مؤقت', 'filters' => ['priority' => true]])->json('data.view.id');
        $other = $this->makeUser($this->t['tenant'], Role::Admin);

        $this->actingAs($other)->deleteJson("/api/v1/leads/saved-views/{$id}")->assertNotFound();
        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/leads/saved-views/{$id}")->assertOk();
        $this->assertSame([], $this->actingAs($this->t['admin'])->getJson('/api/v1/leads/saved-views')->json('data.views'));
    }

    // -------------------------------------------------------------------- SLA

    private function sla(): array
    {
        return $this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard/command')->assertOk()->json('data.sla');
    }

    public function test_the_ring_is_the_share_contacted_within_the_target(): void
    {
        $this->asTenant($this->t['tenant'], fn () => TenantSetting::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->update(['first_contact_target_minutes' => 30]));

        // Two past their target: one kept it, one missed it.
        $kept = $this->lead(['created_at' => now()->subHours(5)]);
        $this->asTenant($this->t['tenant'], fn () => $kept->forceFill(['first_contact_at' => now()->subHours(5)->addMinutes(10), 'status' => 'contacted'])->saveQuietly());
        $this->lead(['created_at' => now()->subHours(5)]);
        // One still inside its target: neither.
        $this->lead();

        $ring = $this->sla()['compliance'];

        $this->assertSame(2, $ring['total']);
        $this->assertSame(1, $ring['within']);
        $this->assertSame(50, $ring['percent']);
        $this->assertSame(1, $this->sla()['breached_first_contact']);
    }

    public function test_it_measures_the_first_automated_and_the_first_human_response(): void
    {
        $lead = $this->lead(['created_at' => now()->subHours(2)]);
        $this->asTenant($this->t['tenant'], function () use ($lead) {
            $m = LeadMessage::create(['lead_id' => $lead->id, 'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp, 'provider' => 'mock', 'message_type' => 'text', 'body' => 'x', 'status' => 'sent']);
            $m->forceFill(['created_at' => now()->subHours(2)->addMinutes(1)])->saveQuietly();
            $a = LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $this->t['admin']->id, 'type' => ActivityType::Called]);
            $a->forceFill(['created_at' => now()->subHours(2)->addMinutes(45)])->saveQuietly();
        });

        $sla = $this->sla();

        $this->assertEqualsWithDelta(60, $sla['first_automated']['median_seconds'], 5);
        $this->assertEqualsWithDelta(2700, $sla['first_human']['median_seconds'], 5);
        $this->assertSame(1, $sla['first_automated']['samples']);
    }

    public function test_it_says_how_long_open_leads_have_sat_in_their_stage(): void
    {
        $lead = $this->lead(['status' => 'contacted']);
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['stage_entered_at' => now()->subDays(4)])->saveQuietly());

        $stages = collect($this->sla()['stages'])->keyBy('status');

        $this->assertEqualsWithDelta(4.0, $stages['contacted']['average_days'], 0.2);
    }

    public function test_moving_a_lead_restarts_its_stage_clock(): void
    {
        $lead = $this->lead(['status' => 'contacted']);
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['stage_entered_at' => now()->subDays(9)])->saveQuietly());

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/status", ['status' => 'qualified'])->assertOk();

        $this->assertTrue($this->asTenant($this->t['tenant'], fn () => $lead->fresh()->stage_entered_at->isToday()));
    }

    public function test_stalled_leads_are_counted_and_listed_with_how_long(): void
    {
        $this->lead(['status' => 'contacted', 'last_contact_at' => now()->subDays(8), 'created_at' => now()->subDays(12)]);

        $sla = $this->sla();

        $this->assertSame(1, $sla['stalled']['count']);
        $this->assertSame(8, $sla['stalled']['leads'][0]['days']);
        $attention = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard/command')->json('data.attention'))->firstWhere('key', 'stalled');
        $this->assertSame(1, $attention['count']);
    }

    public function test_overdue_follow_ups_are_counted(): void
    {
        $this->lead(['status' => 'contacted', 'next_follow_up_at' => now()->subDay()]);

        $this->assertSame(1, $this->sla()['overdue_follow_ups']);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LostReason;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadManagementTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Summit Realty');
    }

    public function test_a_lead_can_be_created_and_the_phone_is_normalised(): void
    {
        $response = $this->actingAs($this->t['admin'])->postJson('/api/v1/leads', [
            'full_name' => 'فهد العتيبي',
            'phone' => '0512345678',
            'city' => 'الرياض',
        ]);

        $response->assertCreated()->assertJsonPath('data.duplicate', false);

        $this->assertDatabaseHas('leads', [
            'full_name' => 'فهد العتيبي',
            'phone_normalized' => '+966512345678',
            'status' => LeadStatus::New->value,
        ]);
    }

    public function test_creating_a_lead_with_an_existing_phone_re_engages_instead_of_duplicating(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/leads', [
            'full_name' => 'فهد العتيبي', 'phone' => '0512345678',
        ])->assertCreated();

        // Same person, different format, different campaign.
        $response = $this->actingAs($this->t['admin'])->postJson('/api/v1/leads', [
            'full_name' => 'فهد العتيبي', 'phone' => '+966512345678',
        ]);

        $response->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, Lead::withoutGlobalScopes()->count());
        $this->assertSame(1, Lead::withoutGlobalScopes()->first()->re_engaged_count);
    }

    public function test_a_lead_needs_a_phone_or_an_email(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/leads', ['full_name' => 'No Contact'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'email']);
    }

    public function test_an_unparseable_phone_is_rejected(): void
    {
        $this->actingAs($this->t['admin'])
            ->postJson('/api/v1/leads', ['full_name' => 'Bad Phone', 'phone' => '12'])
            ->assertStatus(422);
    }

    public function test_marking_a_lead_lost_requires_a_reason(): void
    {
        $lead = $this->makeLead();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/status", ['status' => 'lost'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lost_reason_id');
    }

    public function test_marking_a_lead_lost_with_a_reason_records_the_timestamp_and_activity(): void
    {
        $lead = $this->makeLead();
        $reason = $this->asTenant($this->t['tenant'], fn () => LostReason::where('key', 'budget')->first());

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/status", [
                'status' => 'lost',
                'lost_reason_id' => $reason->uuid,
                'lost_note' => 'Budget too low',
            ])->assertOk()->assertJsonPath('data.lead.status', 'lost');

        $lead->refresh();
        $this->assertNotNull($lead->lost_at);
        $this->assertSame($reason->id, $lead->lost_reason_id);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type' => ActivityType::Lost->value,
        ]);
    }

    public function test_winning_a_lead_clears_the_follow_up_and_stamps_won_at(): void
    {
        $lead = $this->makeLead(['next_follow_up_at' => now()->addDay()]);

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/status", ['status' => 'won'])
            ->assertOk();

        $lead->refresh();
        $this->assertNotNull($lead->won_at);
        $this->assertNull($lead->next_follow_up_at);
    }

    public function test_logging_a_call_moves_a_new_lead_to_contacted_automatically(): void
    {
        $lead = $this->makeLead();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/contact", ['channel' => 'call'])
            ->assertOk()
            ->assertJsonPath('data.lead.status', 'contacted');

        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'called']);
        $this->assertNotNull($lead->fresh()->last_contact_at);
    }

    public function test_opening_whatsapp_is_recorded_as_an_activity(): void
    {
        $lead = $this->makeLead();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/contact", ['channel' => 'whatsapp'])
            ->assertOk();

        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'whatsapp_clicked']);
    }

    public function test_the_api_exposes_a_ready_to_use_whatsapp_number(): void
    {
        $lead = $this->makeLead();

        $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$lead->uuid}")
            ->assertOk()
            ->assertJsonPath('data.lead.whatsapp', '966512345678');
    }

    public function test_a_note_can_be_added_and_appears_on_the_timeline(): void
    {
        $lead = $this->makeLead();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/notes", ['body' => 'العميل طلب جدول الدفعات'])
            ->assertCreated();

        $this->assertDatabaseHas('lead_notes', ['lead_id' => $lead->id]);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'note_added']);
    }

    public function test_a_sales_rep_only_sees_their_own_leads(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $other = $this->makeUser($this->t['tenant'], Role::Sales);

        $mine = $this->makeLead(['assigned_to' => $rep->id, 'phone_normalized' => '+966511111111']);
        $theirs = $this->makeLead(['assigned_to' => $other->id, 'phone_normalized' => '+966522222222']);

        $response = $this->actingAs($rep)->getJson('/api/v1/leads');

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($mine->uuid, $response->json('data.0.id'));

        // And the other rep's lead is not reachable by uuid either.
        $this->actingAs($rep)->getJson("/api/v1/leads/{$theirs->uuid}")->assertForbidden();
    }

    public function test_a_manager_sees_every_lead_in_the_tenant(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->makeLead(['assigned_to' => $rep->id, 'phone_normalized' => '+966511111111']);
        $this->makeLead(['phone_normalized' => '+966522222222']);

        $this->assertSame(2, $this->actingAs($manager)->getJson('/api/v1/leads')->json('meta.total'));
    }

    public function test_a_sales_rep_cannot_reassign_a_lead(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $other = $this->makeUser($this->t['tenant'], Role::Sales);
        $lead = $this->makeLead(['assigned_to' => $rep->id]);

        $this->actingAs($rep)
            ->postJson("/api/v1/leads/{$lead->uuid}/assign", ['assigned_to' => $other->uuid])
            ->assertForbidden();
    }

    public function test_only_an_admin_can_delete_a_lead(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $lead = $this->makeLead();

        $this->actingAs($manager)->deleteJson("/api/v1/leads/{$lead->uuid}")->assertForbidden();
        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/leads/{$lead->uuid}")->assertOk();

        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
    }

    public function test_searching_by_a_local_phone_format_finds_a_lead_stored_in_e164(): void
    {
        $this->makeLead(['full_name' => 'Searchable', 'phone_normalized' => '+966512345678']);

        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads?search=0512345678');

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Searchable', $response->json('data.0.full_name'));
    }

    public function test_leads_can_be_filtered_by_status_and_temperature(): void
    {
        $this->makeLead(['status' => 'won', 'temperature' => 'hot', 'phone_normalized' => '+966511111111']);
        $this->makeLead(['status' => 'new', 'temperature' => 'cold', 'phone_normalized' => '+966522222222']);

        $this->assertSame(1, $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/leads?status[]=won')->json('meta.total'));

        $this->assertSame(1, $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/leads?temperature[]=cold')->json('meta.total'));
    }

    public function test_changing_a_phone_to_one_already_used_in_the_tenant_is_rejected(): void
    {
        $this->makeLead(['phone_normalized' => '+966511111111']);
        $second = $this->makeLead(['phone_normalized' => '+966522222222']);

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/leads/{$second->uuid}", ['phone' => '0511111111'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_lead_can_be_linked_to_a_project(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'Jasmine Villas']));

        $this->actingAs($this->t['admin'])->postJson('/api/v1/leads', [
            'full_name' => 'Project Lead',
            'phone' => '0512345678',
            'project_id' => $project->uuid,
        ])->assertCreated()->assertJsonPath('data.lead.project.name', 'Jasmine Villas');
    }

    public function test_leads_export_streams_a_csv(): void
    {
        $this->makeLead(['full_name' => 'CSV Person']);

        $response = $this->actingAs($this->t['admin'])->get('/api/v1/leads/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('CSV Person', $response->streamedContent());
    }

    private function makeLead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'Test Lead',
            'phone' => '0512345678',
            'phone_normalized' => '+966512345678',
        ], $attributes)));
    }
}

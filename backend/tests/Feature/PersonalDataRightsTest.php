<?php

namespace Tests\Feature;

use App\Domain\Privacy\PersonalData;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadNote;
use App\Models\Suppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A person can be handed everything the company holds about them, or erased from it. Erasure takes the person and
 * keeps the counts; only a company's admin can do either, and never to another company's lead.
 */
class PersonalDataRightsTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha');
        $this->b = $this->provisionTenant('Beta');
    }

    private function person(): Lead
    {
        return $this->asTenant($this->a['tenant'], function () {
            $lead = Lead::create(['full_name' => 'Sara Ali', 'phone' => '0551234567', 'phone_normalized' => '+966551234567', 'email' => 'sara@example.test', 'city' => 'Riyadh', 'platform' => 'meta', 'campaign_name' => 'Launch']);
            LeadNote::create(['lead_id' => $lead->id, 'user_id' => $this->a['admin']->id, 'body' => 'Wants a villa near her mother']);
            $message = new LeadMessage(['lead_id' => $lead->id, 'direction' => 'inbound', 'channel' => 'whatsapp', 'body' => 'My ID is 1010101010', 'status' => 'received']);
            $message->forceFill(['uuid' => (string) str()->uuid()])->save();

            return $lead;
        });
    }

    public function test_the_export_holds_what_the_person_told_and_was_told(): void
    {
        $lead = $this->person();

        $response = $this->actingAs($this->a['admin'])->get("/api/v1/leads/{$lead->uuid}/personal-data")->assertOk();
        $data = json_decode($response->streamedContent(), true);

        $this->assertSame('Sara Ali', $data['person']['name']);
        $this->assertSame('+966551234567', $data['person']['phone']);
        $this->assertSame('My ID is 1010101010', $data['messages'][0]['body']);
        $this->assertArrayNotHasKey('provider_payload', $data['person']);
        $this->assertTrue($this->asTenant($this->a['tenant'], fn () => AuditLog::where('action', 'data.exported')->exists()));
    }

    public function test_erasure_removes_the_person_everywhere_and_keeps_the_row_for_the_counts(): void
    {
        $lead = $this->person();

        $this->actingAs($this->a['admin'])->postJson("/api/v1/leads/{$lead->uuid}/erase", ['confirm' => 'Sara Ali'])
            ->assertOk()->assertJsonPath('data.suppressed', ['phone', 'email']);

        $row = DB::table('leads')->where('id', $lead->id)->first();
        $this->assertSame(PersonalData::ERASED_NAME, $row->full_name);
        $this->assertNull($row->phone);
        $this->assertNull($row->phone_normalized);
        $this->assertNull($row->email);
        $this->assertNull($row->city);
        $this->assertNotNull($row->erased_at);
        $this->assertNotNull($row->deleted_at);
        // The counts keep their meaning.
        $this->assertSame('meta', $row->platform);
        $this->assertSame('Launch', $row->campaign_name);

        $this->assertNull(DB::table('lead_messages')->where('lead_id', $lead->id)->value('body'));
        $this->assertSame('—', DB::table('lead_notes')->where('lead_id', $lead->id)->value('body'));

        // "Delete my data" also means "don't message me again".
        $this->assertSame(2, DB::table('suppressions')->where('tenant_id', $this->a['tenant']->id)->count());

        // The audit says it happened, not who it was.
        $audit = $this->asTenant($this->a['tenant'], fn () => AuditLog::where('action', 'lead.erased')->first());
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('Sara', json_encode($audit->toArray(), JSON_UNESCAPED_UNICODE));

        $this->actingAs($this->a['admin'])->getJson("/api/v1/leads/{$lead->uuid}")->assertNotFound();
    }

    public function test_erasure_needs_the_name_typed_back(): void
    {
        $lead = $this->person();

        $this->actingAs($this->a['admin'])->postJson("/api/v1/leads/{$lead->uuid}/erase", ['confirm' => 'Sara'])->assertUnprocessable();
        $this->assertSame('Sara Ali', DB::table('leads')->where('id', $lead->id)->value('full_name'));
    }

    public function test_suppression_can_be_skipped(): void
    {
        $lead = $this->person();

        $this->actingAs($this->a['admin'])->postJson("/api/v1/leads/{$lead->uuid}/erase", ['confirm' => 'Sara Ali', 'suppress' => false])->assertOk();
        $this->assertSame(0, DB::table('suppressions')->count());
    }

    public function test_only_an_admin_of_the_same_company_may(): void
    {
        $lead = $this->person();
        $manager = $this->makeUser($this->a['tenant'], Role::Manager);
        $sales = $this->makeUser($this->a['tenant'], Role::Sales);

        $this->actingAs($manager)->get("/api/v1/leads/{$lead->uuid}/personal-data")->assertForbidden();
        $this->actingAs($manager)->postJson("/api/v1/leads/{$lead->uuid}/erase", ['confirm' => 'Sara Ali'])->assertForbidden();
        $this->actingAs($sales)->postJson("/api/v1/leads/{$lead->uuid}/erase", ['confirm' => 'Sara Ali'])->assertForbidden();

        $this->actingAs($this->b['admin'])->get("/api/v1/leads/{$lead->uuid}/personal-data")->assertNotFound();
        $this->actingAs($this->b['admin'])->postJson("/api/v1/leads/{$lead->uuid}/erase", ['confirm' => 'Sara Ali'])->assertNotFound();

        $this->assertSame('Sara Ali', DB::table('leads')->where('id', $lead->id)->value('full_name'));
        $this->assertSame(0, Suppression::withoutGlobalScopes()->count());
    }
}

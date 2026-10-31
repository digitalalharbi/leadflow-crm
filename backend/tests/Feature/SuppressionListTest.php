<?php

namespace Tests\Feature;

use App\Domain\Privacy\SuppressionList;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Suppression;
use App\Services\Whatsapp\InboundWhatsappProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Someone who asked to stop is not messaged again — not when they fill in a second form, not from another lead with
 * the same number — and one company's list never touches another company's leads.
 */
class SuppressionListTest extends TestCase
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

    public function test_stop_words_are_recognised_only_as_the_whole_message(): void
    {
        $list = app(SuppressionList::class);

        foreach (['STOP', 'إيقاف', ' الغاء الاشتراك ', 'لا ترسلوا!', 'Unsubscribe.'] as $stop) {
            $this->assertTrue($list->isStopMessage($stop), $stop);
        }

        foreach (['لا توقف الرسائل أرجوك', 'متى الموعد؟', 'stop by the office tomorrow', '', null] as $not) {
            $this->assertFalse($list->isStopMessage($not), (string) $not);
        }
    }

    public function test_an_entry_opts_out_existing_leads_with_that_number_and_every_new_one(): void
    {
        $existing = $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'A', 'phone' => '0551234567', 'phone_normalized' => '+966551234567']));
        $otherCompany = $this->asTenant($this->b['tenant'], fn () => Lead::create(['full_name' => 'B', 'phone' => '0551234567', 'phone_normalized' => '+966551234567']));

        $this->actingAs($this->a['admin'])->postJson('/api/v1/settings/suppressions', ['channel' => 'phone', 'value' => '+966 55 123 4567'])->assertCreated();

        $this->assertNotNull($existing->fresh()->whatsapp_opt_out_at);
        $this->assertNull($otherCompany->fresh()->whatsapp_opt_out_at, 'Another company’s list must not touch this lead.');

        // A second form from the same person arrives already opted out.
        $this->actingAs($this->a['admin'])->postJson('/api/v1/leads', ['full_name' => 'A again', 'phone' => '0551234567', 'email' => 'new@person.test'])->assertSuccessful();
        $this->assertNotNull($this->asTenant($this->a['tenant'], fn () => Lead::where('email', 'new@person.test')->first()?->whatsapp_opt_out_at ?? Lead::where('phone_normalized', '+966551234567')->latest('id')->first()->whatsapp_opt_out_at));
    }

    public function test_an_email_entry_blocks_that_address(): void
    {
        $this->actingAs($this->a['admin'])->postJson('/api/v1/settings/suppressions', ['channel' => 'email', 'value' => 'Sara@Example-Mail.com'])->assertCreated();

        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'S', 'email' => 'sara@example-mail.com']));

        $this->assertNotNull($lead->email_opt_out_at);
        $this->assertFalse($lead->acceptsEmail());
    }

    public function test_removing_an_entry_does_not_opt_anyone_back_in(): void
    {
        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'A', 'phone' => '0559998888', 'phone_normalized' => '+966559998888']));
        $id = $this->actingAs($this->a['admin'])->postJson('/api/v1/settings/suppressions', ['channel' => 'phone', 'value' => '0559998888'])->json('data.id');

        $this->actingAs($this->a['admin'])->deleteJson("/api/v1/settings/suppressions/{$id}")->assertOk();

        $this->assertNotNull($lead->fresh()->whatsapp_opt_out_at);
    }

    public function test_another_company_cannot_see_or_remove_my_entries_and_a_rep_cannot_manage_them(): void
    {
        $id = $this->actingAs($this->a['admin'])->postJson('/api/v1/settings/suppressions', ['channel' => 'phone', 'value' => '0551110000'])->json('data.id');

        $this->assertSame([], $this->actingAs($this->b['admin'])->getJson('/api/v1/settings/suppressions')->json('data.data'));
        $this->actingAs($this->b['admin'])->deleteJson("/api/v1/settings/suppressions/{$id}")->assertNotFound();
        $this->actingAs($this->makeUser($this->a['tenant']))->getJson('/api/v1/settings/suppressions')->assertForbidden();
        $this->assertSame(1, Suppression::withoutGlobalScopes()->count());
    }

    public function test_a_stop_reply_on_whatsapp_opts_the_number_out_everywhere_in_that_company(): void
    {
        $whatsapp = $this->asTenant($this->a['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp', 'name' => 'WhatsApp Business', 'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000111'],
            'credentials' => ['access_token' => 'token', 'verify_token' => 'v', 'app_secret' => 's'], 'is_active' => true,
        ]));
        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::create(['full_name' => 'Noor', 'phone' => '0551234567', 'phone_normalized' => '+966551234567']));

        $this->asTenant($this->a['tenant'], fn () => app(InboundWhatsappProcessor::class)->process($whatsapp, [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => '1555000111'],
                'contacts' => [['wa_id' => '966551234567']],
                'messages' => [['from' => '966551234567', 'id' => 'wamid.stop.1', 'type' => 'text', 'text' => ['body' => 'إيقاف'], 'timestamp' => (string) time()]],
            ]]]]],
        ]));

        $this->assertNotNull($lead->fresh()->whatsapp_opt_out_at);
        $this->assertFalse($lead->fresh()->acceptsWhatsapp());
        $this->assertSame('opt_out', Suppression::withoutGlobalScopes()->where('tenant_id', $this->a['tenant']->id)->value('reason'));
    }

    public function test_garbage_is_refused(): void
    {
        $this->actingAs($this->a['admin'])->postJson('/api/v1/settings/suppressions', ['channel' => 'email', 'value' => 'not-an-email'])->assertStatus(422);
    }
}

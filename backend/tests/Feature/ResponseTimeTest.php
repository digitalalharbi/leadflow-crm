<?php

namespace Tests\Feature;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Nobody has called these yet."
 *
 * The one panel on the dashboard that creates work rather than reporting on it.
 * An ad lead answered within half an hour is a conversation; the same lead
 * answered tomorrow is a cold call.
 */
class ResponseTimeTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Response Co');
    }

    public function test_a_lead_nobody_has_touched_appears_after_the_target(): void
    {
        $this->lead('متأخر', createdMinutesAgo: 45);
        $this->lead('حديث', createdMinutesAgo: 5);

        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/dashboard');

        $response->assertSuccessful()
            ->assertJsonPath('data.today.awaiting_first_contact', 1)
            ->assertJsonPath('data.today.first_contact_target_minutes', 30);

        $names = collect($response->json('data.awaiting_contact_leads'))->pluck('full_name');
        $this->assertTrue($names->contains('متأخر'));
        $this->assertFalse($names->contains('حديث'));
    }

    public function test_a_contacted_lead_leaves_the_panel(): void
    {
        $lead = $this->lead('تم التواصل معه', createdMinutesAgo: 90);

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.today.awaiting_first_contact', 1);

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/contact", ['channel' => 'call'])
            ->assertSuccessful();

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.today.awaiting_first_contact', 0);

        $this->assertNotNull($lead->refresh()->first_contact_at);
    }

    public function test_the_target_is_a_workspace_setting(): void
    {
        $this->lead('بعد عشر دقائق', createdMinutesAgo: 12);

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.today.awaiting_first_contact', 0);

        $this->actingAs($this->t['admin'])
            ->patchJson('/api/v1/settings', ['first_contact_target_minutes' => 10])
            ->assertSuccessful();

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.today.awaiting_first_contact', 1)
            ->assertJsonPath('data.today.first_contact_target_minutes', 10);
    }

    public function test_a_whatsapp_message_counts_as_first_contact(): void
    {
        $lead = $this->lead('عبر واتساب', createdMinutesAgo: 60);

        $this->connectWhatsapp();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$lead->uuid}/messages", ['body' => 'مرحبًا'])
            ->assertStatus(201);

        $this->assertNotNull($lead->refresh()->first_contact_at);

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.today.awaiting_first_contact', 0);
    }

    public function test_a_lead_already_in_progress_is_not_counted_as_neglected(): void
    {
        /*
         * Someone moved it along without logging a contact. It is not untouched,
         * and dragging it back into the panel would train people to ignore the
         * panel.
         *
         * This test used to force status and timestamp apart with saveQuietly,
         * and so asserted the dashboard's behaviour against a state the product
         * can no longer hold. Moving the lead the ordinary way now stamps the
         * contact time with it, which is the point: the panel is right for the
         * same reason the badge is.
         */
        $lead = $this->lead('قيد العمل', createdMinutesAgo: 120);
        $lead->update(['status' => 'contacted']);

        $this->assertNotNull($lead->fresh()->first_contact_at);

        $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.today.awaiting_first_contact', 0);
    }

    public function test_the_dashboard_number_and_the_list_behind_it_agree(): void
    {
        // The panel links to the leads list with this filter; if the two
        // disagreed, the number would send people to the wrong work.
        $this->lead('متأخر أول', createdMinutesAgo: 60);
        $this->lead('متأخر ثانٍ', createdMinutesAgo: 90);
        $this->lead('حديث', createdMinutesAgo: 2);

        $count = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/dashboard')
            ->json('data.today.awaiting_first_contact');

        $listed = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/leads?awaiting_contact=1')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame(2, $count);
        $this->assertCount(2, $listed);
        $this->assertNotContains('حديث', array_column($listed, 'full_name'));
    }

    private function lead(string $name, int $createdMinutesAgo): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($name, $createdMinutesAgo) {
            $lead = Lead::create([
                'full_name' => $name,
                'phone' => '05'.random_int(10000000, 99999999),
                'phone_normalized' => '+9665'.random_int(10000000, 99999999),
            ]);

            $lead->forceFill(['created_at' => now()->subMinutes($createdMinutesAgo)])->saveQuietly();

            return $lead->refresh();
        });
    }

    private function connectWhatsapp(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => IntegrationProvider::Whatsapp,
            'name' => 'WhatsApp Business',
            'status' => Integration::CONFIGURATION_REQUIRED,
            'config' => ['phone_number_id' => '1777000111'],
            'credentials' => ['verify_token' => 'verify', 'app_secret' => 'secret-secret-secret'],
            'is_active' => true,
        ]));
    }
}

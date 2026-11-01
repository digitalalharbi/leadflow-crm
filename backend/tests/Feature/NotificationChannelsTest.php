<?php

namespace Tests\Feature;

use App\Domain\Notifications\NotificationPrefs;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\Channels\ProviderMailChannel;
use App\Notifications\Channels\TenantDatabaseChannel;
use App\Notifications\ImportantNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each kind of alert can be kept out of the bell, out of email, or both — per person. The mode still decides when an
 * email goes; the channel switch decides whether it goes at all, and a summary is never emptied by the bell switch.
 */
class NotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Channels Co');
        $this->rep = $this->makeUser($this->t['tenant'], Role::Sales);
    }

    private function via(string $kind = 'hot_lead'): array
    {
        return (new ImportantNotification($kind, 't', 'b', null, null, 'warning'))->via($this->rep->fresh());
    }

    public function test_both_channels_are_on_until_the_person_says_otherwise(): void
    {
        $this->assertSame(['in_app' => true, 'email' => true], NotificationPrefs::for($this->rep)['channels']['hot_lead']);
        $this->assertContains(TenantDatabaseChannel::class, $this->via());
        $this->assertContains(ProviderMailChannel::class, $this->via());
    }

    public function test_email_off_keeps_the_bell_and_bell_off_keeps_the_email(): void
    {
        NotificationPrefs::save($this->rep, ['channels' => ['hot_lead' => ['email' => false]]]);
        $this->assertSame([TenantDatabaseChannel::class], array_values(array_diff($this->via(), ['App\\Notifications\\Channels\\StaffWhatsappChannel'])));

        NotificationPrefs::save($this->rep->fresh(), ['channels' => ['hot_lead' => ['email' => true, 'in_app' => false]]]);
        $this->assertNotContains(TenantDatabaseChannel::class, $this->via());
        $this->assertContains(ProviderMailChannel::class, $this->via());

        // Other kinds are untouched.
        $this->assertContains(TenantDatabaseChannel::class, $this->via('sla_breach'));
    }

    public function test_a_kind_meant_for_the_daily_summary_is_still_recorded_with_the_bell_off(): void
    {
        NotificationPrefs::save($this->rep, ['alerts' => ['hot_lead' => 'daily'], 'channels' => ['hot_lead' => ['in_app' => false]]]);
        $this->assertContains(TenantDatabaseChannel::class, $this->via());

        // …unless its email is off too: then it reaches nobody, as asked.
        NotificationPrefs::save($this->rep->fresh(), ['channels' => ['hot_lead' => ['email' => false]]]);
        $this->assertSame([], array_values(array_diff($this->via(), ['App\\Notifications\\Channels\\StaffWhatsappChannel'])));
    }

    public function test_the_api_saves_channels_for_known_kinds_only(): void
    {
        $prefs = $this->actingAs($this->rep)->patchJson('/api/v1/auth/notification-preferences', [
            'channels' => ['hot_lead' => ['in_app' => false, 'email' => true, 'sms' => true], 'made_up' => ['email' => false]],
        ])->assertOk()->json('data.channels');

        $this->assertSame(['in_app' => false, 'email' => true], $prefs['hot_lead']);
        $this->assertArrayNotHasKey('made_up', $prefs);
        $this->assertArrayNotHasKey('made_up', (array) ($this->rep->fresh()->notification_prefs['channels'] ?? []));

        $this->actingAs($this->rep)->patchJson('/api/v1/auth/notification-preferences', ['channels' => ['hot_lead' => ['email' => 'maybe']]])->assertUnprocessable();
    }
}

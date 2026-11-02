<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\Lead;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The first real client, one person's path from a new company to a sale in a report, through nothing but what the product
 * exposes: every step starts from what the one before it returned, and every token comes out of the email that carried it.
 * Mail is logged here, so a real inbox is what this does not prove.
 */
class FirstRealClientFlowTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function as(string $token)
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders($this->bearer($token));
    }

    private function linkIn(string $email, string $path): string
    {
        $html = (string) EmailDelivery::withoutGlobalScopes()->where('to_email', $email)->latest('id')->value('html');
        $this->assertSame(1, preg_match('#/'.$path.'/([A-Za-z0-9]{48})#', $html), "No /{$path}/ link reached {$email}.");
        preg_match('#/'.$path.'/([A-Za-z0-9]{48})#', $html, $m);

        return $m[1];
    }

    public function test_a_new_company_reaches_a_sale_in_a_report(): void
    {
        $this->fakeMailer();

        // Create Company + Create Admin
        $platform = $this->makePlatformOwner()->createToken('platform', ['platform'], now()->addHour())->plainTextToken;
        $this->as($platform)->postJson('/api/v1/super-admin/tenants', ['company_name' => 'شركة الرواق', 'admin_name' => 'نورة', 'admin_email' => 'noura@rawaq.test'])->assertCreated();
        $tenant = Tenant::where('name', 'شركة الرواق')->firstOrFail();

        // Login: the owner chooses their own password from the emailed link, and can then sign in with it
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/password/reset', ['token' => $this->linkIn('noura@rawaq.test', 'set-password'), 'password' => 'a-strong-pass', 'password_confirmation' => 'a-strong-pass'])->assertOk();
        $this->assertNotNull(User::where('email', 'noura@rawaq.test')->first()->email_verified_at);
        $owner = $this->postJson('/api/v1/auth/login', ['email' => 'noura@rawaq.test', 'password' => 'a-strong-pass'])->assertOk()->json('data.token');

        // Create Project
        $project = $this->as($owner)->postJson('/api/v1/projects', ['name' => 'مشروع النرجس'])->assertCreated()->json('data.project.id');

        // Invite Team, Configure Roles
        $this->as($owner)->postJson('/api/v1/invitations', ['name' => 'سلطان', 'email' => 'sultan@rawaq.test', 'role' => 'sales', 'project_ids' => [$project], 'all_projects' => false])->assertCreated();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $rep = $this->postJson('/api/v1/public/invitations/'.$this->linkIn('sultan@rawaq.test', 'i').'/accept', ['name' => 'سلطان', 'password' => 'another-pass', 'password_confirmation' => 'another-pass'])
            ->assertCreated()->assertJsonPath('data.user.role', 'sales')->json('data.token');

        // Add Lead Source + Publish Landing Page
        $form = $this->as($owner)->postJson('/api/v1/tracking/forms', [
            'name' => 'صفحة النرجس', 'project_id' => $project, 'slug' => 'narjis',
            'headline' => 'النرجس', 'consent_required' => false,
            'fields' => [['key' => 'name', 'type' => 'text', 'label' => 'الاسم', 'required' => true, 'mapping' => 'lead.full_name'], ['key' => 'mobile', 'type' => 'phone', 'label' => 'الجوال', 'required' => true, 'mapping' => 'lead.phone']],
            'page' => ['template' => 'real_estate_lead'],
        ])->assertCreated()->json('data.form');
        $this->as($owner)->postJson("/api/v1/tracking/forms/{$form['id']}/publish")->assertOk();

        // Receive Lead + Attribution: a stranger, from an ad, with no session
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $visit = $this->getJson('/api/v1/public/landing/narjis?ScCid=snap-click&utm_source=snapchat&utm_campaign=narjis-launch')->assertOk()->json('data.tracking');
        $this->postJson('/api/v1/public/landing/narjis/submit', ['submission_id' => (string) str()->uuid(), 'answers' => ['name' => 'خالد العتيبي', 'mobile' => '0551234567'], 'visitor_id' => $visit['visitor_id'], 'session_id' => $visit['session_id'], 'utm_source' => 'snapchat', 'utm_campaign' => 'narjis-launch'])->assertSuccessful();

        $lead = $this->asTenant($tenant, fn () => Lead::where('phone_normalized', '+966551234567')->firstOrFail());
        $this->assertSame($project, $lead->project?->uuid);
        $this->assertSame(['sccid' => 'snap-click'], $lead->click_ids['first'] ?? null);

        // Assignment: the company did nothing special, and its one salesperson on the project has it
        $this->assertSame('sultan@rawaq.test', User::find($lead->assigned_to)?->email);

        // Work Now
        $next = $this->as($rep)->getJson('/api/v1/work/next')->assertOk()->json('data');
        $this->assertSame($lead->uuid, $next['lead']['id'] ?? null);

        // Follow-up
        $this->as($rep)->postJson('/api/v1/follow-ups', ['lead_id' => $lead->uuid, 'due_at' => now()->addHour()->toIso8601String(), 'note' => 'اتصال أول', 'type' => 'call'])->assertCreated();

        // Appointment + Visit + Reservation
        $appointment = $this->as($rep)->postJson('/api/v1/appointments', ['lead_id' => $lead->uuid, 'scheduled_at' => now()->addDay()->toIso8601String()])->assertCreated()->json('data.appointment.id');
        $this->as($rep)->postJson("/api/v1/appointments/{$appointment}/outcome", ['outcome' => 'reservation'])->assertOk();
        $reservation = $this->asTenant($tenant, fn () => Reservation::where('lead_id', $lead->id)->firstOrFail());

        // Sale + Revenue
        $this->as($rep)->postJson("/api/v1/reservations/{$reservation->uuid}/convert", ['deal_value' => 750000])->assertOk();
        $this->assertSame(75000000, (int) $lead->fresh()->deal_value_minor);
        $this->assertSame('won', $this->asTenant($tenant, fn () => $lead->fresh()->status->value));

        // Report: the owner sees it, by the campaign the ad said
        $rows = collect($this->as($owner)->getJson('/api/v1/reports/campaign-quality')->assertOk()->json('data.rows'));
        $this->assertEquals(750000.0, $rows->sum('revenue'), 'The sale is not in the report.');
        $this->assertSame(1, $this->as($owner)->getJson('/api/v1/dashboard')->assertOk()->json('data.summary.won'));
        $share = $this->as($owner)->postJson('/api/v1/report-shares', ['name' => 'تقرير النرجس', 'template' => 'executive', 'project_id' => $project])->assertCreated()->json('data.share');
        $this->assertNotEmpty($share['url']);
    }
}

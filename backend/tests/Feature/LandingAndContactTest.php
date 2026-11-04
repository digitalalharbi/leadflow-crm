<?php

namespace Tests\Feature;

use App\Domain\Tracking\Forms\LandingPageConfig;
use App\Enums\Role;
use App\Models\EmailDelivery;
use App\Models\LandingForm;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingAndContactTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha', ['subdomain' => 'alpha-co']);
    }

    public function test_a_page_keeps_its_section_order_and_refuses_anything_outside_the_closed_list(): void
    {
        $page = LandingPageConfig::normalize(['sections' => [['key' => 'financing', 'visible' => false], ['key' => 'script'], ['key' => 'gallery']], 'gallery' => ['https://x.test/a.jpg', 'javascript:alert(1)'], 'financing_note' => '<script>x</script>تمويل']);

        $this->assertSame(['financing', 'gallery', 'benefits', 'location'], array_column($page['sections'], 'key'));
        $this->assertFalse($page['sections'][0]['visible']);
        $this->assertNotContains('javascript:alert(1)', $page['gallery']);
        $this->assertStringNotContainsString('<script>', (string) $page['financing_note']);
    }

    public function test_the_page_list_counts_only_the_leads_this_person_may_see(): void
    {
        [$form, $project] = $this->asTenant($this->a['tenant'], function () {
            $project = Project::create(['name' => 'Counted']);
            $elsewhere = Project::create(['name' => 'Elsewhere']);
            $form = LandingForm::create(['name' => 'P', 'project_id' => $project->id, 'status' => 'draft', 'fields' => [], 'consent_required' => false]);
            Lead::create(['full_name' => 'Here', 'phone' => '0551234567', 'project_id' => $project->id])->forceFill(['landing_form_id' => $form->id])->saveQuietly();
            Lead::create(['full_name' => 'There', 'phone' => '0551234568', 'project_id' => $elsewhere->id])->forceFill(['landing_form_id' => $form->id])->saveQuietly();

            return [$form, $project];
        });

        $rows = collect($this->actingAs($this->a['admin'])->getJson('/api/v1/tracking/forms')->assertOk()->json('data.forms'))->keyBy('id');
        $this->assertSame(2, $rows[$form->uuid]['leads_count']);

        $limited = $this->makeUser($this->a['tenant'], Role::Manager);
        $limited->forceFill(['all_projects' => false])->save();
        $limited->projects()->sync([$project->id]);
        $this->app['auth']->forgetGuards();
        $seen = collect($this->actingAs($limited)->getJson('/api/v1/tracking/forms')->assertOk()->json('data.forms'))->keyBy('id');
        $this->assertSame(1, $seen[$form->uuid]['leads_count']);
    }

    public function test_submissions_show_this_pages_leads_within_what_the_person_may_see(): void
    {
        [$mine, $form, $lead] = $this->asTenant($this->a['tenant'], function () {
            $mine = Project::create(['name' => 'Mine']);
            $form = LandingForm::create(['name' => 'P', 'project_id' => $mine->id, 'status' => 'draft', 'fields' => [], 'consent_required' => false]);
            $lead = Lead::create(['full_name' => 'From page', 'phone' => '0551234567', 'project_id' => $mine->id]);
            $lead->forceFill(['landing_form_id' => $form->id])->saveQuietly();

            return [$mine, $form, $lead];
        });

        $this->actingAs($this->a['admin'])->getJson("/api/v1/tracking/forms/{$form->uuid}/submissions")->assertOk()->assertJsonPath('data.submissions.0.id', $lead->uuid);

        $other = $this->asTenant($this->a['tenant'], fn () => Project::create(['name' => 'Other']));
        $limited = $this->makeUser($this->a['tenant'], Role::Manager);
        $limited->forceFill(['all_projects' => false])->save();
        $limited->projects()->sync([$other->id]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($limited)->getJson("/api/v1/tracking/forms/{$form->uuid}/submissions")->assertNotFound();
        $this->assertNotNull($mine);
    }

    public function test_a_demo_request_becomes_a_lead_in_mohtams_workspace_with_consent_only(): void
    {
        config(['platform.marketing_workspace' => 'alpha-co']);
        $this->getJson('/api/v1/public/contact')->assertOk()->assertJsonPath('data.form', true);

        $this->postJson('/api/v1/public/contact', ['kind' => 'demo', 'name' => 'زائر', 'phone' => '0551234567'])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson('/api/v1/public/contact', ['kind' => 'demo', 'name' => 'زائر', 'phone' => '0551234567', 'consent' => true, 'website' => 'spam'])->assertUnprocessable();
        $this->postJson('/api/v1/public/contact', ['kind' => 'demo', 'name' => 'زائر', 'phone' => '0551234567', 'consent' => true, 'utm_campaign' => 'launch'])->assertOk();

        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::where('phone_normalized', '+966551234567')->sole());
        $this->assertSame('marketing_site', $lead->source);
        $this->assertContains('demo_request', (array) $lead->tags);
    }

    public function test_with_no_workspace_named_the_form_is_not_offered(): void
    {
        config(['platform.marketing_workspace' => null]);
        $this->getJson('/api/v1/public/contact')->assertOk()->assertJsonPath('data.form', false);
        $this->postJson('/api/v1/public/contact', ['kind' => 'demo', 'name' => 'x', 'phone' => '0551234567', 'consent' => true])->assertNotFound();
    }

    public function test_the_provider_says_delivered_or_bounced_and_nothing_assumes_it(): void
    {
        config(['services.email_inbound.secret' => 's3cret']);
        $row = $this->asTenant($this->a['tenant'], fn () => EmailDelivery::create(['kind' => 'alert', 'to_email' => 'a@x.test', 'subject' => 'S', 'html' => 'H', 'provider' => 'resend', 'status' => EmailDelivery::SENT]));

        $this->postJson('/api/v1/integrations/email/inbound/s3cret', ['type' => 'email.delivered', 'data' => ['to' => ['a@x.test']]])->assertOk();
        $this->assertSame(EmailDelivery::DELIVERED, $row->fresh()->status);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LandingForm;
use App\Models\Lead;
use App\Models\Project;
use App\Models\TrackingDestination;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inside each project, one place that answers "where do our customers come from, and is the tracking sound?": the links, the
 * landing forms, the funnel, the sources, the campaigns, what just happened, who gets the credit, and what is broken.
 */
class ProjectAcquisitionTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $rival;

    private Project $project;

    private Project $other;

    private TrackingLink $meta;

    private TrackingLink $qr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Acquire Co');
        $this->rival = $this->provisionTenant('Rival Acquire Co');
        [$this->project, $this->other] = $this->asTenant($this->t['tenant'], fn () => [Project::create(['name' => 'النرجس']), Project::create(['name' => 'الياسمين'])]);

        $form = $this->asTenant($this->t['tenant'], fn () => LandingForm::create(['project_id' => $this->project->id, 'name' => 'نموذج النرجس', 'status' => 'published', 'headline' => 'س', 'fields' => []]));
        $this->meta = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create(['token' => 'acqm'.random_int(100000, 999999), 'name' => 'إعلان ميتا', 'channel' => 'meta', 'mode' => 'landing', 'landing_form_id' => $form->id, 'project_id' => $this->project->id, 'utm_source' => 'meta', 'utm_medium' => 'paid', 'utm_campaign' => 'oct']));
        $this->qr = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create(['token' => 'acqq'.random_int(100000, 999999), 'name' => 'ملصق المعرض', 'channel' => 'qr', 'project_id' => $this->project->id, 'destination_url' => 'https://x.example/']));
        $this->asTenant($this->t['tenant'], fn () => TrackingLink::create(['token' => 'acqo'.random_int(100000, 999999), 'name' => 'رابط مشروع آخر', 'channel' => 'meta', 'project_id' => $this->other->id]));

        $this->asTenant($this->t['tenant'], function () {
            foreach ([['click', $this->meta], ['click', $this->meta], ['visit', $this->meta], ['form_submit', $this->meta], ['click', $this->qr]] as $i => [$type, $link]) {
                TrackingEvent::create(['tracking_link_id' => $link->id, 'event_type' => $type, 'visitor_id' => 'v_'.str_pad((string) $i, 20, '0'), 'occurred_at' => now()->subHours($i + 1), 'project_id' => $this->project->id, 'event_id' => (string) str()->uuid()]);
            }
            $mk = function (array $attributes) {
                $lead = Lead::create(['full_name' => 'عميل', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'new', 'project_id' => $this->project->id]);
                if (($attributes['status'] ?? 'new') !== 'new') {
                    $attributes += ['first_contact_at' => now()->subDay(), 'last_contact_at' => now()->subDay()];
                }
                $lead->forceFill($attributes + ['created_at' => now()->subDay()])->saveQuietly();
            };
            $mk(['first_touch_link_id' => $this->meta->id, 'last_touch_link_id' => $this->meta->id, 'status' => 'won', 'won_at' => now(), 'deal_value_minor' => 50000000]);
            $mk(['first_touch_link_id' => $this->meta->id, 'last_touch_link_id' => $this->qr->id]);
            $mk(['first_touch_link_id' => $this->qr->id, 'last_touch_link_id' => $this->qr->id]);
            $mk([]);      // arrived with no trail at all
        });
    }

    private function page(?array $as = null, ?Project $project = null)
    {
        return $this->actingAs(($as ?? $this->t)['admin'] ?? $as)->getJson('/api/v1/projects/'.($project ?? $this->project)->uuid.'/acquisition?days=30');
    }

    public function test_the_project_page_answers_where_customers_come_from(): void
    {
        $data = $this->page()->assertOk()->json('data');

        $this->assertSame('النرجس', $data['project']['name']);
        $this->assertEqualsCanonicalizing(['إعلان ميتا', 'ملصق المعرض'], array_column($data['links'], 'name'), 'only this project\'s links');
        $metaRow = collect($data['links'])->firstWhere('name', 'إعلان ميتا');
        $this->assertSame(2, $metaRow['stats']['leads']);
        $this->assertSame(1, $metaRow['stats']['won']);
        $this->assertContains($metaRow['health'], ['healthy', 'warning', 'critical']);

        $this->assertSame('نموذج النرجس', $data['forms'][0]['name']);
        $this->assertSame(1, $data['forms'][0]['submissions']);
        $this->assertSame(1, $data['forms'][0]['links_count']);

        $funnel = collect($data['funnel']);
        $this->assertSame(3, $funnel->firstWhere('key', 'click')['count']);
        $this->assertSame(1, $funnel->firstWhere('key', 'won')['count']);
    }

    public function test_the_sources_name_the_channels_and_the_leads_nobody_can_place(): void
    {
        $data = $this->page()->assertOk()->json('data');

        $channels = collect($data['sources']['channels'])->keyBy('channel');
        $this->assertSame(2, $channels['meta']['leads']);
        $this->assertSame(1, $channels['qr']['leads']);
        $this->assertSame(1, $data['sources']['unattributed'], 'one lead arrived with no trail, and that is said, not hidden');
    }

    public function test_first_touch_and_last_touch_can_disagree_and_both_are_shown(): void
    {
        $attr = $this->page()->assertOk()->json('data.attribution');

        $first = collect($attr['first']['rows'])->keyBy('key');
        $last = collect($attr['last']['rows'])->keyBy('key');
        $this->assertEquals(2, $first['meta']['leads'] ?? 0);
        $this->assertEquals(1, $last['meta']['leads'] ?? 0, 'one of the two Meta leads was last touched by the poster');
    }

    public function test_what_just_happened_is_listed_in_the_marketers_words(): void
    {
        $events = collect($this->page()->assertOk()->json('data.events'));

        $this->assertNotEmpty($events);
        $this->assertContains('landing_viewed', $events->pluck('event')->all());
        $this->assertContains('form_submitted', $events->pluck('event')->all());
        $this->assertSame('إعلان ميتا', $events->firstWhere('event', 'form_submitted')['link']);
    }

    public function test_the_diagnostics_count_the_links_by_health_and_name_what_is_wrong(): void
    {
        $this->asTenant($this->t['tenant'], fn () => TrackingDestination::create(['kind' => 'ga4', 'name' => 'GA4', 'is_enabled' => true, 'config' => ['measurement_id' => 'G-ABC123DEF4'], 'credentials' => [], 'send_value' => true, 'hash_identifiers' => true, 'consent_mode' => 'required']));

        $d = $this->page()->assertOk()->json('data.diagnostics');

        $this->assertSame(2, array_sum($d['links']), 'both of this project\'s links are judged');
        $this->assertArrayHasKey('issues', $d);
        $this->assertSame('browser_only', collect($d['destinations'])->firstWhere('kind', 'ga4')['readiness'], 'the tag works, the server side is waiting for its secret');
    }

    // ---------------------------------------------------------------- whose project

    public function test_another_workspaces_project_is_not_here(): void
    {
        $theirs = $this->asTenant($this->rival['tenant'], fn () => Project::create(['name' => 'مشروعهم']));

        $this->page(null, $theirs)->assertNotFound();
    }

    public function test_a_project_manager_sees_only_the_projects_they_were_given(): void
    {
        $pm = $this->makeUser($this->t['tenant'], Role::ProjectManager);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/users/{$pm->uuid}", ['project_ids' => [$this->other->uuid]])->assertOk();

        $this->actingAs($pm->fresh())->getJson('/api/v1/projects/'.$this->project->uuid.'/acquisition')->assertNotFound();
    }

    public function test_a_salesperson_does_not_reach_it(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/projects/'.$this->project->uuid.'/acquisition')->assertForbidden();
    }

    public function test_another_workspaces_events_never_appear_in_this_projects_page(): void
    {
        $this->asTenant($this->rival['tenant'], fn () => TrackingEvent::create(['tracking_link_id' => $this->meta->id, 'event_type' => 'click', 'visitor_id' => 'v_'.str_repeat('q', 20), 'occurred_at' => now(), 'project_id' => $this->project->id]));

        $funnel = collect($this->page()->assertOk()->json('data.funnel'));
        $this->assertSame(3, $funnel->firstWhere('key', 'click')['count']);
    }
}

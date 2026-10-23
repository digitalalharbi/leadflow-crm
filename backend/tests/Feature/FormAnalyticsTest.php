<?php

namespace Tests\Feature;

use App\Domain\Tracking\Forms\FormTemplates;
use App\Enums\Role;
use App\Models\LandingForm;
use App\Models\LandingFormVersion;
use App\Models\Project;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How a form is doing: who saw it, who started it, where they stopped, who finished, how long it took — and what the matrix said, added
 * up across everybody without a name in it.
 */
class FormAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private array $rival;

    private Project $project;

    private LandingForm $form;

    private LandingFormVersion $version;

    private TrackingLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Form Analytics Co');
        $this->rival = $this->provisionTenant('Rival Analytics Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));

        $d = FormTemplates::get('investment_qualification');   // three steps, with a matrix
        $id = $this->actingAs($this->t['admin'])->postJson('/api/v1/tracking/forms', ['name' => 'تأهيل', 'project_id' => $this->project->uuid] + $d)->assertCreated()->json('data.form.id');
        $this->actingAs($this->t['admin'])->postJson("/api/v1/tracking/forms/{$id}/publish")->assertOk();

        $this->form = LandingForm::withoutGlobalScopes()->where('uuid', $id)->first();
        $this->version = LandingFormVersion::withoutGlobalScopes()->where('landing_form_id', $this->form->id)->first();
        $this->link = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create(['token' => 'an'.random_int(100000, 999999), 'name' => 'إعلان', 'channel' => 'meta', 'mode' => 'landing', 'landing_form_id' => $this->form->id, 'project_id' => $this->project->id]));
    }

    private function event(string $type, string $session, array $o = []): void
    {
        $this->asTenant($this->t['tenant'], fn () => TrackingEvent::create([
            'tracking_link_id' => $o['link'] ?? $this->link->id, 'event_type' => $type, 'visitor_id' => 'v_'.$session, 'session_id' => $session,
            'occurred_at' => $o['at'] ?? now()->subMinutes(30), 'meta' => $o['meta'] ?? null, 'form_version_id' => $o['version'] ?? null, 'event_id' => (string) str()->uuid(),
        ]));
    }

    private function trail(): void
    {
        foreach (range(1, 10) as $i) {
            $this->event('visit', "s{$i}");
        }
        foreach (range(1, 6) as $i) {
            $this->event('form_start', "s{$i}", ['at' => now()->subMinutes(30)]);
        }
        foreach (range(1, 5) as $i) {
            $this->event('form_step', "s{$i}", ['meta' => ['step' => '1']]);
        }
        foreach (range(1, 4) as $i) {
            $this->event('form_step', "s{$i}", ['meta' => ['step' => '2']]);
        }
        // Three finished: the first two in two minutes and one minute, the third in three.
        $this->event('form_submit', 's1', ['at' => now()->subMinutes(30)->addSeconds(120), 'version' => $this->version->id, 'meta' => ['matrix' => ['priorities' => ['return' => 'high', 'location' => 'mid']]]]);
        $this->event('form_submit', 's2', ['at' => now()->subMinutes(30)->addSeconds(60), 'version' => $this->version->id, 'meta' => ['matrix' => ['priorities' => ['return' => 'high', 'location' => 'low']]]]);
        $this->event('form_submit', 's3', ['at' => now()->subMinutes(30)->addSeconds(180), 'version' => $this->version->id, 'meta' => ['matrix' => ['priorities' => ['return' => 'mid']]]]);
        // Where the others stopped.
        $this->event('form_abandon', 's4', ['meta' => ['step' => '3', 'field' => 'priorities']]);
        $this->event('form_abandon', 's5', ['meta' => ['step' => '2', 'field' => 'budget']]);
        $this->event('form_abandon', 's6', ['meta' => ['step' => '1', 'field' => 'name']]);
        $this->event('form_abandon', 'sx', ['meta' => ['step' => '2', 'field' => 'budget']]);
    }

    private function analytics(array $query = [])
    {
        return $this->actingAs($this->t['admin'])->getJson("/api/v1/tracking/forms/{$this->form->uuid}/analytics".($query !== [] ? '?'.http_build_query($query) : ''));
    }

    public function test_the_headline_numbers(): void
    {
        $this->trail();

        $a = $this->analytics(['days' => 7])->assertOk()->json('data');

        $this->assertSame(10, $a['totals']['views']);
        $this->assertSame(6, $a['totals']['starts']);
        $this->assertSame(3, $a['totals']['submits']);
        $this->assertSame(50.0, (float) $a['totals']['completion_rate'], 'finished ÷ started');
        $this->assertSame(50.0, (float) $a['totals']['abandonment_rate']);
        $this->assertSame(120, (int) $a['totals']['avg_completion_seconds'], '(120 + 60 + 180) ÷ 3');
    }

    public function test_where_in_the_steps_people_were_lost(): void
    {
        $this->trail();

        $steps = collect($this->analytics()->json('data.steps'))->keyBy('step');

        $this->assertCount(3, $steps, 'the form has three steps');
        $this->assertSame(['entered' => 6, 'completed' => 5], ['entered' => $steps[1]['entered'], 'completed' => $steps[1]['completed']]);
        $this->assertSame(['entered' => 5, 'completed' => 4], ['entered' => $steps[2]['entered'], 'completed' => $steps[2]['completed']]);
        $this->assertSame(['entered' => 4, 'completed' => 3], ['entered' => $steps[3]['entered'], 'completed' => $steps[3]['completed']], 'the last step completes on submit');
        $this->assertEquals(25.0, $steps[3]['drop_off']);
    }

    public function test_where_in_the_form_they_stopped_by_field_never_by_what_they_typed(): void
    {
        $this->trail();

        $abandoned = collect($this->analytics()->json('data.abandoned_at'));

        $this->assertSame('budget', $abandoned->first()['field'], 'the most common place, first');
        $this->assertSame(2, $abandoned->first()['count']);
        $this->assertEqualsCanonicalizing(['budget', 'priorities', 'name'], $abandoned->pluck('field')->all());
        $this->assertStringNotContainsString('value', json_encode($abandoned));
    }

    public function test_a_matrix_is_added_up_across_everyone(): void
    {
        $this->trail();

        $matrix = collect($this->analytics()->json('data.matrix'))->firstWhere('field', 'priorities');
        $return = collect($matrix['rows'])->firstWhere('row', 'return');
        $location = collect($matrix['rows'])->firstWhere('row', 'location');

        $this->assertSame('ما أهمية العوامل التالية لك؟', $matrix['label']);
        $this->assertEquals(['high' => 2, 'mid' => 1], array_filter($return['counts']));
        $this->assertSame('العائد الإيجاري', $return['label']);
        $this->assertEquals(['mid' => 1, 'low' => 1], array_filter($location['counts']));
        $this->assertSame(3, $return['answered']);
    }

    public function test_the_versions_each_say_how_many_submitted_on_them(): void
    {
        $this->trail();

        $this->assertSame([['version' => 1, 'submits' => 3]], array_map(fn ($v) => ['version' => $v['version'], 'submits' => $v['submits']], $this->analytics()->json('data.versions')));
    }

    public function test_only_this_forms_own_links_count_and_only_the_period_asked_for(): void
    {
        $this->trail();
        $otherForm = $this->asTenant($this->t['tenant'], fn () => LandingForm::create(['name' => 'آخر', 'status' => 'draft', 'fields' => []]));
        $other = $this->asTenant($this->t['tenant'], fn () => TrackingLink::create(['token' => 'ot'.random_int(100000, 999999), 'name' => 'آخر', 'channel' => 'meta', 'mode' => 'landing', 'landing_form_id' => $otherForm->id]));
        $this->event('visit', 'o1', ['link' => $other->id]);
        $this->event('visit', 'old', ['at' => now()->subDays(60)]);

        $this->assertSame(10, $this->analytics(['days' => 30])->json('data.totals.views'), 'another form\'s views and a visit from two months ago are not this form\'s this month');
        $this->assertSame(11, $this->analytics(['days' => 90])->json('data.totals.views'));
    }

    public function test_a_form_nobody_has_seen_is_all_zeros_not_an_error(): void
    {
        $a = $this->analytics()->assertOk()->json('data');

        $this->assertSame(0, $a['totals']['views']);
        $this->assertNull($a['totals']['completion_rate']);
        $this->assertNull($a['totals']['avg_completion_seconds']);
        $this->assertCount(3, $a['steps']);
    }

    public function test_another_workspace_and_a_salesperson_cannot_read_it(): void
    {
        $this->trail();

        $this->actingAs($this->rival['admin'])->getJson("/api/v1/tracking/forms/{$this->form->uuid}/analytics")->assertNotFound();
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->actingAs($rep)->getJson("/api/v1/tracking/forms/{$this->form->uuid}/analytics")->assertForbidden();
    }

    public function test_the_page_may_report_where_a_visitor_stopped_but_only_in_the_shape_of_a_step_and_a_field(): void
    {
        $this->postJson('/api/v1/track/'.$this->link->token, [
            'event' => 'form_abandon', 'visitor_id' => 'v_'.str_repeat('a', 20), 'session_id' => 's_'.str_repeat('b', 20),
            'meta' => ['step' => '2', 'field' => 'budget', 'typed' => 'سلمان 0551234567', 'note' => 'x'],
        ])->assertNoContent();

        $e = TrackingEvent::withoutGlobalScopes()->where('event_type', 'form_abandon')->latest('id')->first();
        $this->assertSame(['step' => '2', 'field' => 'budget'], $e->meta, 'what the visitor typed is not a thing a beacon can carry');

        $this->postJson('/api/v1/track/'.$this->link->token, ['event' => 'form_abandon', 'meta' => ['step' => 'DROP TABLE', 'field' => '<script>alert(1)</script>']])->assertNoContent();
        $bad = TrackingEvent::withoutGlobalScopes()->where('event_type', 'form_abandon')->latest('id')->first();
        $this->assertEmpty((array) $bad->meta);
    }
}

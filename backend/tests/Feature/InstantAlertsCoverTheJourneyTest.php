<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\ImportantAlerts;
use App\Domain\Notifications\NotificationPrefs;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Channels\ProviderMailChannel;
use App\Notifications\ImportantNotification;
use App\Notifications\LeadAssignedNotification;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/**
 * Nine things interrupt a person — a new lead, a hot one, an interested one, a visit asked for, a reservation,
 * a lot of money, a clock running out, an automation that broke, a pixel that stopped. Each tells them WHO and
 * WHICH PROJECT, opens that lead (not a dashboard), and goes only to the person who can act on it.
 */
class InstantAlertsCoverTheJourneyTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private User $rep;

    private User $otherRep;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['leadflow_mail.provider' => 'log']);
        $this->t = $this->provisionTenant('Nine Co');
        $this->rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->otherRep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create($attributes + [
            'full_name' => 'سلمان القحطاني', 'phone_normalized' => '+9665512'.random_int(10000, 99999), 'status' => 'contacted',
            'assigned_to' => $this->rep->id, 'project_id' => $this->project->id,
        ]));
    }

    private function told(User $user, ?string $kind = null)
    {
        return DatabaseNotification::where('notifiable_id', $user->id)->get()
            ->filter(fn ($n) => $kind === null || ($n->data['kind'] ?? null) === $kind);
    }

    public function test_the_nine_alerts_are_all_categories_a_person_can_choose_how_to_hear(): void
    {
        foreach (['new_lead', 'hot_lead', 'interested_lead', 'visit_requested', 'reservation_intent', 'high_value', 'sla_breach', 'automation_failure', 'tracking_failure'] as $category) {
            $this->assertContains($category, AlertKinds::CATEGORIES, "{$category} cannot be chosen");
            $this->assertNotEmpty(AlertKinds::kindsOf($category), "{$category} has no notification");
        }
    }

    public function test_a_lead_becoming_interested_tells_its_owner_once_with_the_project_and_opens_the_lead(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            ImportantAlerts::interestChanged($lead, 'interested');
            ImportantAlerts::interestChanged($lead, 'interested');
        });

        $mine = $this->told($this->rep, 'interested_lead');
        $this->assertCount(1, $mine, 'once, however often the level is re-read');
        $this->assertStringContainsString('سلمان القحطاني', $mine->first()->data['body']);
        $this->assertStringContainsString('النرجس', $mine->first()->data['body'], 'it says which project');
        $this->assertSame($lead->uuid, $mine->first()->data['lead_id'], 'it opens the lead itself');
        $this->assertCount(0, $this->told($this->otherRep), 'and nobody else on the floor');
    }

    public function test_the_alerts_that_already_existed_now_name_the_project_too(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            ImportantAlerts::hotLead($lead);
            ImportantAlerts::reservationIntent($lead);
            ImportantAlerts::appointmentRequested($lead);
        });

        foreach (['hot_lead', 'reservation_intent', 'appointment_requested'] as $kind) {
            $note = $this->told($this->rep, $kind)->first();
            $this->assertNotNull($note, $kind);
            $this->assertStringContainsString('النرجس', $note->data['body'], "{$kind} does not say which project");
            $this->assertSame($lead->uuid, $note->data['lead_id']);
        }
    }

    public function test_an_assigned_new_lead_tells_the_owner_in_the_app_and_carries_what_the_digests_need(): void
    {
        $result = app(LeadIngestionService::class)->ingest($this->t['tenant'], IncomingLead::fromArray(IntegrationProvider::GoogleSheets, [
            'full_name' => 'نورة العتيبي', 'phone' => '+966551234567', 'project_id' => $this->project->id, 'assigned_to' => $this->rep->id,
        ]));

        $mine = $this->told($this->rep, 'new_lead');
        $this->assertCount(1, $mine);
        $this->assertSame($result->lead->uuid, $mine->first()->data['lead_id']);
        $this->assertStringContainsString('النرجس', $mine->first()->data['body']);
        $this->assertSame('lead_assigned', $mine->first()->data['type'], 'the tray still draws it as before');
    }

    public function test_a_new_lead_nobody_owns_tells_the_managers_once_and_an_owned_one_does_not(): void
    {
        $this->t['tenant']->settings->forceFill(['assignment_strategy' => 'manual'])->save();

        app(LeadIngestionService::class)->ingest($this->t['tenant'], IncomingLead::fromArray(IntegrationProvider::GoogleSheets, [
            'full_name' => 'بلا مسؤول', 'phone' => '+966552222222', 'project_id' => $this->project->id,
        ]));
        app(LeadIngestionService::class)->ingest($this->t['tenant'], IncomingLead::fromArray(IntegrationProvider::GoogleSheets, [
            'full_name' => 'له مسؤول', 'phone' => '+966553333333', 'project_id' => $this->project->id, 'assigned_to' => $this->rep->id,
        ]));

        $heard = $this->told($this->t['admin'], 'new_lead');
        $this->assertCount(1, $heard, 'only the lead with no owner needs the managers');
        $this->assertStringContainsString('بلا مسؤول', $heard->first()->data['body']);
        $this->assertCount(0, $this->told($this->otherRep), 'the rest of the floor is not told');
    }

    public function test_the_email_of_an_alert_opens_what_it_is_about(): void
    {
        $lead = (string) (new ImportantNotification('hot_lead', 'عميل ساخن', 'سلمان', 'abc-lead', null, 'critical'))->toMail($this->rep)->render();
        $this->assertStringContainsString('/leads/abc-lead', $lead);

        $tracking = (string) (new ImportantNotification('tracking_failure', 'عطل', 'توقف', null, '/tracking', 'critical'))->toMail($this->rep)->render();
        $this->assertStringContainsString('/tracking', $tracking, 'a tracking failure opens tracking, not the home page');
    }

    public function test_instant_means_an_email_and_the_other_modes_do_not_double_up(): void
    {
        $this->assertNotContains(ProviderMailChannel::class, (new LeadAssignedNotification($this->lead()))->via($this->rep), 'a rep is not mailed every lead by default');

        NotificationPrefs::save($this->rep, ['alerts' => ['new_lead' => 'instant', 'interested_lead' => 'daily']]);
        $rep = $this->rep->fresh();

        $this->assertContains(ProviderMailChannel::class, (new LeadAssignedNotification($this->lead()))->via($rep));
        $this->assertNotContains(ProviderMailChannel::class, (new ImportantNotification('interested_lead', 't', 'b'))->via($rep), 'daily goes to the summary, not also at once');
    }
}

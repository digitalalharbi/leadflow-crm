<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Leads\NextBestAction;
use App\Enums\LeadStatus;
use App\Models\AutomationRule;
use App\Models\Lead;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One state the product must never hold: a lead whose status says it was
 * contacted while its `first_contact_at` is null.
 *
 * It is not a crash. Nothing errors, no job fails, no log line appears. The
 * badge reads the status and says "contacted"; the work queue reads the
 * timestamp and says "new lead, never touched". It was found by opening the
 * screen and reading the two lines next to each other, which is why it is
 * pinned at three levels here — the model, the database, and the one consumer
 * that was drawing a conclusion from the timestamp alone.
 */
class ContactStateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Integrity Co');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+9665001'.random_int(10000, 99999),
            'status' => 'new',
            'assigned_to' => $this->t['admin']->id,
        ], $attributes)));
    }

    /** @return array<string, array{LeadStatus}> */
    public static function contactedStatuses(): array
    {
        return [
            'contacted' => [LeadStatus::Contacted],
            'replied' => [LeadStatus::Replied],
            'qualified' => [LeadStatus::Qualified],
            'appointment' => [LeadStatus::Appointment],
            'negotiation' => [LeadStatus::Negotiation],
            'won' => [LeadStatus::Won],
        ];
    }

    #[DataProvider('contactedStatuses')]
    public function test_a_status_that_means_contact_always_carries_a_contact_time(LeadStatus $status): void
    {
        $lead = $this->lead(['status' => $status->value]);

        $this->assertNotNull($lead->first_contact_at, "{$status->value} left the timestamp null");
    }

    /** Moving an existing lead forward backfills it too, not only creation. */
    public function test_moving_a_new_lead_forward_sets_the_time(): void
    {
        $lead = $this->lead();
        $this->assertNull($lead->first_contact_at);

        $this->asTenant($this->t['tenant'], fn () => $lead->update(['status' => 'qualified']));

        $this->assertNotNull($lead->fresh()->first_contact_at);
    }

    /**
     * The statuses left out are left out on purpose: a wrong number was never
     * contacted, and claiming otherwise inflates every response-time figure.
     */
    public function test_an_invalid_lead_is_not_credited_with_a_contact(): void
    {
        $lead = $this->lead(['status' => 'invalid']);

        $this->assertNull($lead->first_contact_at);
    }

    /** The path that could create this in production: a customer's own rule. */
    public function test_automation_moving_a_lead_forward_cannot_create_the_contradiction(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], function () use ($lead) {
            AutomationRule::create([
                'name' => 'نقل تلقائي',
                'trigger' => 'lead.created',
                'conditions' => [],
                'actions' => [['type' => 'change_stage', 'status' => 'contacted']],
            ]);

            app(ActionExecutor::class)
                ->execute($lead, ['type' => 'change_stage', 'status' => 'contacted']);
        });

        $fresh = $lead->fresh();
        $this->assertSame(LeadStatus::Contacted, $fresh->status);
        $this->assertNotNull($fresh->first_contact_at);
    }

    /** Below the application: a raw write cannot create it either. */
    public function test_the_database_itself_refuses_the_contradiction(): void
    {
        $lead = $this->lead();

        $this->expectException(QueryException::class);

        \DB::table('leads')->where('id', $lead->id)
            ->update(['status' => 'contacted', 'first_contact_at' => null]);
    }

    /**
     * And the consumer that produced the visible symptom: the work queue must
     * not call a lead "new" when the badge beside it says contacted.
     */
    public function test_the_next_action_never_calls_a_contacted_lead_new(): void
    {
        $lead = $this->lead(['status' => 'contacted']);
        // Force the exact contradiction past the model, as a stale row would be.
        $lead->setRawAttributes(array_merge($lead->getAttributes(), ['first_contact_at' => null]), true);

        $action = app(NextBestAction::class)->for($lead, 30);

        $this->assertStringNotContainsString('عميل جديد', $action['reason']);
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Sla\SlaSweeper;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Notifications\SlaBreachNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The rungs of the SLA ladder that are supposed to reach a person.
 *
 * The sweeper wrote "تذكير آلي" and "تصعيد آلي للإدارة" into the lead's
 * timeline and sent nothing at all. So the reminder arrived only for somebody
 * already looking at the lead they had not contacted, and the escalation "to
 * management" was visible to everyone except management.
 *
 * That is the failure the whole ladder exists to prevent: the lead goes cold
 * while the product records, accurately, that it noticed.
 */
class SlaReachesPeopleTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private SlaSweeper $sweeper;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Ladder Co');
        $this->sweeper = app(SlaSweeper::class);

        $this->t['tenant']->settings->forceFill([
            'sla_remind_after_minutes' => 10,
            'sla_escalate_after_minutes' => 30,
            'sla_reassign_after_minutes' => 0,
        ])->save();

        $this->rep = $this->makeUser($this->t['tenant']);
    }

    private function staleLead(int $ageMinutes, ?User $owner = null): Lead
    {
        return $this->asTenant($this->t['tenant'], function () use ($ageMinutes, $owner) {
            $lead = Lead::create([
                'full_name' => 'سلطان القحطاني',
                'phone' => '0501234567',
                'phone_normalized' => '+96650015'.random_int(1000, 9999),
                'status' => 'new',
                'assigned_to' => $owner?->id ?? $this->rep->id,
            ]);

            $lead->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->saveQuietly();

            return $lead->fresh();
        });
    }

    private function sweep(): void
    {
        $this->asTenant($this->t['tenant'], fn () => $this->sweeper->sweep($this->t['tenant']));
    }

    public function test_the_reminder_reaches_the_rep_who_owns_the_lead(): void
    {
        Notification::fake();
        $this->staleLead(15);

        $this->sweep();

        Notification::assertSentTo(
            $this->rep,
            SlaBreachNotification::class,
            fn (SlaBreachNotification $n) => $n->stage === SlaBreachNotification::REMIND,
        );
    }

    /** It carries the name and the number, because the point is a phone call. */
    public function test_the_reminder_says_who_is_waiting_and_for_how_long(): void
    {
        Notification::fake();
        $this->staleLead(15);

        $this->sweep();

        Notification::assertSentTo($this->rep, SlaBreachNotification::class, function ($n) {
            $payload = $n->toDatabase($this->rep);

            return str_contains($payload['body'], 'سلطان القحطاني')
                && $payload['lead_phone'] !== null
                && $payload['age_minutes'] >= 15;
        });
    }

    public function test_the_escalation_reaches_management(): void
    {
        Notification::fake();
        $this->staleLead(45);

        $this->sweep();

        Notification::assertSentTo(
            $this->t['admin'],
            SlaBreachNotification::class,
            fn (SlaBreachNotification $n) => $n->stage === SlaBreachNotification::ESCALATE,
        );
    }

    /**
     * The owner has already had the reminder, and the point of this rung is
     * that somebody else now knows.
     */
    public function test_the_escalation_does_not_go_back_to_the_rep_who_missed_it(): void
    {
        Notification::fake();
        $this->staleLead(45);

        $this->sweep();

        Notification::assertSentTo($this->rep, SlaBreachNotification::class, function ($n) {
            return $n->stage === SlaBreachNotification::REMIND;
        });

        $escalations = Notification::sent($this->rep, SlaBreachNotification::class)
            ->filter(fn ($n) => $n->stage === SlaBreachNotification::ESCALATE);

        $this->assertCount(0, $escalations);
    }

    /** A rep who owns nothing here is not told about somebody else's lead. */
    public function test_another_rep_is_not_told(): void
    {
        Notification::fake();
        $bystander = $this->makeUser($this->t['tenant']);

        $this->staleLead(15);
        $this->sweep();

        Notification::assertNotSentTo($bystander, SlaBreachNotification::class);
    }

    public function test_nobody_in_another_workspace_hears_about_it(): void
    {
        Notification::fake();
        $other = $this->provisionTenant('Somebody Else');

        $this->staleLead(45);
        $this->sweep();

        Notification::assertNotSentTo($other['admin'], SlaBreachNotification::class);
    }

    /**
     * Nagging about one breach destroys trust in the notifications faster than
     * missing one, and the sweep runs every five minutes.
     */
    public function test_one_breach_is_never_announced_twice(): void
    {
        Notification::fake();
        $this->staleLead(15);

        $this->sweep();
        $this->sweep();
        $this->sweep();

        $this->assertCount(1, Notification::sent($this->rep, SlaBreachNotification::class));
    }

    /** An unassigned lead has nobody to remind; it must not throw. */
    public function test_a_lead_with_no_owner_does_not_break_the_sweep(): void
    {
        Notification::fake();

        $lead = $this->asTenant($this->t['tenant'], function () {
            $lead = Lead::create([
                'full_name' => 'بلا مسؤول',
                'phone_normalized' => '+96650016'.random_int(1000, 9999),
                'status' => 'new',
            ]);
            $lead->forceFill(['created_at' => now()->subMinutes(45)])->saveQuietly();

            return $lead->fresh();
        });

        $this->sweep();

        $this->assertNotNull($lead->fresh());
    }

    /**
     * The timeline shows who did a thing, and these carry no user. Automation
     * marks its own work `by: automation`; the SLA sweeper marked only `sla`,
     * so its entries were the one kind of automatic change still showing no
     * actor at all.
     */
    public function test_its_entries_say_they_were_automatic(): void
    {
        $lead = $this->staleLead(45);

        $this->sweep();

        $entries = LeadActivity::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->whereNull('user_id')
            ->get();

        $this->assertGreaterThanOrEqual(2, $entries->count());

        foreach ($entries as $entry) {
            $this->assertSame('sla', $entry->meta['by'] ?? null);
        }
    }

    /** A manager in this workspace hears an escalation; a rep does not. */
    public function test_a_manager_hears_the_escalation(): void
    {
        Notification::fake();

        $manager = User::create([
            'tenant_id' => $this->t['tenant']->id,
            'name' => 'مدير', 'email' => 'manager@ladder.test',
            'password' => bcrypt('secret'), 'role' => Role::Manager->value, 'is_active' => true,
        ]);

        $this->staleLead(45);
        $this->sweep();

        Notification::assertSentTo($manager, SlaBreachNotification::class);
    }
}

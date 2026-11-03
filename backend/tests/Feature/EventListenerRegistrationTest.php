<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Events\Contracts\LeadDomainEvent;
use App\Events\LeadStageChanged;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One event, one run.
 *
 * Both lead listeners were registered twice: once by hand in AppServiceProvider
 * and once by Laravel's automatic discovery of app/Listeners, which binds a
 * listener to whatever event its `handle()` is typed against. So every lead
 * event ran every automation rule twice — two identical notes, two WhatsApp
 * messages to the same customer, every score change applied twice — and the
 * trace showed two runs carrying different correlation ids, which reads as two
 * genuine events rather than one bug.
 *
 * The behavioural half of this would have caught it. The structural half is
 * here because the fix lives in bootstrap/app.php and the trap re-arms for any
 * listener somebody adds later: a reader of AppServiceProvider sees nothing
 * wrong with registering one.
 */
class EventListenerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_listener_is_bound_to_a_lead_event_more_than_once(): void
    {
        $bound = collect(Event::getRawListeners()[LeadDomainEvent::class] ?? [])
            ->filter(fn ($listener) => is_string($listener))
            // Discovery registers `Listener@handle`; the explicit call registers
            // `Listener`. Same binding, two spellings, so compare the class.
            ->map(fn (string $listener) => Str::before($listener, '@'))
            ->countBy()
            ->filter(fn (int $times) => $times > 1);

        $this->assertSame(
            [],
            $bound->all(),
            'A listener bound twice runs twice: '.$bound->keys()->implode(', '),
        );
    }

    public function test_a_single_event_applies_a_rule_exactly_once(): void
    {
        $t = $this->provisionTenant('Once Co');

        [$rule, $lead] = $this->asTenant($t['tenant'], fn () => [
            AutomationRule::create([
                'name' => 'قاعدة المرحلة',
                'trigger' => 'lead.stage_changed',
                'conditions' => [],
                'actions' => [['type' => 'add_note', 'body' => 'تقدّم العميل']],
                'is_enabled' => true,
            ]),
            Lead::create([
                'full_name' => 'عميل',
                'phone_normalized' => '+9665007'.self::uniqueDigits(5),
                'status' => 'qualified',
                'assigned_to' => $t['admin']->id,
            ]),
        ]);

        $this->asTenant($t['tenant'], fn () => LeadStageChanged::dispatch(
            $lead,
            LeadStatus::Contacted,
            LeadStatus::Qualified,
            $t['admin'],
        ));

        $this->assertSame(1, AutomationRun::withoutGlobalScopes()
            ->where('automation_rule_id', $rule->id)
            ->where('outcome', 'applied')
            ->count());

        // The run count is the symptom; the duplicated side effect is the cost.
        $this->assertSame(1, LeadActivity::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->where('type', ActivityType::NoteAdded->value)
            ->count());
    }
}

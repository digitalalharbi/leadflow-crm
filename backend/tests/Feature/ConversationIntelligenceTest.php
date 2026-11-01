<?php

namespace Tests\Feature;

use App\Domain\Automation\ActionExecutor;
use App\Domain\Conversation\AmountParser;
use App\Domain\Conversation\ConversationIntelligence;
use App\Domain\Conversation\Fact;
use App\Domain\Conversation\RulesExtractor;
use App\Domain\Conversation\Vocabulary;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadAnswer;
use App\Models\LeadFact;
use App\Models\LeadMessage;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What a conversation says, kept as structured facts — Claude when there is a
 * key, plainly the rules when there is not — and what the customer has already
 * answered is never asked again.
 */
class ConversationIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null]);
        // Nothing here may reach a real model: an unfaked request fails, and a test that wants
        // Claude says exactly what it answers.
        Http::preventStrayRequests();
        $this->t = $this->provisionTenant('Talk Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'ماجد', 'phone' => '0551234567', 'phone_normalized' => '+9665512'.self::uniqueDigits(5),
            'status' => 'contacted', 'project_id' => $this->project->id,
        ], $attributes)));
    }

    private function say(Lead $lead, string $text, string $direction = 'inbound', array $extra = []): LeadMessage
    {
        return $this->asTenant($this->t['tenant'], fn () => LeadMessage::create(array_merge([
            'lead_id' => $lead->id, 'direction' => $direction === 'inbound' ? MessageDirection::Inbound : MessageDirection::Outbound,
            'channel' => MessageChannel::Whatsapp, 'provider' => 'whatsapp_cloud', 'message_type' => 'text', 'body' => $text,
            'status' => $direction === 'inbound' ? 'received' : 'sent',
        ], $extra)));
    }

    private function analyse(Lead $lead, bool $force = false): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(ConversationIntelligence::class)->analyse($lead->fresh(), $force));
    }

    private function facts(Lead $lead): Collection
    {
        return $this->asTenant($this->t['tenant'], fn () => LeadFact::where('lead_id', $lead->id)->get());
    }

    private function key(string $key): void
    {
        $this->asTenant($this->t['tenant'], fn () => TenantSetting::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->first()->forceFill(['ai_api_key' => $key])->save());
    }

    // ----------------------------------------------------------------- rules

    public function test_the_rules_read_an_arabic_message_into_structured_facts(): void
    {
        $facts = collect((new RulesExtractor)->extract(['ابي فيلا بميزانية مليون ونصف وابي أزور الموقع بس السعر غالي شوي، خلال شهرين']))->keyBy(fn (Fact $f) => $f->kind.':'.$f->value);

        $this->assertTrue($facts->has('service_interest:villa'));
        $this->assertTrue($facts->has('appointment_intent:requested'));
        $this->assertTrue($facts->has('objection:price_high'));
        $this->assertSame(['min' => 1_500_000, 'max' => 1_500_000, 'currency' => 'SAR'], $facts['budget_signal:stated']->detail);
        $this->assertSame('within_3_months', $facts['urgency:medium']->detail['timeline']);
    }

    public function test_the_rules_read_english_too(): void
    {
        $facts = collect((new RulesExtractor)->extract(["I'm interested in an apartment, my budget is 800k. Can I visit this week? Seems too expensive though, I'll ask my wife"]))->keyBy(fn (Fact $f) => $f->kind.':'.$f->value);

        $this->assertTrue($facts->has('intent:interested'));
        $this->assertTrue($facts->has('service_interest:apartment'));
        $this->assertSame(800_000, $facts['budget_signal:stated']->detail['max']);
        $this->assertTrue($facts->has('urgency:high'));
        $this->assertTrue($facts->has('objection:price_high'));
        $this->assertTrue($facts->has('objection:family_decision'));
        $this->assertTrue($facts->has('appointment_intent:requested'));
    }

    public function test_an_unwilling_customer_is_read_as_such_and_a_plain_message_yields_only_sentiment(): void
    {
        $no = collect((new RulesExtractor)->extract(['مو مهتم شكرا']))->keyBy('kind');
        $this->assertSame('not_interested', $no['intent']->value);

        $plain = collect((new RulesExtractor)->extract(['السلام عليكم']));
        $this->assertSame(['sentiment'], $plain->pluck('kind')->all());
        $this->assertSame('neutral', $plain->first()->value);
    }

    public function test_amounts_are_only_amounts_when_they_carry_a_unit_or_a_currency(): void
    {
        $this->assertSame(['min' => 500_000, 'max' => 500_000], AmountParser::parse('٥٠٠ ألف'));
        $this->assertSame(['min' => 1_500_000, 'max' => 1_500_000], AmountParser::parse('1.5 مليون'));
        $this->assertSame(['min' => 2_000_000, 'max' => 2_000_000], AmountParser::parse('مليونين'));
        $this->assertSame(['min' => 800_000, 'max' => 1_200_000], AmountParser::parse('between 800k and 1.2 million'));
        $this->assertNull(AmountParser::parse('رقمي 0551234567'), 'a phone number is not a price');
        $this->assertNull(AmountParser::parse('عندي 3 غرف'), 'a count is not a price');
    }

    // ------------------------------------------------------------ the engine

    public function test_without_a_key_the_rules_read_it_and_the_result_says_so(): void
    {
        $lead = $this->lead();
        $this->say($lead, 'ابي شقة وميزانيتي 900 ألف');

        $run = $this->analyse($lead, true);

        $this->assertSame(['rules', 'no_api_key'], [$run['engine'], $run['note']]);
        $this->assertSame(['rules'], $this->facts($lead)->pluck('source')->unique()->all());
        $summary = $this->asTenant($this->t['tenant'], fn () => app(ConversationIntelligence::class)->summary($lead->fresh()));
        $this->assertSame('rules', $summary['engine']);
        $this->assertSame('no_api_key', $summary['note']);
        $this->assertSame('apartment', $summary['facts']['service_interest']);
    }

    private function claudeSays(array $input): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'record_facts', 'input' => $input]]], 200)]);
    }

    public function test_with_a_key_claudes_reading_is_used_and_held_to_the_closed_lists(): void
    {
        $this->key('sk-ant-test');
        $lead = $this->lead();
        $this->claudeSays([
            'intent' => 'ready_to_buy', 'service_interest' => 'villa', 'sentiment' => 'positive',
            'urgency' => 'hacked', // not on the list: dropped
            'budget' => ['signal' => 'stated', 'min' => 2_000_000, 'max' => 2_500_000],
            'objections' => ['price_high', 'made_up'],
        ]);
        $this->say($lead, 'I would like to buy a villa');

        $run = $this->analyse($lead, true);

        $this->assertSame(['claude', null], [$run['engine'], $run['note']]);
        $facts = $this->facts($lead)->keyBy(fn ($f) => $f->kind.':'.$f->value);
        $this->assertTrue($facts->has('intent:ready_to_buy'));
        $this->assertTrue($facts->has('objection:price_high'));
        $this->assertFalse($facts->has('objection:made_up'));
        $this->assertNull($this->facts($lead)->firstWhere('kind', 'urgency'), 'an invented value is never stored');
        $this->assertSame(['min' => 2_000_000, 'max' => 2_500_000, 'currency' => 'SAR'], $facts['budget_signal:stated']->detail);
        $this->assertSame(['claude'], $facts->pluck('source')->unique()->all());
    }

    public function test_an_unbelievable_amount_from_claude_is_not_a_stated_budget(): void
    {
        $this->key('sk-ant-test');
        $lead = $this->lead();
        $this->claudeSays(['budget' => ['signal' => 'stated', 'min' => 5, 'max' => 9], 'sentiment' => 'neutral']);
        $this->say($lead, 'x');

        $this->analyse($lead, true);

        $this->assertNull($this->facts($lead)->firstWhere('kind', 'budget_signal'));
    }

    public function test_when_claude_fails_the_rules_step_in_and_the_note_says_why(): void
    {
        $this->key('sk-ant-test');
        $lead = $this->lead();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);
        $this->say($lead, 'مو مهتم');

        $run = $this->analyse($lead, true);

        $this->assertSame(['rules', 'claude_failed'], [$run['engine'], $run['note']]);
        $this->assertSame('not_interested', $this->facts($lead)->firstWhere('kind', 'intent')->value);
    }

    public function test_a_reply_with_nothing_usable_falls_back_and_says_that_instead(): void
    {
        $this->key('sk-ant-test');
        $lead = $this->lead();
        $this->claudeSays(['intent' => 'nonsense']);
        $this->say($lead, 'ابي فيلا');

        $this->assertSame(['rules', 'claude_empty'], [($r = $this->analyse($lead, true))['engine'], $r['note']]);
    }

    // ----------------------------------------------------------- when it runs

    public function test_the_conversation_is_read_once_per_customer_message(): void
    {
        $lead = $this->lead();
        $first = $this->say($lead, 'ابي فيلا');
        $this->say($lead, 'كم السعر', 'outbound');

        $this->assertSame($first->id, $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->facts_message_id), 'read when it was saved');
        $this->assertTrue($this->analyse($lead)['skipped'], 'nothing new to read');

        $second = $this->say($lead, 'وابي أزور الموقع');
        $this->assertSame($second->id, $this->asTenant($this->t['tenant'], fn () => $lead->fresh()->facts_message_id), 'and the next one is read too');
        $this->assertTrue($this->analyse($lead)['skipped']);
        $this->assertFalse($this->analyse($lead, true)['skipped'], 'unless somebody asks again');
    }

    public function test_a_message_saved_is_a_message_read(): void
    {
        $lead = $this->lead();

        $this->say($lead, 'عندي ميزانية مليون ونصف');

        $this->assertSame('stated', $this->facts($lead)->firstWhere('kind', 'budget_signal')?->value, 'saving the inbound message queued and ran the extraction');
    }

    public function test_a_later_message_changes_the_current_value_and_objections_pile_up(): void
    {
        $lead = $this->lead();
        $this->say($lead, 'ابي اعرف التفاصيل، السعر غالي');
        $this->say($lead, 'تمام جاهز للشراء، بس لازم اشاور اهلي');

        $facts = $this->facts($lead);

        $this->assertSame(['ready_to_buy'], $facts->where('kind', 'intent')->pluck('value')->all(), 'one current intent');
        $this->assertEqualsCanonicalizing(['price_high', 'family_decision'], $facts->where('kind', 'objection')->pluck('value')->all());
    }

    // ------------------------------------------------------------ completion

    public function test_what_the_customer_said_fills_the_blank_fields_and_never_overwrites_a_known_one(): void
    {
        $blank = $this->lead();
        $this->say($blank, 'ابي فيلا وميزانيتي مليونين وابي أزور الموقع');
        $this->analyse($blank, true);
        $fresh = $this->asTenant($this->t['tenant'], fn () => $blank->fresh());

        $this->assertEquals(2_000_000, $fresh->budget_max);
        $this->assertSame('villa', $fresh->preferred_property_type);
        $this->assertTrue($fresh->appointment_interest);

        $known = $this->lead(['budget_max' => 700_000, 'preferred_property_type' => 'apartment']);
        $this->say($known, 'ابي فيلا وميزانيتي مليونين');
        $this->analyse($known, true);
        $after = $this->asTenant($this->t['tenant'], fn () => $known->fresh());

        $this->assertEquals(700_000, $after->budget_max, 'the rep\'s figure stands');
        $this->assertSame('apartment', $after->preferred_property_type);
    }

    private function question(string $key, string $type, int $order): ProjectQuestion
    {
        return $this->asTenant($this->t['tenant'], fn () => ProjectQuestion::create([
            'project_id' => $this->project->id, 'key' => $key, 'label' => 'سؤال '.$key, 'type' => $type, 'is_active' => true, 'sort_order' => $order,
        ]));
    }

    public function test_a_question_the_conversation_has_answered_is_answered_for_us(): void
    {
        $budget = $this->question('budget', 'number', 1);
        $timeline = $this->question('timeline', 'text', 2);
        $other = $this->question('children', 'text', 3);
        $lead = $this->lead();
        $this->say($lead, 'ميزانيتي مليون ونصف وابي أشتري خلال شهرين');

        $this->analyse($lead, true);

        $answers = $this->asTenant($this->t['tenant'], fn () => LeadAnswer::where('lead_id', $lead->id)->get()->keyBy('project_question_id'));
        $this->assertEquals(1_500_000, $answers[$budget->id]->value);
        $this->assertSame('خلال 3 أشهر', $answers[$timeline->id]->value);
        $this->assertArrayNotHasKey($other->id, $answers->all(), 'what was not said is not guessed');
    }

    public function test_the_automation_does_not_ask_what_is_already_answered_and_asks_only_the_rest(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp', 'name' => 'WhatsApp Business', 'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000999'], 'credentials' => ['verify_token' => 'v', 'app_secret' => 's'], 'is_active' => true,
        ]));
        $this->question('budget', 'number', 1);
        $rest = $this->question('children', 'text', 2);
        $lead = $this->lead(['budget_max' => 900_000]);

        $result = $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)->execute($lead->fresh(), ['type' => 'ask_qualification']));

        $this->assertTrue($result['ok'], $result['description']);
        $sent = $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->where('direction', 'outbound')->latest('id')->first());
        $this->assertStringContainsString('سؤال children', $sent->body);
        $this->assertStringNotContainsString('سؤال budget', $sent->body, 'the budget is already known');
        $this->assertSame([$rest->id], $sent->context['questions'], 'the message remembers which questions it asked');
    }

    public function test_when_everything_is_answered_nothing_is_sent(): void
    {
        $this->question('budget', 'number', 1);
        $lead = $this->lead(['budget_max' => 900_000]);

        $result = $this->asTenant($this->t['tenant'], fn () => app(ActionExecutor::class)->execute($lead->fresh(), ['type' => 'ask_qualification']));

        $this->assertSame('skipped', $result['result']);
        $this->assertSame(0, $this->asTenant($this->t['tenant'], fn () => LeadMessage::where('lead_id', $lead->id)->where('direction', 'outbound')->count()));
    }

    public function test_a_numbered_reply_answers_the_numbered_questions_in_their_own_types(): void
    {
        $budget = $this->question('wallet', 'number', 1);
        $when = $this->question('when_to_buy', 'text', 2);
        $visit = $this->question('site_visit', 'boolean', 3);
        $lead = $this->lead();
        $this->say($lead, 'أسئلة التأهيل', 'outbound', ['context' => ['questions' => [$budget->id, $when->id, $visit->id]]]);

        $this->say($lead, "1. مليون ونصف\n2. خلال شهرين\n3. نعم");

        $answers = $this->asTenant($this->t['tenant'], fn () => LeadAnswer::where('lead_id', $lead->id)->get()->keyBy('project_question_id'));
        $this->assertEquals(1_500_000, $answers[$budget->id]->value);
        $this->assertSame('خلال شهرين', $answers[$when->id]->value);
        $this->assertTrue($answers[$visit->id]->value);
    }

    public function test_a_reply_to_one_question_without_numbering_answers_it_and_an_old_answer_is_kept(): void
    {
        $only = $this->question('wallet', 'number', 1);
        $lead = $this->lead();
        $this->say($lead, 'ما ميزانيتك؟', 'outbound', ['context' => ['questions' => [$only->id]]]);
        $this->asTenant($this->t['tenant'], fn () => LeadAnswer::create(['lead_id' => $lead->id, 'project_question_id' => $only->id, 'value' => 600_000]));

        $this->say($lead, 'حوالي مليونين');

        $this->assertEquals(600_000, $this->asTenant($this->t['tenant'], fn () => LeadAnswer::where('lead_id', $lead->id)->value('value')), 'what was already recorded is not overwritten');
    }

    public function test_an_answer_that_is_not_of_the_questions_type_is_left_unanswered(): void
    {
        $budget = $this->question('wallet', 'number', 1);
        $lead = $this->lead();
        $this->say($lead, 'ما ميزانيتك؟', 'outbound', ['context' => ['questions' => [$budget->id]]]);

        $this->say($lead, 'والله ما أدري بعد');

        $this->assertSame(0, $this->asTenant($this->t['tenant'], fn () => LeadAnswer::where('lead_id', $lead->id)->count()));
    }

    // ------------------------------------------------------------------- API

    public function test_the_lead_screen_carries_the_facts_and_the_engine_and_a_rep_can_read_again(): void
    {
        $lead = $this->lead(['assigned_to' => $this->t['admin']->id]);
        $this->say($lead, 'ابي شقة وغالي شوي');

        $shown = $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}")->assertOk()->json('data.conversation');

        $this->assertSame('rules', $shown['engine']);
        $this->assertSame('apartment', $shown['facts']['service_interest']);
        $this->assertContains('price_high', $shown['facts']['objections']);

        $this->say($lead, 'وابي أزور الموقع');
        $again = $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/conversation/analyze")->assertOk()->json('data.conversation');
        $this->assertSame('requested', $again['facts']['appointment_intent']);
    }

    public function test_only_closed_lists_are_ever_stored(): void
    {
        $lead = $this->lead();
        $this->say($lead, 'ابي فيلا غالي مو مهتم وابي أزور وخلال شهرين وميزانية مليون');

        foreach ($this->facts($lead) as $fact) {
            $this->assertTrue(Vocabulary::valid($fact->kind, $fact->value), "{$fact->kind}={$fact->value} is on its list");
            $this->assertContains($fact->source, ['claude', 'rules']);
        }
    }
}

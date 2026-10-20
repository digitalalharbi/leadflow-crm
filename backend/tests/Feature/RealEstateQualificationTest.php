<?php

namespace Tests\Feature;

use App\Domain\Conversation\ConversationIntelligence;
use App\Domain\Conversation\Fact;
use App\Domain\Conversation\QualificationCompleter;
use App\Domain\Conversation\RulesExtractor;
use App\Domain\Conversation\Vocabulary;
use App\Domain\Qualification\QualificationGaps;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\Role;
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
 * A real estate qualification that listens: what a customer volunteers in a sentence becomes
 * structured facts, fills the lead and the project's own questions, moves the score at once, and
 * is never asked for again.
 */
class RealEstateQualificationTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null]);
        Http::preventStrayRequests();
        $this->t = $this->provisionTenant('Estate Qual Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'ماجد', 'phone' => '0551234567', 'phone_normalized' => '+9665512'.random_int(10000, 99999),
            'status' => 'contacted', 'project_id' => $this->project->id,
        ], $attributes)));
    }

    private function say(Lead $lead, string $text): LeadMessage
    {
        return $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id, 'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Whatsapp,
            'provider' => 'whatsapp_cloud', 'message_type' => 'text', 'body' => $text, 'status' => 'received',
        ]));
    }

    private function analyse(Lead $lead): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(ConversationIntelligence::class)->analyse($lead->fresh(), true));
    }

    private function fresh(Lead $lead): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => $lead->fresh());
    }

    /** @return Collection<string,Fact> */
    private function read(string $text): Collection
    {
        return collect((new RulesExtractor)->extract([$text]))->keyBy(fn (Fact $f) => $f->kind.':'.$f->value);
    }

    // ------------------------------------------------------------ the rules

    public function test_an_arabic_sentence_yields_every_real_estate_fact_it_carries(): void
    {
        $facts = $this->read('ابغى اشتري فيلا 4 غرف في حي الياسمين كاش للسكن وابي احجز وحدة');

        $this->assertTrue($facts->has('transaction:buy'));
        $this->assertTrue($facts->has('service_interest:villa'));
        $this->assertTrue($facts->has('bedrooms:4'));
        $this->assertTrue($facts->has('district:الياسمين'));
        $this->assertTrue($facts->has('payment:cash'));
        $this->assertTrue($facts->has('purpose:residence'));
        $this->assertTrue($facts->has('reservation_intent:ready'));
    }

    public function test_english_works_too(): void
    {
        $facts = $this->read('I want to rent a two bedrooms apartment in the Olaya district, financing is not needed. How to reserve?');

        $this->assertTrue($facts->has('transaction:rent'));
        $this->assertTrue($facts->has('bedrooms:2'));
        $this->assertTrue($facts->has('district:olaya'));
        $this->assertTrue($facts->has('reservation_intent:interested'));
    }

    public function test_spoken_numbers_of_rooms_and_a_district_name_stop_where_the_name_stops(): void
    {
        $this->assertTrue($this->read('ابي شقة ثلاث غرف')->has('bedrooms:3'));
        $this->assertTrue($this->read('ابي غرفتين')->has('bedrooms:2'));
        $this->assertTrue($this->read('ابغى في حي الملقا قريب من الجامعة')->has('district:الملقا'));
        $this->assertTrue($this->read('حي النرجس او الياسمين')->has('district:النرجس'));
        $this->assertTrue($this->read('حي الورود الشمالية في الرياض')->has('district:الورود الشماليه'), 'two words, and not the city after it');
    }

    public function test_a_rental_yield_is_an_investment_not_a_tenancy_and_noise_yields_nothing(): void
    {
        $facts = $this->read('ابغى استثمار بعائد ايجاري جيد');

        $this->assertTrue($facts->has('purpose:investment'));
        $this->assertFalse($facts->has('transaction:rent'));

        $none = collect((new RulesExtractor)->extract(['شكرا لكم']))->pluck('kind')->all();
        $this->assertSame(['sentiment'], $none);
        $this->assertFalse($this->read('عندي 3 اطفال')->has('bedrooms:3'), 'a count of children is not rooms');
        $this->assertFalse($this->read('رقمي 0551234567')->contains(fn (Fact $f) => $f->kind === 'bedrooms'));
    }

    public function test_free_valued_facts_are_held_to_their_shape(): void
    {
        $this->assertTrue(Vocabulary::valid(Vocabulary::BEDROOMS, '4'));
        $this->assertFalse(Vocabulary::valid(Vocabulary::BEDROOMS, '0'));
        $this->assertFalse(Vocabulary::valid(Vocabulary::BEDROOMS, '55'));
        $this->assertFalse(Vocabulary::valid(Vocabulary::BEDROOMS, '3 rooms'));
        $this->assertTrue(Vocabulary::valid(Vocabulary::DISTRICT, 'الياسمين'));
        $this->assertFalse(Vocabulary::valid(Vocabulary::DISTRICT, '<script>alert(1)</script>'));
        $this->assertFalse(Vocabulary::valid(Vocabulary::DISTRICT, 'x'));
        $this->assertFalse(Vocabulary::valid(Vocabulary::DISTRICT, str_repeat('ا', 41)));
        $this->assertTrue(Vocabulary::valid(Vocabulary::PAYMENT, 'cash'));
        $this->assertFalse(Vocabulary::valid(Vocabulary::PAYMENT, 'barter'));
    }

    // ------------------------------------------------------------ onto the lead

    public function test_what_the_customer_says_fills_the_lead_and_moves_the_score_at_once(): void
    {
        $lead = $this->lead();
        $before = $this->fresh($lead)->score;
        $this->say($lead, 'ابغى اشتري فيلا 4 غرف في حي الياسمين كاش للسكن');

        $this->analyse($lead);
        $lead = $this->fresh($lead);

        $this->assertSame('buy', $lead->transaction_type);
        $this->assertSame('cash', $lead->payment_method);
        $this->assertSame('residence', $lead->purchase_purpose);
        $this->assertSame(4, $lead->bedrooms);
        $this->assertSame('الياسمين', $lead->preferred_district);
        $this->assertSame('villa', $lead->preferred_property_type);
        $this->assertGreaterThan($before, $lead->score, 'the answers count the moment they are given');
    }

    public function test_an_answer_somebody_already_recorded_is_never_overwritten(): void
    {
        $lead = $this->lead(['payment_method' => 'financing', 'bedrooms' => 3, 'preferred_district' => 'النرجس', 'transaction_type' => 'rent']);
        $this->say($lead, 'ابغى اشتري 5 غرف في حي الياسمين كاش');

        $this->analyse($lead);
        $lead = $this->fresh($lead);

        $this->assertSame('financing', $lead->payment_method);
        $this->assertSame(3, $lead->bedrooms);
        $this->assertSame('النرجس', $lead->preferred_district);
        $this->assertSame('rent', $lead->transaction_type);
    }

    public function test_a_reservation_the_customer_asks_for_is_kept_as_intent(): void
    {
        $lead = $this->lead();
        $this->say($lead, 'ابي احجز وحدة وادفع العربون');

        $this->analyse($lead);

        $this->assertTrue($this->fresh($lead)->reservation_intent);
    }

    public function test_the_conversation_card_carries_the_new_facts(): void
    {
        $lead = $this->lead();
        $this->say($lead, 'ابغى استثمار فيلا 5 غرف في حي الملقا بتمويل');
        $this->analyse($lead);

        $facts = $this->asTenant($this->t['tenant'], fn () => app(ConversationIntelligence::class)->summary($this->fresh($lead)))['facts'];

        $this->assertSame('investment', $facts['purpose']);
        $this->assertSame('financing', $facts['payment']);
        $this->assertSame(5, $facts['bedrooms']);
        $this->assertSame('الملقا', $facts['district']);
    }

    // ------------------------------------------------------------ never asked twice

    private function seedQuestions(): void
    {
        $this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$this->project->uuid}/questions/real-estate")->assertOk();
    }

    public function test_the_real_estate_questions_are_added_once_and_a_managers_own_are_left_alone(): void
    {
        $this->asTenant($this->t['tenant'], fn () => ProjectQuestion::create([
            'project_id' => $this->project->id, 'key' => 'budget', 'label' => 'سؤال المدير الخاص', 'type' => 'number', 'is_active' => true, 'sort_order' => 1,
        ]));

        $first = $this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$this->project->uuid}/questions/real-estate")->assertOk();
        $second = $this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$this->project->uuid}/questions/real-estate")->assertOk();

        $this->assertSame(8, $first->json('data.created'), 'nine questions, one of which the manager already wrote');
        $this->assertSame(0, $second->json('data.created'));
        $this->assertCount(9, $second->json('data.questions'));
        $this->assertSame('سؤال المدير الخاص', collect($second->json('data.questions'))->firstWhere('key', 'budget')['label']);

        $reservation = collect($second->json('data.questions'))->firstWhere('key', 'reservation');
        $this->assertSame('priority', $reservation['rules'][0]['effect']);
    }

    public function test_only_a_manager_can_add_them_and_only_to_their_own_project(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->actingAs($rep)->postJson("/api/v1/projects/{$this->project->uuid}/questions/real-estate")->assertForbidden();

        $other = $this->provisionTenant('Rival Co');
        $this->actingAs($other['admin'])->postJson("/api/v1/projects/{$this->project->uuid}/questions/real-estate")->assertNotFound();
    }

    public function test_what_was_said_answers_the_matching_questions_so_they_are_not_asked_again(): void
    {
        $this->seedQuestions();
        $lead = $this->lead();
        $this->say($lead, 'ابغى اشتري فيلا 4 غرف في حي الياسمين كاش');

        $this->analyse($lead);

        $answered = $this->asTenant($this->t['tenant'], fn () => LeadAnswer::where('lead_id', $lead->id)->with('question')->get()->pluck('value', 'question.key')->all());
        $this->assertSame('buy', $answered['transaction']);
        $this->assertSame('cash', $answered['payment']);
        $this->assertSame(4, $answered['bedrooms']);
        $this->assertSame('الياسمين', $answered['district']);
        $this->assertSame('villa', $answered['property_type']);

        $left = $this->asTenant($this->t['tenant'], fn () => app(QualificationCompleter::class)->unanswered($this->fresh($lead))->pluck('key')->all());
        $this->assertNotContains('transaction', $left);
        $this->assertNotContains('bedrooms', $left);
        $this->assertContains('visit', $left, 'what was not said is still to ask');
        $this->assertContains('reservation', $left);
    }

    // ------------------------------------------------------------ what is still unknown

    private function gaps(Lead $lead): array
    {
        return $this->asTenant($this->t['tenant'], fn () => app(QualificationGaps::class)->for($this->fresh($lead)));
    }

    public function test_a_lead_with_nothing_but_a_project_has_every_other_question_to_ask_in_order(): void
    {
        $gaps = $this->gaps($this->lead());

        $this->assertSame(['project'], $gaps['known']);
        $this->assertSame(['transaction', 'budget', 'district', 'property_type', 'bedrooms', 'payment', 'timeline', 'visit', 'reservation'], $gaps['gaps']);
        $this->assertSame('transaction', $gaps['next']);
        $this->assertSame(10, $gaps['percent'], '1 of 10');
    }

    public function test_the_gaps_close_as_the_customer_answers_and_next_moves_on(): void
    {
        $lead = $this->lead();
        $this->say($lead, 'ابغى اشتري فيلا 4 غرف في حي الياسمين كاش');
        $this->analyse($lead);

        $gaps = $this->gaps($lead);

        $this->assertEqualsCanonicalizing(['project', 'transaction', 'district', 'property_type', 'bedrooms', 'payment'], $gaps['known']);
        $this->assertSame(['budget', 'timeline', 'visit', 'reservation'], $gaps['gaps']);
        $this->assertSame('budget', $gaps['next']);
        $this->assertSame(60, $gaps['percent']);
    }

    public function test_a_no_to_a_visit_is_an_answer_and_a_plot_has_no_bedrooms_to_ask(): void
    {
        $lead = $this->lead(['preferred_property_type' => 'land', 'appointment_interest' => false]);

        $gaps = $this->gaps($lead);

        $this->assertContains('visit', $gaps['known']);
        $this->assertNotContains('bedrooms', $gaps['gaps']);
        $this->assertNotContains('bedrooms', $gaps['known']);
    }

    public function test_the_lead_screen_carries_what_is_missing_and_the_next_question(): void
    {
        $lead = $this->lead(['budget_max' => 900000]);

        $this->actingAs($this->t['admin'])->getJson("/api/v1/leads/{$lead->uuid}")->assertOk()
            ->assertJsonPath('data.qualification_gaps.next', 'transaction')
            ->assertJsonPath('data.qualification_gaps.known', ['project', 'budget']);
    }

    // ------------------------------------------------------------ Claude, held to the same rules

    public function test_claudes_new_facts_are_validated_like_the_rules(): void
    {
        $this->asTenant($this->t['tenant'], fn () => TenantSetting::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->first()->forceFill(['ai_api_key' => 'sk-ant-test'])->save());
        $lead = $this->lead();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'record_facts', 'input' => [
            'intent' => 'interested', 'transaction' => 'buy', 'payment' => 'barter', 'purpose' => 'investment', 'reservation_intent' => 'ready',
            'bedrooms' => 99, 'district' => '<b>النرجس</b>',
        ]]]], 200)]);
        $this->say($lead, 'ابغى استثمار');

        $run = $this->analyse($lead);

        $this->assertSame('claude', $run['engine']);
        $kinds = $this->asTenant($this->t['tenant'], fn () => LeadFact::where('lead_id', $lead->id)->pluck('value', 'kind')->all());
        $this->assertSame('buy', $kinds['transaction']);
        $this->assertSame('investment', $kinds['purpose']);
        $this->assertSame('ready', $kinds['reservation_intent']);
        $this->assertArrayNotHasKey('payment', $kinds, 'barter is not on the list');
        $this->assertArrayNotHasKey('bedrooms', $kinds, '99 rooms is not a number of rooms');
        $this->assertArrayNotHasKey('district', $kinds, 'markup is not a district');
    }
}

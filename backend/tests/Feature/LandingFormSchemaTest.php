<?php

namespace Tests\Feature;

use App\Domain\Tracking\Forms\FormSchema;
use Tests\TestCase;

/**
 * A smart form is a list of fields, and the list is the contract: what may be asked, what must be answered,
 * what shows only after something else was chosen, and where each answer lands on the lead. Pure logic — no
 * database — so every rule is fast to pin down.
 */
class LandingFormSchemaTest extends TestCase
{
    private function field(string $key, array $overrides = []): array
    {
        return $overrides + ['key' => $key, 'type' => 'text', 'label' => $key, 'required' => false, 'mapping' => 'none'];
    }

    private function schema(): FormSchema
    {
        return new FormSchema;
    }

    private function errorsOf(array $fields): array
    {
        return $this->schema()->definitionErrors($fields);
    }

    // ---------------------------------------------------------------- the definition

    public function test_a_sound_definition_has_no_errors_and_is_ordered(): void
    {
        $fields = [
            $this->field('name', ['type' => 'text', 'label' => 'الاسم', 'required' => true, 'mapping' => 'lead.full_name', 'order' => 2]),
            $this->field('mobile', ['type' => 'phone', 'label' => 'الجوال', 'required' => true, 'mapping' => 'lead.phone', 'order' => 1]),
            $this->field('budget', ['type' => 'number', 'label' => 'الميزانية', 'mapping' => 'lead.budget_max', 'validation' => ['min' => 100000]]),
        ];

        $this->assertSame([], $this->errorsOf($fields));
        $this->assertSame(['mobile', 'name', 'budget'], array_column($this->schema()->normalize($fields), 'key'));
    }

    public function test_the_definition_refuses_what_could_not_work(): void
    {
        $cases = [
            'two fields with one key' => [[$this->field('a'), $this->field('a')], 'a'],
            'a key that is not a key' => [[$this->field('Bad Key!')], 'Bad Key!'],
            'an unknown type' => [[$this->field('a', ['type' => 'colour'])], 'a'],
            'a select with no options' => [[$this->field('a', ['type' => 'select'])], 'a'],
            'an unknown mapping' => [[$this->field('a', ['mapping' => 'lead.password'])], 'a'],
            'two fields onto one lead column' => [[$this->field('a', ['mapping' => 'lead.phone']), $this->field('b', ['mapping' => 'lead.phone'])], 'b'],
            'a condition on a field that is not there' => [[$this->field('a', ['visible_if' => ['field' => 'ghost', 'equals' => 'x']])], 'a'],
            'a label of nothing' => [[$this->field('a', ['label' => ''])], 'a'],
            'a hidden field the visitor must fill' => [[$this->field('a', ['hidden' => true, 'required' => true])], 'a'],
            'too many fields' => [array_map(fn ($i) => $this->field("f{$i}"), range(1, 61)), 'f61'],
        ];

        foreach ($cases as $name => [$fields, $key]) {
            $errors = $this->errorsOf($fields);
            $this->assertNotEmpty($errors, "{$name} was accepted");
            $this->assertArrayHasKey($key, $errors, "{$name}: the error does not name the field");
        }
    }

    public function test_a_phone_or_an_email_the_lead_cannot_be_reached_by_is_not_a_form(): void
    {
        $errors = $this->errorsOf([$this->field('name', ['mapping' => 'lead.full_name', 'required' => true])]);

        $this->assertArrayHasKey('_form', $errors, 'a form that can create a lead nobody can contact');
    }

    // ---------------------------------------------------------------- a submission

    private function fields(): array
    {
        return $this->schema()->normalize([
            $this->field('name', ['label' => 'الاسم', 'required' => true, 'mapping' => 'lead.full_name']),
            $this->field('mobile', ['type' => 'phone', 'label' => 'الجوال', 'required' => true, 'mapping' => 'lead.phone']),
            $this->field('email', ['type' => 'email', 'label' => 'البريد', 'mapping' => 'lead.email']),
            $this->field('purpose', ['type' => 'select', 'label' => 'الغرض', 'mapping' => 'lead.purchase_purpose', 'options' => [['value' => 'residence', 'label' => 'سكن'], ['value' => 'investment', 'label' => 'استثمار']]]),
            $this->field('yield', ['type' => 'number', 'label' => 'العائد', 'mapping' => 'custom.target_yield', 'visible_if' => ['field' => 'purpose', 'equals' => 'investment'], 'required' => true, 'validation' => ['min' => 1, 'max' => 30]]),
            $this->field('budget', ['type' => 'number', 'label' => 'الميزانية', 'mapping' => 'lead.budget_max']),
            $this->field('areas', ['type' => 'multi_select', 'label' => 'الأحياء', 'mapping' => 'custom.areas', 'options' => [['value' => 'north', 'label' => 'شمال'], ['value' => 'east', 'label' => 'شرق']]]),
            $this->field('when', ['type' => 'date', 'label' => 'متى', 'mapping' => 'lead.purchase_timeline']),
            $this->field('agree', ['type' => 'checkbox', 'label' => 'أوافق على التواصل', 'mapping' => 'custom.agreed']),
            $this->field('source', ['type' => 'hidden', 'label' => 'المصدر', 'hidden' => true, 'default' => '@source', 'mapping' => 'tracking.source']),
        ]);
    }

    public function test_a_good_submission_passes_and_what_was_not_asked_is_dropped(): void
    {
        $result = $this->schema()->validateSubmission($this->fields(), [
            'name' => '  سلمان القحطاني ', 'mobile' => '0551234567', 'email' => 'salman@example.com', 'purpose' => 'residence', 'budget' => '1500000',
            'areas' => ['north'], 'agree' => true, 'password' => 'hunter2', 'isAdmin' => true,
        ], ['source' => 'meta']);

        $this->assertSame([], $result['errors']);
        $this->assertSame('سلمان القحطاني', $result['values']['name'], 'trimmed');
        $this->assertSame(1500000.0, (float) $result['values']['budget']);
        $this->assertSame('meta', $result['values']['source'], 'the hidden field comes from the server, not the visitor');
        $this->assertArrayNotHasKey('password', $result['values']);
        $this->assertSame(['password', 'isAdmin'], $result['dropped'], 'what was dropped is told, never kept');
        $this->assertArrayNotHasKey('yield', $result['values'], 'a field that stayed hidden is not part of the answer');
    }

    public function test_required_fields_and_conditions(): void
    {
        $missing = $this->schema()->validateSubmission($this->fields(), ['name' => '', 'mobile' => ''], []);
        $this->assertEqualsCanonicalizing(['name', 'mobile'], array_keys($missing['errors']));

        // The condition makes the yield required — only once the purpose is investment.
        $inv = $this->schema()->validateSubmission($this->fields(), ['name' => 'س', 'mobile' => '0551234567', 'purpose' => 'investment'], []);
        $this->assertArrayHasKey('yield', $inv['errors']);

        $ok = $this->schema()->validateSubmission($this->fields(), ['name' => 'س', 'mobile' => '0551234567', 'purpose' => 'investment', 'yield' => '8'], []);
        $this->assertSame([], $ok['errors']);
    }

    public function test_each_type_checks_its_own_shape(): void
    {
        $bad = $this->schema()->validateSubmission($this->fields(), [
            'name' => 'س', 'mobile' => 'abc', 'email' => 'not-an-email', 'purpose' => 'barter', 'budget' => 'lots', 'areas' => ['west'], 'when' => '31/02/2026',
        ], []);

        foreach (['mobile', 'email', 'purpose', 'budget', 'areas', 'when'] as $key) {
            $this->assertArrayHasKey($key, $bad['errors'], "{$key} accepted a value that is not its type");
        }

        $range = $this->schema()->validateSubmission($this->fields(), ['name' => 'س', 'mobile' => '0551234567', 'purpose' => 'investment', 'yield' => '99'], []);
        $this->assertArrayHasKey('yield', $range['errors'], 'a number over its maximum');

        $long = $this->schema()->validateSubmission($this->fields(), ['name' => str_repeat('ا', 300), 'mobile' => '0551234567'], []);
        $this->assertArrayHasKey('name', $long['errors'], 'a text longer than any name');
    }

    // ---------------------------------------------------------------- onto the lead

    public function test_answers_land_where_the_field_says_and_nothing_is_lost(): void
    {
        $values = ['name' => 'سلمان القحطاني', 'mobile' => '0551234567', 'email' => 'salman@example.com', 'purpose' => 'investment', 'yield' => 8, 'budget' => 2000000, 'areas' => ['north', 'east'], 'agree' => true, 'source' => 'meta'];

        $incoming = $this->schema()->toLead($this->fields(), $values);

        $this->assertSame('سلمان القحطاني', $incoming['full_name']);
        $this->assertSame('0551234567', $incoming['phone']);
        $this->assertSame('salman@example.com', $incoming['email']);
        $this->assertSame('investment', $incoming['purchase_purpose']);
        $this->assertSame(2000000.0, (float) $incoming['budget_max']);
        $this->assertSame(['target_yield' => 8, 'areas' => ['north', 'east'], 'agreed' => true], $incoming['custom']);
        $this->assertSame(['target_yield' => 'العائد', 'areas' => 'الأحياء', 'agreed' => 'أوافق على التواصل'], $incoming['custom_labels']);
        $this->assertSame('meta', $incoming['tracking']['source'], 'tracking metadata is kept apart from the lead\'s own answers');
        $this->assertSame($values, $incoming['raw'], 'the whole submission is kept as it came');
    }

    public function test_the_public_shape_shows_the_visitor_what_to_fill_and_nothing_about_where_it_goes(): void
    {
        $public = $this->schema()->publicShape($this->fields());
        $encoded = json_encode($public, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('lead.', $encoded, 'the mapping is the workspace\'s business');
        $this->assertStringNotContainsString('tracking.', $encoded);
        $this->assertStringNotContainsString('@source', $encoded, 'a hidden field\'s default is not shown');
        $this->assertNotContains('source', array_column($public, 'key'), 'a hidden field is not rendered at all');
        $this->assertSame('purpose', collect($public)->firstWhere('key', 'yield')['visible_if']['field'], 'but a condition is, so the page can honour it');
    }
}

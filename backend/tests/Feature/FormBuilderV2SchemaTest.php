<?php

namespace Tests\Feature;

use App\Domain\Tracking\Forms\FormLayout;
use App\Domain\Tracking\Forms\FormSchema;
use Tests\TestCase;

/**
 * The advanced form: sections, rows and columns; a matrix; a repeater; conditions on a field, a row and a section; display
 * elements; phone numbers with a country; validation with its own words. Pure logic, so every rule is pinned on its own.
 */
class FormBuilderV2SchemaTest extends TestCase
{
    private function field(string $key, array $o = []): array
    {
        return $o + ['key' => $key, 'type' => 'text', 'label' => $key, 'required' => false, 'mapping' => 'none'];
    }

    private function schema(): FormSchema
    {
        return new FormSchema;
    }

    private function matrix(array $o = []): array
    {
        return $this->field('priorities', ['type' => 'matrix', 'label' => 'ما أهمية…', 'mapping' => 'custom.priorities', 'matrix' => $o + [
            'rows' => [['value' => 'location', 'label' => 'الموقع'], ['value' => 'price', 'label' => 'السعر'], ['value' => 'area', 'label' => 'المساحة']],
            'columns' => [['value' => 'low', 'label' => 'غير مهم'], ['value' => 'mid', 'label' => 'مهم'], ['value' => 'high', 'label' => 'مهم جدًا']],
        ]]);
    }

    private function repeater(array $o = []): array
    {
        return $this->field('guests', ['type' => 'repeater', 'label' => 'أضف الأشخاص المهتمين', 'mapping' => 'custom.guests', 'repeater' => $o + [
            'subfields' => [
                ['key' => 'name', 'type' => 'text', 'label' => 'الاسم', 'required' => true],
                ['key' => 'mobile', 'type' => 'phone', 'label' => 'الجوال'],
                ['key' => 'relation', 'type' => 'select', 'label' => 'العلاقة', 'options' => [['value' => 'wife', 'label' => 'زوجة'], ['value' => 'son', 'label' => 'ابن']]],
            ],
            'min_rows' => 0, 'max_rows' => 3, 'default_rows' => 1,
        ]]);
    }

    private function base(array $extra = []): array
    {
        return [
            $this->field('name', ['label' => 'الاسم', 'required' => true, 'mapping' => 'lead.full_name']),
            $this->field('mobile', ['type' => 'phone', 'label' => 'الجوال', 'required' => true, 'mapping' => 'lead.phone']),
            ...$extra,
        ];
    }

    private function check(array $fields, array $input, array $options = []): array
    {
        $normal = $this->schema()->normalize($fields);

        return $this->schema()->validateSubmission($normal, $input, [], (new FormLayout)->derive($normal), $options);
    }

    // ---------------------------------------------------------------- the definition

    public function test_the_new_types_are_known_and_a_display_element_needs_no_answer(): void
    {
        $fields = $this->base([
            $this->field('intro', ['type' => 'heading', 'label' => 'أخبرنا عن اهتمامك']),
            $this->field('note', ['type' => 'paragraph', 'label' => 'سنتواصل معك خلال ساعات']),
            $this->field('rule', ['type' => 'divider', 'label' => '']),
            $this->field('visit', ['type' => 'time', 'label' => 'وقت الزيارة']),
            $this->field('agree', ['type' => 'consent', 'label' => 'أوافق على استلام العروض', 'required' => true, 'mapping' => 'custom.marketing']),
        ]);

        $this->assertSame([], $this->schema()->definitionErrors($fields));
    }

    public function test_a_display_element_cannot_be_mapped_or_required(): void
    {
        $e = $this->schema()->definitionErrors($this->base([$this->field('h', ['type' => 'heading', 'label' => 'x', 'mapping' => 'lead.city'])]));
        $this->assertSame('display_cannot_be_mapped', $e['h']);

        $normal = $this->schema()->normalize($this->base([$this->field('h', ['type' => 'heading', 'label' => 'x', 'required' => true])]));
        $this->assertFalse(collect($normal)->firstWhere('key', 'h')['required'], 'nothing to answer, nothing to require');
    }

    public function test_a_matrix_needs_rows_and_at_least_two_columns_that_are_distinct(): void
    {
        $this->assertSame([], $this->schema()->definitionErrors($this->base([$this->matrix()])));

        $cases = [
            'no rows' => [['rows' => []], 'matrix_rows_required'],
            'one column' => [['columns' => [['value' => 'a', 'label' => 'A']]], 'matrix_columns_required'],
            'a repeated column' => [['columns' => [['value' => 'a', 'label' => 'A'], ['value' => 'a', 'label' => 'B']]], 'matrix_duplicate_value'],
            'a minimum above the rows' => [['min_answers' => 9], 'matrix_min_answers'],
        ];

        foreach ($cases as $name => [$override, $code]) {
            $this->assertSame($code, $this->schema()->definitionErrors($this->base([$this->matrix($override)]))['priorities'] ?? null, $name);
        }
    }

    public function test_a_matrix_or_a_repeater_lands_only_in_a_custom_field(): void
    {
        $bad = $this->matrix();
        $bad['mapping'] = 'lead.city';

        $this->assertSame('structured_mapping_only_custom', $this->schema()->definitionErrors($this->base([$bad]))['priorities']);

        $none = $this->repeater();
        $none['mapping'] = 'none';
        $this->assertSame([], $this->schema()->definitionErrors($this->base([$none])), 'kept in the submission only');
    }

    public function test_a_repeater_needs_columns_that_make_sense(): void
    {
        $this->assertSame([], $this->schema()->definitionErrors($this->base([$this->repeater()])));

        $cases = [
            'no columns' => [['subfields' => []], 'repeater_subfields_required'],
            'a bad key' => [['subfields' => [['key' => 'Bad Key', 'type' => 'text', 'label' => 'x']]], 'repeater_subfield_invalid'],
            'a nested repeater' => [['subfields' => [['key' => 'x', 'type' => 'repeater', 'label' => 'x']]], 'repeater_subfield_invalid'],
            'a select with no choices' => [['subfields' => [['key' => 'x', 'type' => 'select', 'label' => 'x']]], 'repeater_subfield_invalid'],
            'min above max' => [['min_rows' => 5, 'max_rows' => 2], 'repeater_rows_invalid'],
            'more rows than the cap' => [['max_rows' => 99], 'repeater_rows_invalid'],
        ];

        foreach ($cases as $name => [$override, $code]) {
            $this->assertSame($code, $this->schema()->definitionErrors($this->base([$this->repeater($override)]))['guests'] ?? null, $name);
        }
    }

    public function test_a_condition_must_point_at_a_field_that_is_there_and_not_at_itself(): void
    {
        $cond = fn ($field) => ['match' => 'all', 'conditions' => [['field' => $field, 'op' => 'equals', 'value' => 'x']]];

        $this->assertSame([], $this->schema()->definitionErrors($this->base([$this->field('a', ['show_when' => $cond('name')])])));
        $this->assertSame('invalid_condition', $this->schema()->definitionErrors($this->base([$this->field('a', ['show_when' => $cond('ghost')])]))['a']);
        $this->assertSame('invalid_condition', $this->schema()->definitionErrors($this->base([$this->field('a', ['hide_when' => $cond('a')])]))['a']);
        $this->assertSame('invalid_condition', $this->schema()->definitionErrors($this->base([$this->field('a', ['require_when' => ['match' => 'all', 'conditions' => [['field' => 'name', 'op' => 'sounds_like']]]])]))['a']);
    }

    public function test_an_unsafe_pattern_or_an_inverted_range_is_refused_with_the_field(): void
    {
        $this->assertSame('invalid_pattern', $this->schema()->definitionErrors($this->base([$this->field('a', ['validation' => ['pattern' => '([a-z']])]))['a']);
        $this->assertSame('invalid_validation', $this->schema()->definitionErrors($this->base([$this->field('a', ['type' => 'number', 'validation' => ['min' => 10, 'max' => 1]])]))['a']);
        $this->assertSame([], $this->schema()->definitionErrors($this->base([$this->field('a', ['validation' => ['pattern' => '^[A-Z]{2}\d{4}$']])])));
    }

    public function test_the_layout_places_every_field_once_and_loses_none(): void
    {
        $fields = $this->schema()->normalize($this->base([$this->field('city'), $this->field('note')]));
        $layout = (new FormLayout)->normalize(['sections' => [['rows' => [['columns' => [['fields' => ['name']], ['fields' => ['mobile', 'ghost', 'name']]]]]]]], $fields);

        $placed = collect($layout['sections'])->flatMap(fn ($s) => collect($s['rows'])->flatMap(fn ($r) => collect($r['columns'])->flatMap(fn ($c) => $c['fields'])))->all();

        $this->assertEqualsCanonicalizing(['name', 'mobile', 'city', 'note'], $placed, 'an unknown key and a repeat are dropped; what nobody placed is asked at the end');
        $this->assertCount(4, $placed);
    }

    public function test_a_layout_that_could_not_work_is_refused(): void
    {
        $fields = $this->base();
        $five = ['sections' => [['rows' => [['columns' => array_map(fn ($i) => ['fields' => []], range(1, 5))]]]]];

        $this->assertSame('layout_columns', $this->schema()->definitionErrors($fields, $five)['_layout']);
        $this->assertSame('layout_unknown_field', $this->schema()->definitionErrors($fields, ['sections' => [['rows' => [['columns' => [['fields' => ['ghost']]]]]]]])['_layout']);
        $this->assertSame('layout_duplicate_field', $this->schema()->definitionErrors($fields, ['sections' => [['rows' => [['columns' => [['fields' => ['name']], ['fields' => ['name']]]]]]]])['_layout']);
    }

    public function test_a_form_with_no_layout_gets_one_from_its_steps(): void
    {
        $fields = $this->schema()->normalize([$this->field('a', ['step' => 1]), $this->field('b', ['step' => 2]), $this->field('c', ['step' => 2])]);
        $layout = (new FormLayout)->derive($fields);

        $this->assertCount(2, $layout['sections']);
        $this->assertCount(2, $layout['sections'][1]['rows']);
    }

    // ---------------------------------------------------------------- a submission

    public function test_a_matrix_answer_is_kept_as_structured_data(): void
    {
        $r = $this->check($this->base([$this->matrix()]), ['name' => 'س', 'mobile' => '0551234567', 'priorities' => ['location' => 'high', 'price' => 'mid']]);

        $this->assertSame([], $r['errors']);
        $this->assertSame(['location' => 'high', 'price' => 'mid'], $r['values']['priorities']);
    }

    public function test_a_matrix_refuses_what_is_not_on_the_grid(): void
    {
        foreach ([['ghost' => 'high'], ['location' => 'extreme'], ['location' => ['high']], 'high'] as $bad) {
            $r = $this->check($this->base([$this->matrix()]), ['name' => 'س', 'mobile' => '0551234567', 'priorities' => $bad]);
            $this->assertSame('invalid_matrix', $r['errors']['priorities'] ?? null, json_encode($bad));
        }
    }

    public function test_a_matrix_can_demand_every_row_or_a_minimum(): void
    {
        $all = $this->base([$this->matrix(['require_all_rows' => true])]);
        $this->assertSame('matrix_incomplete', $this->check($all, ['name' => 'س', 'mobile' => '0551234567', 'priorities' => ['location' => 'high']])['errors']['priorities']);
        $this->assertSame([], $this->check($all, ['name' => 'س', 'mobile' => '0551234567', 'priorities' => ['location' => 'high', 'price' => 'low', 'area' => 'mid']])['errors']);

        $two = $this->base([$this->matrix(['min_answers' => 2])]);
        $this->assertSame('matrix_incomplete', $this->check($two, ['name' => 'س', 'mobile' => '0551234567', 'priorities' => ['location' => 'high']])['errors']['priorities']);
        $this->assertSame('required', $this->check($two, ['name' => 'س', 'mobile' => '0551234567'])['errors']['priorities'], 'a minimum above zero is a demand');
    }

    public function test_a_matrix_that_allows_many_takes_a_list_for_each_row(): void
    {
        $fields = $this->base([$this->matrix(['multiple' => true])]);
        $r = $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'priorities' => ['location' => ['mid', 'high'], 'price' => []]]);

        $this->assertSame([], $r['errors']);
        $this->assertSame(['location' => ['mid', 'high']], $r['values']['priorities'], 'an empty row is no answer');
        $this->assertSame('invalid_matrix', $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'priorities' => ['location' => ['nope']]])['errors']['priorities']);
    }

    public function test_an_optional_matrix_may_be_left_alone(): void
    {
        $this->assertSame([], $this->check($this->base([$this->matrix()]), ['name' => 'س', 'mobile' => '0551234567'])['errors']);
        $this->assertSame([], $this->check($this->base([$this->matrix()]), ['name' => 'س', 'mobile' => '0551234567', 'priorities' => []])['errors']);
    }

    public function test_a_repeater_keeps_each_row_checked_by_column_and_drops_an_empty_one(): void
    {
        $r = $this->check($this->base([$this->repeater()]), ['name' => 'س', 'mobile' => '0551234567', 'guests' => [
            ['name' => 'نورة', 'mobile' => '0555000111', 'relation' => 'wife'],
            ['name' => '', 'mobile' => '', 'relation' => ''],
            ['name' => 'خالد', 'relation' => 'son'],
        ]]);

        $this->assertSame([], $r['errors']);
        $this->assertSame([['name' => 'نورة', 'mobile' => '+966555000111', 'relation' => 'wife'], ['name' => 'خالد', 'relation' => 'son']], $r['values']['guests']);
    }

    public function test_a_repeater_names_the_row_and_the_column_that_is_wrong(): void
    {
        $r = $this->check($this->base([$this->repeater()]), ['name' => 'س', 'mobile' => '0551234567', 'guests' => [
            ['name' => 'نورة', 'mobile' => '0555000111'],
            ['mobile' => '12', 'relation' => 'cousin'],
        ]]);

        $this->assertSame('invalid_phone', $r['errors']['guests.1.mobile']);
        $this->assertSame('invalid_option', $r['errors']['guests.1.relation']);
        $this->assertSame('required', $r['errors']['guests.1.name']);
        $this->assertArrayNotHasKey('guests.0.name', $r['errors']);
    }

    public function test_a_repeater_holds_its_row_count(): void
    {
        $row = fn ($n) => ['name' => "ضيف {$n}"];
        $over = $this->check($this->base([$this->repeater()]), ['name' => 'س', 'mobile' => '0551234567', 'guests' => [$row(1), $row(2), $row(3), $row(4)]]);
        $this->assertSame('too_many_rows', $over['errors']['guests']);

        $min = $this->base([$this->repeater(['min_rows' => 2, 'default_rows' => 2])]);
        $this->assertSame('too_few_rows', $this->check($min, ['name' => 'س', 'mobile' => '0551234567', 'guests' => [$row(1)]])['errors']['guests']);
        $this->assertSame('required', $this->check($min, ['name' => 'س', 'mobile' => '0551234567'])['errors']['guests']);
        $this->assertSame([], $this->check($min, ['name' => 'س', 'mobile' => '0551234567', 'guests' => [$row(1), $row(2)]])['errors']);
        $this->assertSame('invalid_repeater', $this->check($this->base([$this->repeater()]), ['name' => 'س', 'mobile' => '0551234567', 'guests' => ['x' => 'y']])['errors']['guests']);
    }

    public function test_a_hidden_field_cannot_be_demanded_and_its_answer_is_not_kept(): void
    {
        $fields = $this->base([
            $this->field('purpose', ['type' => 'radio', 'options' => [['value' => 'residence'], ['value' => 'investment']]]),
            $this->field('yield', ['type' => 'number', 'required' => true, 'show_when' => ['match' => 'all', 'conditions' => [['field' => 'purpose', 'op' => 'equals', 'value' => 'investment']]]]),
        ]);

        $own = $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'purpose' => 'residence', 'yield' => '9']);
        $this->assertSame([], $own['errors']);
        $this->assertArrayNotHasKey('yield', $own['values'], 'an answer to a question that was not shown is not an answer');

        $this->assertSame('required', $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'purpose' => 'investment'])['errors']['yield']);
    }

    public function test_a_whole_row_or_section_can_hide_and_what_is_in_it_is_not_demanded(): void
    {
        $normal = $this->schema()->normalize($this->base([
            $this->field('purpose', ['type' => 'radio', 'options' => [['value' => 'own'], ['value' => 'invest']]]),
            $this->field('horizon', ['required' => true]),
        ]));
        $layout = (new FormLayout)->normalize(['sections' => [
            ['rows' => [['columns' => [['fields' => ['name']]]], ['columns' => [['fields' => ['mobile']]]], ['columns' => [['fields' => ['purpose']]]]]],
            ['show_when' => ['match' => 'all', 'conditions' => [['field' => 'purpose', 'op' => 'equals', 'value' => 'invest']]], 'rows' => [['columns' => [['fields' => ['horizon']]]]]],
        ]], $normal);

        $own = $this->schema()->validateSubmission($normal, ['name' => 'س', 'mobile' => '0551234567', 'purpose' => 'own'], [], $layout);
        $this->assertSame([], $own['errors']);

        $invest = $this->schema()->validateSubmission($normal, ['name' => 'س', 'mobile' => '0551234567', 'purpose' => 'invest'], [], $layout);
        $this->assertSame('required', $invest['errors']['horizon']);
    }

    public function test_require_when_makes_an_optional_field_required(): void
    {
        $fields = $this->base([
            $this->field('financing', ['type' => 'select', 'options' => [['value' => 'cash'], ['value' => 'mortgage']]]),
            $this->field('bank', ['require_when' => ['match' => 'all', 'conditions' => [['field' => 'financing', 'op' => 'equals', 'value' => 'mortgage']]]]),
        ]);

        $this->assertSame([], $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'financing' => 'cash'])['errors']);
        $this->assertSame('required', $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'financing' => 'mortgage'])['errors']['bank']);
    }

    public function test_a_required_checkbox_or_consent_must_be_ticked(): void
    {
        $fields = $this->base([$this->field('agree', ['type' => 'consent', 'required' => true, 'label' => 'أوافق'])]);

        $this->assertSame('required', $this->check($fields, ['name' => 'س', 'mobile' => '0551234567'])['errors']['agree']);
        $this->assertSame('required', $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'agree' => false])['errors']['agree'], 'unticked is not given');
        $this->assertSame([], $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'agree' => true])['errors']);
    }

    public function test_a_phone_is_kept_as_one_international_number_whatever_it_was_typed_as(): void
    {
        $fields = $this->base();

        $this->assertSame('+966551234567', $this->check($fields, ['name' => 'س', 'mobile' => '0551234567'])['values']['mobile']);
        $this->assertSame('+971501234567', $this->check($fields, ['name' => 'س', 'mobile' => ['country' => 'AE', 'number' => '050 123 4567']])['values']['mobile']);
        $this->assertSame('+971501234567', $this->check($fields, ['name' => 'س', 'mobile' => '0501234567'], ['default_country' => 'AE'])['values']['mobile'], 'the workspace\'s own country');
        $this->assertSame('invalid_phone', $this->check($fields, ['name' => 'س', 'mobile' => ['country' => 'ZZ', 'number' => '0551234567']])['errors']['mobile']);
    }

    public function test_the_phone_as_typed_is_kept_beside_the_normalised_one(): void
    {
        $r = $this->check($this->base(), ['name' => 'س', 'mobile' => ' 055-123-4567 ']);

        $this->assertSame('+966551234567', $r['values']['mobile']);
        $this->assertSame(' 055-123-4567 ', $r['raw']['mobile'], 'raw and normalised, both');
    }

    public function test_a_field_may_have_a_pattern_a_length_and_its_own_words_for_what_went_wrong(): void
    {
        $fields = $this->base([$this->field('plot', ['label' => 'رقم القطعة', 'validation' => ['pattern' => '^[A-Z]{2}\d{4}$', 'message' => 'مثال: AB1234']])]);
        $normal = $this->schema()->normalize($fields);

        $this->assertSame('invalid_pattern', $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'plot' => 'zz'])['errors']['plot']);
        $this->assertSame([], $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'plot' => 'AB1234'])['errors']);
        $this->assertSame('مثال: AB1234', collect($this->schema()->publicShape($normal))->firstWhere('key', 'plot')['validation']['message'], 'the page shows the manager\'s own words');
    }

    public function test_a_time_is_a_real_time(): void
    {
        $fields = $this->base([$this->field('visit', ['type' => 'time', 'label' => 'وقت'])]);

        $this->assertSame([], $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'visit' => '17:30'])['errors']);
        $this->assertSame('invalid_time', $this->check($fields, ['name' => 'س', 'mobile' => '0551234567', 'visit' => '25:00'])['errors']['visit']);
    }

    public function test_html_in_a_label_or_an_option_is_not_kept(): void
    {
        $normal = $this->schema()->normalize($this->base([$this->field('a', ['label' => 'اسم <script>alert(1)</script>', 'type' => 'select', 'options' => [['value' => 'x', 'label' => '<b>أ</b>']]])]));
        $a = collect($normal)->firstWhere('key', 'a');

        $this->assertStringNotContainsString('<', $a['label']);
        $this->assertSame('أ', $a['options'][0]['label']);
    }

    // ---------------------------------------------------------------- onto the lead

    public function test_a_matrix_and_a_repeater_read_as_text_on_the_lead_and_whole_in_the_raw_submission(): void
    {
        $fields = $this->schema()->normalize($this->base([$this->matrix(), $this->repeater()]));
        $values = [
            'name' => 'س', 'mobile' => '+966551234567',
            'priorities' => ['location' => 'high', 'price' => 'mid'],
            'guests' => [['name' => 'نورة', 'mobile' => '+966555000111', 'relation' => 'wife']],
        ];

        $lead = $this->schema()->toLead($fields, $values);
        $text = $this->schema()->customAsText($fields, $lead['custom']);

        $this->assertSame("الموقع: مهم جدًا\nالسعر: مهم", $text['priorities']);
        $this->assertSame('1) الاسم: نورة، الجوال: +966555000111، العلاقة: زوجة', $text['guests']);
        $this->assertSame($values['priorities'], $lead['raw']['priorities'], 'nothing is lost');
        $this->assertSame($values['guests'], $lead['raw']['guests']);
    }
}

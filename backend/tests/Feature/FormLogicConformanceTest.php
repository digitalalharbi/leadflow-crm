<?php

namespace Tests\Feature;

use App\Domain\Tracking\Forms\FormLayout;
use App\Domain\Tracking\Forms\FormSchema;
use App\Domain\Tracking\Forms\Logic;
use App\Domain\Tracking\Forms\PhoneCountries;
use Tests\TestCase;

/**
 * The server and the page run the same vectors (`frontend/src/features/formVectors.json`), so a condition, a hidden row or a
 * phone number means one thing on both sides of the wire. A rule that differed would show a field the server then refuses, or hide
 * one the server then demands.
 */
class FormLogicConformanceTest extends TestCase
{
    /** @return array<string,mixed> */
    private function vectors(): array
    {
        $path = base_path('../frontend/src/features/formVectors.json');

        if (! is_file($path)) {
            $this->markTestSkipped('The frontend vectors are not beside the backend.');
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_every_logic_vector(): void
    {
        foreach ($this->vectors()['logic'] as $v) {
            $this->assertSame($v['expect'], Logic::passes($v['group'], $v['answers']), $v['name']);
        }
    }

    public function test_every_visibility_vector(): void
    {
        $schema = new FormSchema;

        foreach ($this->vectors()['visibility'] as $set) {
            $fields = $schema->normalize($set['form']['fields']);
            $layout = (new FormLayout)->normalize($set['form']['layout'], $fields);

            foreach ($set['cases'] as $i => $case) {
                $state = (new FormLayout)->visibility($fields, $layout, $case['answers']);

                $this->assertEqualsCanonicalizing($case['visible'], $state['visible'], "{$set['name']} #{$i}: visible");
                $this->assertEqualsCanonicalizing($case['required'], $state['required'], "{$set['name']} #{$i}: required");
            }
        }
    }

    public function test_every_phone_vector(): void
    {
        foreach ($this->vectors()['phone'] as $v) {
            $this->assertSame($v['e164'], PhoneCountries::e164($v['input'], $v['country']), "{$v['input']} ({$v['country']})");
        }
    }

    public function test_the_country_list_is_the_pages_list(): void
    {
        $this->assertSame($this->vectors()['countries'], PhoneCountries::DIAL);
    }

    public function test_an_unknown_operator_or_a_missing_field_is_an_error_not_a_pass(): void
    {
        $this->assertSame(['invalid_operator'], Logic::errors(['match' => 'all', 'conditions' => [['field' => 'a', 'op' => 'like', 'value' => 'x']]], ['a'], 'b'));
        $this->assertSame(['unknown_field'], Logic::errors(['match' => 'all', 'conditions' => [['field' => 'ghost', 'op' => 'empty']]], ['a'], 'b'));
        $this->assertSame(['self_reference'], Logic::errors(['match' => 'all', 'conditions' => [['field' => 'b', 'op' => 'empty']]], ['b'], 'b'));
        $this->assertSame(['invalid_match'], Logic::errors(['match' => 'maybe', 'conditions' => []], [], 'b'));
        $this->assertSame([], Logic::errors(['match' => 'any', 'conditions' => [['field' => 'a', 'op' => 'not_empty']]], ['a', 'b'], 'b'));
    }

    public function test_a_legacy_condition_becomes_a_group(): void
    {
        $this->assertSame(['match' => 'all', 'conditions' => [['field' => 'p', 'op' => 'equals', 'value' => 'x']]], Logic::fromLegacy(['field' => 'p', 'equals' => 'x']));
        $this->assertSame(['match' => 'any', 'conditions' => [['field' => 'p', 'op' => 'equals', 'value' => 'x'], ['field' => 'p', 'op' => 'equals', 'value' => 'y']]], Logic::fromLegacy(['field' => 'p', 'in' => ['x', 'y']]));
        $this->assertSame(['match' => 'all', 'conditions' => [['field' => 'p', 'op' => 'not_empty']]], Logic::fromLegacy(['field' => 'p', 'not_empty' => true]));
    }
}

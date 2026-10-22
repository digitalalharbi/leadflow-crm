<?php

namespace Tests\Unit;

use App\Domain\Sheets\ColumnMapper;
use PHPUnit\Framework\TestCase;

/**
 * Every lead sheet names its columns differently. These are the real header
 * rows of each platform's lead export, and a sheet a team built by hand.
 */
class ColumnMapperTest extends TestCase
{
    private ColumnMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ColumnMapper;
    }

    public function test_a_meta_lead_export_maps_without_help(): void
    {
        $headers = ['id', 'created_time', 'ad_id', 'ad_name', 'adset_id', 'adset_name', 'campaign_id', 'campaign_name',
            'form_id', 'form_name', 'is_organic', 'platform', 'what_type_of_unit_are_you_looking_for?', 'full_name', 'phone_number', 'email', 'city'];

        $result = $this->mapper->map($headers);

        $this->assertSame('id', $result['mapping']['lead_id']);
        $this->assertSame('phone_number', $result['mapping']['phone']);
        $this->assertSame('adset_id', $result['mapping']['adset_id']);
        $this->assertSame('ad_name', $result['mapping']['ad_name']);
        $this->assertSame('form_id', $result['mapping']['form_id']);
        $this->assertSame('campaign_name', $result['mapping']['campaign_name']);
        $this->assertSame(1.0, $result['confidence']['phone']);
        $this->assertTrue($result['usable']);

        // The form's own question is kept, not dropped.
        $this->assertArrayHasKey('what_type_of_unit_are_you_looking_for?', $result['custom']);
        $this->assertArrayHasKey('is_organic', $result['custom']);
        $this->assertSame([], $result['ambiguous']);
    }

    public function test_a_tiktok_export_maps_ad_groups_to_ad_sets(): void
    {
        $result = $this->mapper->map(['Lead ID', 'Create time', 'Campaign ID', 'Campaign name', 'Ad group ID', 'Ad group name',
            'Ad ID', 'Ad name', 'Form ID', 'Form name', 'Name', 'Phone number', 'Email', 'Service']);

        $this->assertSame('Ad group ID', $result['mapping']['adset_id']);
        $this->assertSame('Ad group name', $result['mapping']['adset_name']);
        $this->assertSame('Phone number', $result['mapping']['phone']);
        $this->assertSame('Name', $result['mapping']['full_name']);
        $this->assertSame('Lead ID', $result['mapping']['lead_id']);
        $this->assertSame(['Service'], array_keys($result['custom']));
    }

    public function test_a_snapchat_export_maps_ad_squads_and_split_names(): void
    {
        $result = $this->mapper->map(['Lead ID', 'Campaign Name', 'Ad Squad Name', 'Ad Name', 'First Name', 'Last Name', 'Phone Number', 'Email']);

        $this->assertSame('Ad Squad Name', $result['mapping']['adset_name']);
        $this->assertSame('First Name', $result['mapping']['first_name']);
        $this->assertSame('Last Name', $result['mapping']['last_name']);
        $this->assertSame('Phone Number', $result['mapping']['phone']);
    }

    public function test_an_arabic_sheet_built_by_hand_maps_whatever_the_spelling(): void
    {
        $result = $this->mapper->map(['الاسم الكامل', 'رقم الجوال', 'الإيميل', 'المدينة', 'المشروع', 'اسم الحملة', 'ملاحظات']);

        $this->assertSame('الاسم الكامل', $result['mapping']['full_name']);
        $this->assertSame('رقم الجوال', $result['mapping']['phone']);
        $this->assertSame('الإيميل', $result['mapping']['email']);
        $this->assertSame('المدينة', $result['mapping']['city']);
        $this->assertSame('المشروع', $result['mapping']['project']);
        $this->assertSame('اسم الحملة', $result['mapping']['campaign_name']);
        $this->assertSame([], $result['custom']);
    }

    public function test_a_column_whose_header_says_nothing_is_read_by_its_values(): void
    {
        $result = $this->mapper->map(
            ['الاسم', 'Column B', 'Column C'],
            [['سارة', '+966 55 123 4567', 'sara@example.com'], ['خالد', '0551234568', 'k@example.com'], ['منى', 'p:+966501112223', 'm@example.com']],
        );

        $this->assertSame('Column B', $result['mapping']['phone']);
        $this->assertSame('Column C', $result['mapping']['email']);
        $this->assertLessThan(1.0, $result['confidence']['phone']);
    }

    public function test_two_columns_that_both_look_like_the_phone_are_a_question_for_a_person(): void
    {
        $result = $this->mapper->map(['name', 'phone', 'whatsapp']);

        $this->assertSame('phone', $result['mapping']['phone']);
        $this->assertSame([['field' => 'phone', 'candidates' => ['phone', 'whatsapp']]], $result['ambiguous']);
    }

    public function test_a_persons_choice_survives_the_next_guess(): void
    {
        $result = $this->mapper->map(['name', 'phone', 'whatsapp'], [], ['phone' => 'whatsapp']);

        $this->assertSame('whatsapp', $result['mapping']['phone']);
        $this->assertSame(1.0, $result['confidence']['phone']);
        $this->assertSame([], $result['ambiguous']);
        // The column they did not choose is still kept, as a custom field.
        $this->assertArrayHasKey('phone', $result['custom']);
    }

    public function test_choosing_none_removes_a_guess(): void
    {
        $result = $this->mapper->map(['name', 'email', 'city'], [], ['city' => null]);

        $this->assertArrayNotHasKey('city', $result['mapping']);
        $this->assertArrayHasKey('city', $result['custom']);
    }

    public function test_a_sheet_with_no_way_to_reach_anybody_is_not_usable(): void
    {
        $this->assertFalse($this->mapper->map(['name', 'city', 'budget'])['usable']);
    }

    public function test_a_custom_key_is_stable_and_safe(): void
    {
        $this->assertSame(ColumnMapper::customKey('ما هي ميزانيتك؟'), ColumnMapper::customKey('ما هي ميزانيتك؟'));
        $this->assertMatchesRegularExpression('/^sheet_[a-z0-9_]+$/', ColumnMapper::customKey('ما هي ميزانيتك؟'));
        $this->assertSame('sheet_budget_range', ColumnMapper::customKey('Budget Range'));
    }
}

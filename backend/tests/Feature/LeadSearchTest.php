<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Search is the control a rep uses dozens of times a day, so both halves of it
 * matter: that it finds the right lead, and that it stays cheap as a tenant grows.
 */
class LeadSearchTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Search Realty');

        $this->asTenant($this->t['tenant'], function () {
            Lead::create([
                'full_name' => 'سعود الحربي',
                'phone' => '0512345678',
                'phone_normalized' => '+966512345678',
                'email' => 'saud@example.com',
            ]);

            Lead::create([
                'full_name' => 'نورة الشمري',
                'phone' => '0598765432',
                'phone_normalized' => '+966598765432',
                'email' => 'noura@example.com',
            ]);
        });
    }

    public function test_a_name_search_finds_the_lead(): void
    {
        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads?search='.urlencode('الحربي'));

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('سعود الحربي', $response->json('data.0.full_name'));
    }

    public function test_an_email_search_finds_the_lead(): void
    {
        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads?search=noura@example.com');

        $this->assertSame(1, $response->json('meta.total'));
    }

    /**
     * @dataProvider phoneFormats
     */
    public function test_a_phone_search_finds_the_lead_in_any_format(string $term): void
    {
        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads?search='.urlencode($term));

        $this->assertSame(1, $response->json('meta.total'), "Search for [{$term}] did not find the lead.");
        $this->assertSame('سعود الحربي', $response->json('data.0.full_name'));
    }

    public static function phoneFormats(): array
    {
        return [
            'national' => ['0512345678'],
            'e164' => ['+966512345678'],
            'spaced' => ['+966 51 234 5678'],
            'arabic digits' => ['٠٥١٢٣٤٥٦٧٨'],
            'partial' => ['2345678'],
        ];
    }

    public function test_a_numeric_search_does_not_scan_the_name_columns(): void
    {
        // Matching a phone against names is both wrong and slow: it forces a
        // sequential scan that no index can serve.
        DB::enableQueryLog();

        $this->actingAs($this->t['admin'])->getJson('/api/v1/leads?search=0512345678');

        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        $this->assertStringNotContainsString('full_name" ilike', $sql);
        $this->assertStringContainsString('phone_normalized', $sql);
    }

    public function test_search_still_respects_what_a_rep_is_allowed_to_see(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        // Not assigned to them, so searching must not surface it.
        $this->assertSame(
            0,
            $this->actingAs($rep)->getJson('/api/v1/leads?search='.urlencode('الحربي'))->json('meta.total'),
        );
    }

    public function test_a_search_never_crosses_a_tenant_boundary(): void
    {
        $other = $this->provisionTenant('Other Search Co');

        $this->asTenant($other['tenant'], fn () => Lead::create([
            'full_name' => 'سعود الحربي',
            'phone_normalized' => '+966512345678',
        ]));

        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/leads?search=0512345678');

        $this->assertSame(1, $response->json('meta.total'));
    }
}

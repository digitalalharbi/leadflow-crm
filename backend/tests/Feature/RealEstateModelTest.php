<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Property;
use App\Support\RealEstate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Real estate as a first-class model: the units a project sells, and what a lead wants. */
class RealEstateModelTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Estate Co');
        $this->project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
    }

    private function unit(array $overrides = []): array
    {
        return $overrides + ['code' => 'A-204', 'property_type' => 'apartment', 'price' => 950000, 'city' => 'الرياض', 'district' => 'النرجس', 'bedrooms' => 3, 'area_sqm' => 142.5, 'floor' => 2];
    }

    private function create(array $overrides = [], $as = null)
    {
        return $this->actingAs($as ?? $this->t['admin'])->postJson("/api/v1/projects/{$this->project->uuid}/properties", $this->unit($overrides));
    }

    // ------------------------------------------------------------ properties

    public function test_a_manager_adds_a_unit_to_a_project_and_prices_are_kept_in_minor_units(): void
    {
        $response = $this->create()->assertCreated();

        $response->assertJsonPath('data.property.code', 'A-204')
            ->assertJsonPath('data.property.price', 950000)
            ->assertJsonPath('data.property.status', 'available')
            ->assertJsonPath('data.property.type_label', 'شقة')
            ->assertJsonPath('data.property.project.id', $this->project->uuid);

        $this->assertSame(95_000_000, $this->asTenant($this->t['tenant'], fn () => Property::first()->price_minor));
    }

    public function test_a_sales_rep_can_see_units_but_not_change_them(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $this->create()->assertCreated();

        $this->create(['code' => 'A-205'], $rep)->assertForbidden();
        $this->actingAs($rep)->getJson("/api/v1/projects/{$this->project->uuid}/properties")->assertOk()->assertJsonCount(1, 'data.properties');
    }

    public function test_a_unit_code_is_unique_within_its_project_only(): void
    {
        $this->create()->assertCreated();
        $this->create()->assertStatus(422)->assertJsonValidationErrors('code');

        $other = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'الياسمين']));
        $this->actingAs($this->t['admin'])->postJson("/api/v1/projects/{$other->uuid}/properties", $this->unit())->assertCreated();
    }

    public function test_the_vocabulary_is_closed(): void
    {
        $this->create(['property_type' => 'castle'])->assertStatus(422)->assertJsonValidationErrors('property_type');
        $this->create(['status' => 'haunted'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->create(['price' => -5])->assertStatus(422)->assertJsonValidationErrors('price');
    }

    public function test_the_workspace_list_filters_by_what_a_rep_asks_for(): void
    {
        $this->create(['code' => 'V-1', 'property_type' => 'villa', 'price' => 2_400_000, 'district' => 'الياسمين', 'bedrooms' => 5])->assertCreated();
        $this->create(['code' => 'A-1', 'price' => 700_000, 'bedrooms' => 2])->assertCreated();
        $sold = $this->create(['code' => 'A-2', 'price' => 800_000])->json('data.property.id');
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/properties/{$sold}", ['status' => 'sold'])->assertOk();

        $list = fn (array $q) => collect($this->actingAs($this->t['admin'])->getJson('/api/v1/properties?'.http_build_query($q))->assertOk()->json('data'))->pluck('code')->sort()->values()->all();

        $this->assertSame(['A-1', 'A-2', 'V-1'], $list([]));
        $this->assertSame(['V-1'], $list(['property_type' => 'villa']));
        $this->assertSame(['A-1', 'A-2'], $list(['property_type' => 'apartment']));
        $this->assertSame(['A-1', 'A-2'], $list(['price_max' => 800000, 'property_type' => 'apartment']));
        $this->assertSame(['A-1', 'V-1'], $list(['status' => 'available']));
        $this->assertSame(['A-1'], $list(['price_max' => 750000]));
        $this->assertSame(['A-2'], $list(['status' => 'sold']));
        $this->assertSame(['V-1'], $list(['district' => 'الياسمين']));
        $this->assertSame(['A-1'], $list(['bedrooms' => 2]));
        $this->assertSame(['V-1'], $list(['q' => 'v-1']));
    }

    public function test_a_project_reports_how_many_units_are_in_each_state(): void
    {
        $this->create(['code' => 'A-1'])->assertCreated();
        $second = $this->create(['code' => 'A-2'])->json('data.property.id');
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/properties/{$second}", ['status' => 'reserved'])->assertOk();

        $summary = $this->actingAs($this->t['admin'])->getJson("/api/v1/projects/{$this->project->uuid}/properties")->json('data.summary');

        $this->assertSame(['available' => 1, 'reserved' => 1, 'sold' => 0, 'rented' => 0, 'hidden' => 0], $summary);
    }

    public function test_changes_are_audited_with_before_and_after(): void
    {
        $id = $this->create()->json('data.property.id');
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/properties/{$id}", ['status' => 'reserved', 'price' => 900000])->assertOk();
        $this->actingAs($this->t['admin'])->deleteJson("/api/v1/properties/{$id}")->assertOk();

        $log = $this->asTenant($this->t['tenant'], fn () => AuditLog::where('action', 'property.updated')->sole());

        $this->assertSame('available', $log->changes['before']['status']);
        $this->assertSame('reserved', $log->changes['after']['status']);
        $this->assertSame(90_000_000, $log->changes['after']['price_minor']);
        $this->assertSame(3, $this->asTenant($this->t['tenant'], fn () => AuditLog::where('action', 'like', 'property.%')->count()));
    }

    public function test_another_workspace_cannot_see_or_touch_a_unit(): void
    {
        $id = $this->create()->json('data.property.id');
        $other = $this->provisionTenant('Rival Co');

        $this->actingAs($other['admin'])->patchJson("/api/v1/properties/{$id}", ['status' => 'sold'])->assertNotFound();
        $this->actingAs($other['admin'])->getJson('/api/v1/properties')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other['admin'])->postJson("/api/v1/projects/{$this->project->uuid}/properties", $this->unit(['code' => 'X']))->assertNotFound();
    }

    // ------------------------------------------------------------ what a lead wants

    private function facts(): array
    {
        return [
            'transaction_type' => 'buy', 'preferred_district' => 'النرجس', 'bedrooms' => 3, 'area_sqm' => 150,
            'nationality' => 'سعودي', 'preferred_channel' => 'whatsapp', 'reservation_intent' => true,
        ];
    }

    public function test_a_lead_is_created_with_its_real_estate_facts(): void
    {
        $response = $this->actingAs($this->t['admin'])->postJson('/api/v1/leads', ['full_name' => 'سلمان', 'phone' => '0551234567'] + $this->facts())->assertCreated();

        $response->assertJsonPath('data.lead.transaction_type', 'buy')
            ->assertJsonPath('data.lead.preferred_district', 'النرجس')
            ->assertJsonPath('data.lead.bedrooms', 3)
            ->assertJsonPath('data.lead.area_sqm', 150)
            ->assertJsonPath('data.lead.preferred_channel', 'whatsapp')
            ->assertJsonPath('data.lead.reservation_intent', true);
    }

    public function test_the_qualification_endpoint_stores_the_new_facts_and_refuses_bad_ones(): void
    {
        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::create(['full_name' => 'عميل', 'phone' => '0551234567', 'phone_normalized' => '+966551234567']));

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/leads/{$lead->uuid}/qualification", $this->facts())->assertOk()
            ->assertJsonPath('data.lead.transaction_type', 'buy')
            ->assertJsonPath('data.lead.nationality', 'سعودي');

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/leads/{$lead->uuid}/qualification", ['transaction_type' => 'swap'])->assertStatus(422);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/leads/{$lead->uuid}/qualification", ['preferred_channel' => 'pigeon'])->assertStatus(422);
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/leads/{$lead->uuid}/qualification", ['bedrooms' => -1])->assertStatus(422);
    }

    public function test_a_lead_can_point_at_the_unit_it_is_interested_in_but_not_another_workspaces(): void
    {
        $lead = $this->asTenant($this->t['tenant'], fn () => Lead::create(['full_name' => 'عميل', 'phone' => '0551234567', 'phone_normalized' => '+966551234567']));
        $unit = $this->create()->json('data.property.id');

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/leads/{$lead->uuid}", ['interested_property_id' => $unit])->assertOk()
            ->assertJsonPath('data.lead.interested_property_id', $unit);

        $other = $this->provisionTenant('Rival Co');
        $otherProject = $this->asTenant($other['tenant'], fn () => Project::create(['name' => 'س']));
        $foreign = $this->actingAs($other['admin'])->postJson("/api/v1/projects/{$otherProject->uuid}/properties", $this->unit())->json('data.property.id');

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/leads/{$lead->uuid}", ['interested_property_id' => $foreign])->assertNotFound();
    }

    public function test_what_a_customer_calls_a_property_maps_to_its_type(): void
    {
        $this->assertSame('villa', RealEstate::typeFromWords('ابغى فيلا'));
        $this->assertSame('apartment', RealEstate::typeFromWords('شقة ٣ غرف'));
        $this->assertSame('townhouse', RealEstate::typeFromWords('تاون هاوس'));
        $this->assertSame('land', RealEstate::typeFromWords('أرض'));
        $this->assertNull(RealEstate::typeFromWords('أي شي'));
        $this->assertNull(RealEstate::typeFromWords(null));
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Automation\ConditionEvaluator;
use App\Domain\Leads\LeadTags;
use App\Enums\Role;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tag is one thing whoever writes it — a rep's chip, a rule, the filter — and it stays inside its own company.
 */
class LeadTagsTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->provisionTenant('Alpha');
        $this->b = $this->provisionTenant('Beta');
    }

    private function lead(array $t, array $attributes = []): Lead
    {
        $tags = $attributes['tags'] ?? [];
        unset($attributes['tags']);

        return $this->asTenant($t['tenant'], function () use ($attributes, $tags) {
            $lead = Lead::create($attributes + ['full_name' => 'Lead '.str()->random(4), 'phone' => '05'.random_int(10000000, 99999999)]);
            $lead->forceFill(['tags' => $tags])->save();

            return $lead;
        });
    }

    public function test_tags_are_cleaned_deduplicated_without_case_and_capped(): void
    {
        $this->assertSame(['VIP', 'مستثمر'], LeadTags::normalise(['  VIP ', 'vip', '<b>مستثمر</b>', '', null, ['x']]));
        $this->assertCount(LeadTags::MAX_PER_LEAD, LeadTags::normalise(array_map(fn ($i) => "t{$i}", range(1, 15))));
        $this->assertSame(LeadTags::MAX_LENGTH, mb_strlen(LeadTags::clean(str_repeat('ب', 50))));
    }

    public function test_a_rep_sets_tags_and_the_history_says_what_changed(): void
    {
        $lead = $this->lead($this->a);

        $this->actingAs($this->a['admin'])->putJson("/api/v1/leads/{$lead->uuid}/tags", ['tags' => ['VIP', 'مستثمر']])
            ->assertOk()->assertJsonPath('data.lead.tags', ['VIP', 'مستثمر']);

        $this->actingAs($this->a['admin'])->putJson("/api/v1/leads/{$lead->uuid}/tags", ['tags' => ['vip']])
            ->assertOk()->assertJsonPath('data.lead.tags', ['vip']);

        $last = $this->asTenant($this->a['tenant'], fn () => LeadActivity::where('lead_id', $lead->id)->latest('id')->first());
        $this->assertSame(['added' => [], 'removed' => ['مستثمر']], $last->meta['tags']);
    }

    public function test_the_request_is_validated(): void
    {
        $lead = $this->lead($this->a);

        $this->actingAs($this->a['admin'])->putJson("/api/v1/leads/{$lead->uuid}/tags", ['tags' => array_fill(0, 11, 'x')])->assertUnprocessable();
        $this->actingAs($this->a['admin'])->putJson("/api/v1/leads/{$lead->uuid}/tags", ['tags' => [str_repeat('x', 40)]])->assertUnprocessable();
        $this->actingAs($this->a['admin'])->putJson("/api/v1/leads/{$lead->uuid}/tags", [])->assertUnprocessable();
    }

    public function test_another_company_cannot_tag_or_see_the_lead(): void
    {
        $lead = $this->lead($this->a, ['tags' => ['سري']]);
        $this->lead($this->b, ['tags' => ['Beta-only']]);

        $this->actingAs($this->b['admin'])->putJson("/api/v1/leads/{$lead->uuid}/tags", ['tags' => ['x']])->assertNotFound();

        $tags = collect($this->actingAs($this->b['admin'])->getJson('/api/v1/leads/tags')->assertOk()->json('data.tags'))->pluck('tag')->all();
        $this->assertSame(['Beta-only'], $tags);
    }

    public function test_a_rep_who_cannot_see_a_lead_cannot_tag_it(): void
    {
        $rep = $this->makeUser($this->a['tenant'], Role::Sales);
        $other = $this->makeUser($this->a['tenant'], Role::Sales);
        $lead = $this->lead($this->a, ['assigned_to' => $other->id]);

        $this->actingAs($rep)->putJson("/api/v1/leads/{$lead->uuid}/tags", ['tags' => ['x']])->assertForbidden();
    }

    public function test_the_list_filters_by_tag_without_case_and_suggests_the_tags_in_use(): void
    {
        $vip = $this->lead($this->a, ['tags' => ['VIP']]);
        $this->lead($this->a, ['tags' => ['investor', 'VIP']]);
        $plain = $this->lead($this->a);

        $ids = collect($this->actingAs($this->a['admin'])->getJson('/api/v1/leads?tag=vip')->assertOk()->json('data.leads') ?? $this->actingAs($this->a['admin'])->getJson('/api/v1/leads?tag=vip')->json('data'))
            ->pluck('id')->all();
        $this->assertContains($vip->uuid, $ids);
        $this->assertNotContains($plain->uuid, $ids);
        $this->assertCount(2, $ids);

        $this->assertSame(
            [['tag' => 'VIP', 'count' => 2], ['tag' => 'investor', 'count' => 1]],
            $this->actingAs($this->a['admin'])->getJson('/api/v1/leads/tags')->json('data.tags'),
        );
    }

    public function test_a_rule_adds_the_tag_to_the_lead_and_another_rule_can_read_it(): void
    {
        $this->asTenant($this->a['tenant'], fn () => AutomationRule::create([
            'name' => 'وسم الوارد', 'trigger' => 'lead.created', 'is_enabled' => true, 'conditions' => [],
            'actions' => [['type' => 'add_tag', 'tag' => 'جديد']],
        ]));

        $this->actingAs($this->a['admin'])->postJson('/api/v1/leads', ['full_name' => 'Tagged', 'phone' => '0551112222'])->assertSuccessful();
        $lead = $this->asTenant($this->a['tenant'], fn () => Lead::where('full_name', 'Tagged')->first());

        $this->assertSame(['جديد'], $lead->tags);

        $evaluator = app(ConditionEvaluator::class);
        $this->assertTrue($evaluator->evaluate($lead, [['field' => 'tags', 'operator' => 'contains', 'value' => 'جديد']])['matched']);
        $this->assertFalse($evaluator->evaluate($lead, [['field' => 'tags', 'operator' => 'contains', 'value' => 'جدي']])['matched']);
        $this->assertTrue($evaluator->evaluate($lead, [['field' => 'tags', 'operator' => 'exists']])['matched']);
        $this->assertTrue($evaluator->evaluate($this->lead($this->a), [['field' => 'tags', 'operator' => 'not_exists']])['matched']);
    }
}
